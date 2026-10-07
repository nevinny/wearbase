#!/usr/bin/env node
// Шаблоны рилсов: фото на весь кадр 1080×1920 + плашка (Playwright canvas -> ffmpeg).
// Каталог — config/social/wardrobe_reels_templates.json, ассеты — var/wardrobe-reels/assets/<slug>/NN.(jpg|png|mp4).
// Нет ассета/переменной/статус не ready -> ролик помечается draft и не годится для очереди публикации.
// Публикует отдельная команда app:social:enqueue-wardrobe-reels; здесь ничего никуда не уходит. Озвучки нет: тихая подложка.
const fs = require('node:fs');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const {createHash} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {once} = require('node:events');
const lib = require('./templates-lib.cjs');

const args = process.argv.slice(2);
function option(name, fallback) {
    const i = args.indexOf(name);
    if (i === -1) return fallback;
    if (!args[i + 1] || args[i + 1].startsWith('--')) throw new Error(`Missing value for ${name}`);
    return args[i + 1];
}
function options(name) {
    const out = [];
    args.forEach((a, i) => { if (a === name) { if (!args[i + 1] || args[i + 1].startsWith('--')) throw new Error(`Missing value for ${name}`); out.push(args[i + 1]); } });
    return out;
}
function run(command, parameters) {
    const result = spawnSync(command, parameters, {encoding: 'utf8', maxBuffer: 4 * 1024 * 1024});
    if (result.status !== 0) throw new Error(`${command}: ${result.error?.message || result.stderr}`);
    return result.stdout;
}
const duration = file => Number(run('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', file]).trim());
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
const TYPE_SHORT = {photo: 'ФОТО', chat: 'СКРИН ЧАТА', screen: 'ЗАПИСЬ ЭКРАНА PWA'};

// Лёгкий Ken Burns только для фото; экран/чат стоят (интерфейс не должен «плыть»).
function motion(photo, index, dur) {
    if (!photo) return {z0: 1, z1: 1, x0: 0, x1: 0, y0: 0, y1: 0};
    const grow = Math.min(.1, .025 * dur), sx = index % 2 ? -1 : 1, sy = index % 3 ? 1 : -1;
    return {z0: 1, z1: 1 + grow, x0: 0, x1: sx * 28, y0: 0, y1: sy * 18};
}
const reverse = m => ({z0: m.z1, z1: m.z0, x0: m.x1, x1: m.x0, y0: m.y1, y1: m.y0});

function buildTimeline(template, ev) {
    const shots = [], plates = [], silences = [];
    const byBeat = new Map();
    template.beats.forEach((beat, bi) => {
        const n = beat.shots || 1, step = (beat.end - beat.start) / n;
        const list = [];
        for (let k = 1; k <= n; k++) {
            const from = beat.start + step * (k - 1), to = beat.start + step * k;
            let shot;
            if (beat.frame.fill) shot = {kind: 'dark'};
            else if (beat.frame.type === 'loop') {
                // Луп: тот же кадр, что и первый; движение в обратную сторону — последний кадр = первому кадру (шов без скачка).
                const first = byBeat.get(beat.frame.loopOf)[0];
                shot = {...first, zoom: reverse(first.zoom)};
            } else if (beat.frame.sameAs) {
                const prev = byBeat.get(beat.n - 1).at(-1);
                const m = prev.zoom;
                shot = {...prev, zoom: {z0: m.z1, z1: Math.min(1.14, m.z1 + Math.min(.06, .02 * (to - from))), x0: m.x1, x1: m.x1 * 1.4, y0: m.y1, y1: m.y1 * 1.4}};
            } else {
                const key = lib.shotKey(beat, k), file = ev.assets.found[key];
                const photo = beat.frame.type === 'photo';
                if (file) shot = {kind: 'media', mediaKind: photo ? 'photo' : 'screen', src: pathToFileURL(file).href, zoom: motion(photo, bi, to - from), ai: ev.aiBadge && ev.aiAssets.includes(key)};
                else shot = {kind: 'placeholder', label: `КАДР ${key} · ${TYPE_SHORT[beat.frame.type]}`, desc: beat.frame.desc, zoom: motion(false, 0, 0)};
            }
            list.push({...shot, from, to, beat: beat.n});
        }
        byBeat.set(beat.n, list);
        shots.push(...list);
        // Плашки бита идут по кругу равными окнами (тикер — beat.ticker раз, список — по числу элементов).
        const texts = beat.plates ? (beat.ticker ? Array.from({length: beat.ticker}, (_, i) => beat.plates[i % beat.plates.length]) : beat.plates) : [beat.plate];
        const w = (beat.end - beat.start) / texts.length;
        texts.forEach((text, i) => plates.push({from: beat.start + w * i, to: beat.start + w * (i + 1), text: lib.substitute(text, ev.vars.values, i)}));
        if (beat.silenceAfter) silences.push([beat.end, beat.end + beat.silenceAfter]);
    });
    const total = template.beats.at(-1).end;
    return {shots, plates, silences, total};
}

function audioFilter(total, silences) {
    // Подложка тихая, +4 dB на последние 3 с (без fade-out — ролик зацикливается), 0.5 с тишины на `silenceAfter`.
    const parts = ['volume=0.30', `volume=eval=frame:volume='1+0.585*clip((t-${(total - 3).toFixed(3)})/3,0,1)'`];
    for (const [a, b] of silences) parts.push(`volume=enable='between(t,${a.toFixed(3)},${b.toFixed(3)})':volume=0`);
    return parts.join(',');
}
function pickBed(template) {
    const dir = path.join(lib.root, 'config/social/audio');
    const tracks = fs.existsSync(dir) ? fs.readdirSync(dir).filter(f => /\.(m4a|mp3)$/i.test(f)).sort() : [];
    return tracks.length ? path.join(dir, tracks[template.id % tracks.length]) : null;
}

async function renderOne(page, catalog, template, ev, outBase, fps) {
    const slugId = `t${template.slug}-v1`;
    const work = path.join(outBase, slugId);
    fs.mkdirSync(work, {recursive: true});
    const output = path.join(work, `${slugId}.mp4`), manifestPath = path.join(work, 'manifest.json');
    const timeline = buildTimeline(template, ev);
    const bed = pickBed(template);
    const fingerprint = sha(JSON.stringify({template, values: ev.vars.values, draft: ev.draft, fps, ai: ev.aiAssets, aiBadge: ev.aiBadge})
        + fs.readFileSync(__filename) + fs.readFileSync(path.join(__dirname, 'scene-template.html'))
        + Object.entries(ev.assets.found).map(([k, f]) => k + sha(fs.readFileSync(f))).join())
        ;
    if (fs.existsSync(output) && fs.existsSync(manifestPath)) {
        const existing = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
        if (existing.fingerprint === fingerprint && duration(output) > 0) { console.log(`Cached ${slugId}`); return existing; }
    }
    await page.goto(pathToFileURL(path.join(__dirname, 'scene-template.html')).href);
    const metrics = await page.evaluate(d => window.setup(d), {shots: timeline.shots, plates: timeline.plates, total: timeline.total, draft: ev.draft});
    const warnings = [];
    metrics.forEach(m => {
        if (!m.text) return;
        if (m.size < 76) warnings.push(`плашка «${m.text.replace(/\n/g, ' / ')}»: кегль ${m.size} < 76`);
        if (m.top < 300 || m.bottom > 1500) warnings.push(`плашка «${m.text.replace(/\n/g, ' / ')}» вне безопасной зоны (${m.top}–${m.bottom})`);
    });
    warnings.forEach(w => console.warn('! ' + w));
    // Прогрев: на холодном старте Chromium первые скриншоты иногда уходят белыми (видели 0.5 с белого в начале ролика).
    for (let i = 0; i < 4; i++) {
        await page.evaluate(() => window.render(0));
        await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
        await page.screenshot({type: 'jpeg', quality: 50});
    }
    await page.evaluate(() => window.render(.3));
    const cover = path.join(work, 'cover.jpg');
    await page.screenshot({path: cover, type: 'jpeg', quality: 94});
    const silent = path.join(work, 'silent.mp4');
    const encoder = spawn('ffmpeg', ['-v', 'error', '-y', '-f', 'image2pipe', '-vcodec', 'mjpeg', '-r', String(fps), '-i', 'pipe:0', '-an', '-vf', 'scale=in_range=full:out_range=limited,format=yuv420p', '-color_range', 'tv', '-c:v', 'libx264', '-r', String(fps), '-g', String(fps * 2), '-preset', 'fast', '-crf', '19', '-pix_fmt', 'yuv420p', silent]);
    let errors = '';
    encoder.stderr.on('data', chunk => errors += chunk);
    const closed = once(encoder, 'close');
    const frames = Math.round(timeline.total * fps);
    try {
        for (let f = 0; f < frames; f++) {
            await page.evaluate(t => window.render(t), f / fps);
            const jpeg = await page.screenshot({type: 'jpeg', quality: 90});
            if (!encoder.stdin.write(jpeg)) await once(encoder.stdin, 'drain');
        }
        encoder.stdin.end();
        const [code] = await closed;
        if (code !== 0) throw new Error(errors);
    } catch (error) { encoder.kill(); throw error; }
    const mux = ['-v', 'error', '-y', '-i', silent];
    if (bed) mux.push('-stream_loop', '-1', '-i', bed); else mux.push('-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo');
    mux.push('-map', '0:v:0', '-map', '1:a:0', '-af', audioFilter(timeline.total, timeline.silences), '-t', timeline.total.toFixed(3),
        '-c:v', 'copy', '-c:a', 'aac', '-b:a', '128k', '-shortest', '-movflags', '+faststart', '-use_editlist', '0', output);
    run('ffmpeg', mux);
    fs.unlinkSync(silent);
    // Пометка о генерации — в подписи, не только флагом: зритель должен видеть её без захода в метаданные.
    const aiNote = ev.aiAssets.length ? '\n\nЧасть кадров — AI-иллюстрации.' : '';
    const caption = lib.substitute(template.caption, ev.vars.values) + aiNote + '\n\nГардероб — ссылка в профиле.';
    const manifest = {
        version: 2, campaign: catalog.campaign, id: slugId, series: template.slug,
        template: {id: template.id, slug: template.slug, segment: template.segment, status: template.status, format: template.format, goal: template.goal},
        hook: lib.substitute(template.hook, ev.vars.values), fingerprint, video: output, cover,
        duration_ms: Math.round(duration(output) * 1000), caption, gate: template.gate ? lib.substitute(template.gate, ev.vars.values) : null,
        cta_url: catalog.cta_url, cta_label: catalog.cta_label,
        beats: template.beats.map(b => ({n: b.n, start: b.start, end: b.end, type: b.frame.type, plate: lib.substitute(b.plate, ev.vars.values), silenceAfter: b.silenceAfter})),
        draft: ev.draft, draft_reasons: ev.reasons, assets_missing: ev.assets.missing, variables_missing: ev.vars.missing,
        warnings, ai_generated: ev.aiAssets.length > 0, ai_assets: ev.aiAssets, ai_badge: ev.aiBadge, audio: bed ? path.basename(bed) : null,
        publication_status: ev.draft ? 'draft' : 'preview',
    };
    fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
    console.log(`Rendered ${slugId}: ${(manifest.duration_ms / 1000).toFixed(1)} s, ${ev.draft ? 'DRAFT (' + ev.reasons.join('; ') + ')' : 'ready'}, ${output}`);
    return manifest;
}

function parseVars() {
    const vars = {};
    for (const pair of options('--var')) {
        const i = pair.indexOf('=');
        if (i < 1) throw new Error(`--var ожидает имя=значение: ${pair}`);
        vars[pair.slice(0, i)] = pair.slice(i + 1);
    }
    return vars;
}

async function main() {
    const catalog = lib.loadCatalog();
    const assetsBase = path.resolve(option('--assets', path.join(lib.root, 'var/wardrobe-reels/assets')));
    const shotlist = option('--shotlist', '');
    if (shotlist) {
        const md = lib.shotlistMarkdown(catalog, lib.selectTemplates(catalog, shotlist), assetsBase);
        const file = option('--file', '');
        if (file) { fs.writeFileSync(path.resolve(file), md + '\n'); console.log(`Шот-лист: ${path.resolve(file)}`); } else console.log(md);
        return;
    }
    let selected = [];
    if (args.includes('--all')) selected = catalog.templates;
    else if (args.includes('--all-ready')) selected = catalog.templates.filter(t => t.status === 'ready');
    else selected = options('--template').map(k => lib.findTemplate(catalog, k));
    if (!selected.length) throw new Error('Укажите --template <№|slug> (можно несколько), --all-ready, --all или --shotlist <№|slug|all>');
    const cliVars = parseVars();
    const fps = Number(option('--fps', '30'));
    if (!Number.isInteger(fps) || fps < 23 || fps > 60) throw new Error('--fps должен быть 23–60 (спека Meta Reels)');
    const evals = selected.map(t => ({t, ev: lib.evaluate(t, assetsBase, cliVars)}));
    if (args.includes('--validate')) {
        for (const {t, ev} of evals) console.log(`#${t.id} ${t.slug}: ${ev.draft ? 'draft — ' + ev.reasons.join('; ') : 'ready к публикации'}`);
        return;
    }
    const explicitOut = option('--out', '');
    const {chromium} = require('playwright');
    const browser = await chromium.launch({headless: true, args: ['--allow-file-access-from-files']});
    try {
        const page = await browser.newPage({viewport: {width: 1080, height: 1920}, deviceScaleFactor: 1});
        for (const {t, ev} of evals) {
            // Черновики по умолчанию — вне public_html: enqueue их физически не увидит.
            const out = path.resolve(explicitOut || (ev.draft ? path.join(lib.root, 'var/wardrobe-reels/drafts') : path.join(lib.root, 'public_html/images/social/wardrobe-templates')));
            fs.mkdirSync(out, {recursive: true});
            const manifest = await renderOne(page, catalog, t, ev, out, fps);
            const index = path.join(out, 'manifest.json');
            const merged = new Map((fs.existsSync(index) ? JSON.parse(fs.readFileSync(index, 'utf8')) : []).map(m => [m.id, m]));
            merged.set(manifest.id, manifest);
            fs.writeFileSync(index, JSON.stringify([...merged.values()], null, 2) + '\n');
        }
    } finally { await browser.close(); }
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
