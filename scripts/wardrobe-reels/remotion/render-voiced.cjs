#!/usr/bin/env node
// Озвученный рилс гардероба на Remotion (пилот 2026-10-09).
// Вход: спека (specs/*.json) + готовый дубль Qwen (WAV + JSON-метаданные из qwen.cjs prepareVoice).
// Шаги: whisper.cpp пословно → выравнивание со сценарием → props → Remotion (кадры + плашка-караоке, без звука)
// → ffmpeg: голос + подложка на bedUnderVoiceDb ниже голоса, длина задана явно (без -shortest)
// → контакт-лист + кадры сверки синхрона → manifest.review.json (НЕ manifest.json: в очередь не попадает)
// → qa-transcript.cjs по готовому mp4 (код 1 = брак).
//
// node scripts/wardrobe-reels/remotion/render-voiced.cjs <spec.json> --wav take.wav --voice-json take.json [--root <checkout>] [--take D]
// --root: где лежат ассеты и куда писать результат (по умолчанию — корень этого репо).
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');

const here = __dirname;
const repo = path.resolve(here, '../../..');
const MODEL = process.env.WHISPER_MODEL || path.join(os.homedir(), 'tg-bots/agent-router/models/ggml-large-v3-turbo.bin');
const NODE_MODULES = process.env.REMOTION_NODE_MODULES || path.join(os.homedir(), 'tools/reels-motion/remotion/node_modules');
const FPS = 30;

function run(cmd, args, opts = {}) {
    const r = spawnSync(cmd, args, {encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, ...opts});
    if (r.status !== 0) throw new Error(`${cmd} ${args.slice(0, 3).join(' ')}…: ${(r.stderr || r.error?.message || '').slice(-2000)}`);
    return r;
}
const norm = s => String(s).toLowerCase().replace(/ё/g, 'е').replace(/[^\p{L}\p{N}]+/gu, ' ').trim().split(' ').filter(Boolean);
const spoken = s => require('../qwen.cjs').spokenText(s);

// Слова сценария для показа: тире и прочая пунктуация без звука приклеиваются к предыдущему слову.
function displayWords(text) {
    const out = [];
    for (const raw of text.split(/\s+/).filter(Boolean)) {
        if (!norm(raw).length && out.length) out[out.length - 1].text += ' ' + raw;
        else out.push({text: raw, tokens: norm(spoken(raw))});
    }
    return out;
}

// whisper.cpp: -ml 1 даёт по сегменту на слово; пунктуация может уйти в отдельный сегмент — у неё нет токенов.
function whisperWords(wav) {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'reel-words-'));
    const base = path.join(dir, 'w');
    run('whisper-cli', ['-m', MODEL, '-f', wav, '-l', 'ru', '-ml', '1', '-sow', '-oj', '-np', '-of', base]);
    const json = JSON.parse(fs.readFileSync(base + '.json', 'utf8'));
    const tokens = [];
    for (const seg of json.transcription) for (const tok of norm(seg.text)) tokens.push({tok, start: seg.offsets.from / 1000, end: seg.offsets.to / 1000});
    return tokens;
}

// LCS сценарий ↔ услышанное; слово сценария без пары (бренд, «тёте» → «тетя») интерполируется между соседями.
function align(words, heard) {
    const a = words.flatMap((w, wi) => w.tokens.map(tok => ({tok, wi})));
    const b = heard;
    const dp = Array.from({length: a.length + 1}, () => new Array(b.length + 1).fill(0));
    for (let i = a.length - 1; i >= 0; i--) for (let j = b.length - 1; j >= 0; j--) {
        dp[i][j] = a[i].tok === b[j].tok ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    }
    for (let i = 0, j = 0; i < a.length && j < b.length;) {
        if (a[i].tok === b[j].tok) {
            const w = words[a[i].wi];
            w.start = Math.min(w.start ?? Infinity, b[j].start);
            w.end = Math.max(w.end ?? -Infinity, b[j].end);
            i++; j++;
        } else if (dp[i + 1][j] >= dp[i][j + 1]) i++; else j++;
    }
    const matched = words.filter(w => w.start !== undefined).length;
    for (let i = 0; i < words.length; i++) {
        if (words[i].start !== undefined) continue;
        let k = i;
        while (k < words.length && words[k].start === undefined) k++;
        const left = i ? words[i - 1].end : 0, right = k < words.length ? words[k].start : left + 0.4 * (k - i);
        const step = (right - left) / (k - i);
        for (let m = i; m < k; m++) { words[m].start = left + step * (m - i); words[m].end = left + step * (m - i + 1); words[m].interpolated = true; }
    }
    return matched / words.length;
}

function timeline(spec, scenes, total0) {
    const off = spec.voiceOffset || 0;
    const beats = [];
    scenes.forEach((sc, k) => {
        const ws = sc.words.map(w => ({text: w.text, start: +(w.start + off).toFixed(3), end: +(w.end + off).toFixed(3)}));
        const prevEnd = k ? scenes[k - 1].words.at(-1).end + off : 0;
        const from = k ? Math.max(prevEnd, ws[0].start - 0.12) : 0;
        const plateWords = sc.buttonFromWord !== undefined ? ws.slice(0, sc.buttonFromWord) : ws;
        const cuts = [0, ...(sc.splitAtWord || []), plateWords.length];
        const chunks = cuts.slice(0, -1).map((c, i) => ({from: i ? plateWords[c].start - 0.1 : from, words: plateWords.slice(c, cuts[i + 1])}));
        const beat = {from: +from.toFixed(3), to: 0, kind: sc.kind || 'photo', image: sc.publicImage, zoom: sc.zoom, chunks, band: sc.band};
        if (sc.push) beat.push = {at: ws[sc.push.atWord].start - 0.1, scale: sc.push.scale, cx: sc.push.cx, cy: sc.push.cy};
        if (sc.mark) beat.mark = {at: ws[sc.mark.atWord].start - 0.05, x: sc.mark.x, y: sc.mark.y, w: sc.mark.w, h: sc.mark.h};
        if (sc.buttonFromWord !== undefined) beat.button = {text: ws.slice(sc.buttonFromWord).map(w => w.text).join(' ').replace(/[.!]$/, ''), at: ws[sc.buttonFromWord].start};
        beats.push(beat);
    });
    beats.forEach((b, i) => { b.to = i + 1 < beats.length ? beats[i + 1].from : +(scenes.at(-1).words.at(-1).end + off + spec.ctaHold).toFixed(3); });
    const loopFrom = beats.at(-1).to;
    const loopWords = spec.loop.text.split(/\s+/).map(text => ({text, start: 999, end: 999}));
    beats.push({from: loopFrom, to: +(loopFrom + spec.loop.seconds).toFixed(3), kind: 'loop', image: spec.loop.publicImage, zoom: [1, 1], chunks: [{from: loopFrom, words: loopWords}]});
    return {total: +(loopFrom + spec.loop.seconds).toFixed(3), band: spec.band, beats};
}

function meanDb(file, extra = []) {
    const r = spawnSync('ffmpeg', ['-hide_banner', ...extra, '-i', file, '-af', 'volumedetect', '-f', 'null', '-'], {encoding: 'utf8'});
    const m = /mean_volume: (-?[\d.]+) dB/.exec(r.stderr);
    if (!m) throw new Error(`volumedetect failed: ${file}`);
    return +m[1];
}

function main(argv) {
    const opt = n => { const i = argv.indexOf(n); return i === -1 ? undefined : argv[i + 1]; };
    const specPath = argv[0];
    const wav = opt('--wav'), voiceJson = opt('--voice-json');
    if (!specPath || !wav || !voiceJson) throw new Error('usage: render-voiced.cjs <spec.json> --wav take.wav --voice-json take.json [--root dir] [--take name]');
    const root = path.resolve(opt('--root') || repo);
    const spec = JSON.parse(fs.readFileSync(specPath, 'utf8'));
    const meta = JSON.parse(fs.readFileSync(voiceJson, 'utf8'));
    const spokenScenes = spec.scenes.map(s => spoken(s.voice));
    if (JSON.stringify(meta.scenes.map(s => s.text)) !== JSON.stringify(spokenScenes)) throw new Error('Дубль озвучен по другому тексту, чем в спеке');

    const out = path.join(root, spec.out);
    const work = fs.mkdtempSync(path.join(os.tmpdir(), 'reel-remotion-'));
    const pub = path.join(work, 'public');
    fs.mkdirSync(pub, {recursive: true});
    fs.mkdirSync(out, {recursive: true});
    fs.copyFileSync(path.join(repo, 'config/social/fonts/NotoSans.ttf'), path.join(pub, 'NotoSans.ttf'));
    const publicImage = ref => {
        const [file, at] = ref.split('@');
        const src = path.join(root, spec.assets, file);
        const name = file.replace(/\W/g, '_') + (at ? `_${at.replace('.', '_')}` : '') + '.jpg';
        const dst = path.join(pub, name);
        if (!fs.existsSync(dst)) {
            if (at) run('ffmpeg', ['-v', 'error', '-y', '-ss', at, '-i', src, '-frames:v', '1', '-q:v', '1', dst]);
            else fs.copyFileSync(src, dst);
        }
        return name;
    };
    spec.loop.publicImage = publicImage(spec.loop.image);

    // 1. Слова и тайминги.
    const t0 = Date.now();
    const heard = whisperWords(wav);
    const scenes = spec.scenes.map(s => ({...s, words: displayWords(s.voice), publicImage: publicImage(s.image)}));
    const all = scenes.flatMap(s => s.words);
    const coverage = align(all, heard);
    const props = timeline(spec, scenes);
    fs.writeFileSync(path.join(work, 'props.json'), JSON.stringify(props, null, 1));
    const tWhisper = (Date.now() - t0) / 1000;

    // 2. Remotion: видеоряд без звука.
    if (!fs.existsSync(path.join(here, 'node_modules'))) fs.symlinkSync(NODE_MODULES, path.join(here, 'node_modules'));
    const silent = path.join(work, 'video.mp4');
    const t1 = Date.now();
    run(process.execPath, [path.join(here, 'node_modules/@remotion/cli/remotion-cli.js'), 'render', 'src/index.ts', 'VoicedReel', silent,
        `--props=${path.join(work, 'props.json')}`, `--public-dir=${pub}`, '--codec=h264', '--crf=18', '--muted', '--log=error'], {cwd: here});
    const tRender = (Date.now() - t1) / 1000;

    // 3. Звук: голос со сдвигом voiceOffset, подложка на bedUnderVoiceDb ниже средней громкости голоса.
    const total = props.total;
    const voiceDb = meanDb(wav);
    const bed = path.join(repo, spec.bed);
    const bedDb = meanDb(bed, ['-t', String(total)]);
    const bedGain = +(voiceDb - spec.bedUnderVoiceDb - bedDb).toFixed(2);
    const delay = Math.round((spec.voiceOffset || 0) * 1000);
    const final = path.join(out, `${spec.id}.mp4`);
    const t2 = Date.now();
    run('ffmpeg', ['-v', 'error', '-y', '-i', silent, '-i', wav, '-stream_loop', '-1', '-i', bed,
        '-filter_complex',
        `[1:a]aresample=48000,aformat=channel_layouts=stereo,adelay=${delay}|${delay},apad[v];` +
        `[2:a]aresample=48000,aformat=channel_layouts=stereo,volume=${bedGain}dB,afade=t=in:d=0.3,afade=t=out:st=${(total - 0.4).toFixed(2)}:d=0.4[b];` +
        `[v][b]amix=inputs=2:duration=longest:normalize=0,atrim=0:${total}[a]`,
        '-map', '0:v', '-map', '[a]', '-t', String(total), '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-movflags', '+faststart', final]);
    const tMux = (Date.now() - t2) / 1000;

    // 4. Контакт-лист, кадр обложки и кадры сверки синхрона (середина выбранных слов).
    run('ffmpeg', ['-v', 'error', '-y', '-i', final, '-vf', 'fps=2,scale=270:-1,tile=8x5', '-frames:v', '1', path.join(out, 'contact.png')]);
    run('ffmpeg', ['-v', 'error', '-y', '-ss', '0', '-i', final, '-frames:v', '1', '-q:v', '2', path.join(out, 'cover.jpg')]);
    const probes = (spec.syncProbe || []).map(i => all[i]).filter(Boolean);
    const probeFiles = probes.map((w, i) => {
        const at = w.start + (spec.voiceOffset || 0) + (w.end - w.start) / 2;
        const f = path.join(work, `sync-${i}.png`);
        run('ffmpeg', ['-v', 'error', '-y', '-ss', at.toFixed(3), '-i', final, '-frames:v', '1', '-vf', 'scale=360:-1', f]);
        return {file: f, word: w.text, at: +at.toFixed(2)};
    });
    if (probeFiles.length) {
        run('ffmpeg', ['-v', 'error', '-y', ...probeFiles.flatMap(p => ['-i', p.file]), '-filter_complex',
            probeFiles.map((_, i) => `[${i}]`).join('') + `hstack=${probeFiles.length}`, path.join(out, 'sync.png')]);
    }

    // 5. Манифест на ревью (не manifest.json — его глобят очередь и daily).
    const manifest = {
        version: 2, campaign: 'wardrobe-voiced-remotion-pilot', id: spec.id, series: spec.series, status: 'review',
        renderer: 'remotion', hook: spec.scenes[0].voice,
        video: final, cover: path.join(out, 'cover.jpg'), duration_ms: Math.round(total * 1000),
        caption: spec.caption, cta_url: spec.cta_url, cta_label: spec.cta_label, ai_generated: true, synthetic_voice: true,
        beats: props.beats.map(b => ({kind: b.kind, start: b.from, end: b.to, image: b.image, plate: b.chunks.map(c => c.words.map(w => w.text).join(' ')), button: b.button?.text})),
        voice: {
            engine: meta.engine, take: opt('--take') || null, seed: meta.seed, duration: meta.duration, asr_similarity: meta.asr_similarity,
            offset: spec.voiceOffset || 0, scenes: meta.scenes,
            words: all.map(w => ({text: w.text, start: +w.start.toFixed(3), end: +w.end.toFixed(3), interpolated: !!w.interpolated})),
            whisper_coverage: +coverage.toFixed(3), listening_review: 'pending',
        },
        audio: {bed: path.basename(bed), voice_mean_db: voiceDb, bed_mean_db_raw: bedDb, bed_gain_db: bedGain, bed_under_voice_db: spec.bedUnderVoiceDb},
        timings_s: {whisper: tWhisper, remotion: tRender, mux: tMux},
        sync_probes: probeFiles.map(({word, at}) => ({word, at})),
    };
    const manifestPath = path.join(out, 'manifest.review.json');
    fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
    console.log(JSON.stringify({final, manifestPath, total, coverage: manifest.voice.whisper_coverage, audio: manifest.audio, timings_s: manifest.timings_s, sync: manifest.sync_probes}, null, 1));

    // 6. QA-гейт по готовому mp4.
    const qa = spawnSync(process.execPath, [path.join(here, '../qa-transcript.cjs'), final, '--manifest', manifestPath], {encoding: 'utf8', stdio: 'inherit'});
    return qa.status ?? 2;
}

if (require.main === module) {
    try { process.exitCode = main(process.argv.slice(2)); } catch (e) { console.error(e.message); process.exitCode = 2; }
}
module.exports = {displayWords, align, timeline};
