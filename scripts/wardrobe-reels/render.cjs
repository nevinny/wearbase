#!/usr/bin/env node
// Motion graphics + Qwen narration from the owned GPU server.
const fs = require('node:fs');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const {createHash} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {once} = require('node:events');
const {chromium} = require('playwright');
const {prepareVoice} = require('./qwen.cjs');

const root = path.resolve(__dirname, '../..');
const catalog = JSON.parse(fs.readFileSync(path.join(root, 'config/social/wardrobe_reels.json'), 'utf8'));
const args = process.argv.slice(2);
function option(name, fallback) {
    const i = args.indexOf(name);
    if (i === -1) return fallback;
    if (!args[i + 1] || args[i + 1].startsWith('--')) throw new Error(`Missing value for ${name}`);
    return args[i + 1];
}
function run(command, parameters) {
    const result = spawnSync(command, parameters, {encoding: 'utf8', maxBuffer: 4 * 1024 * 1024});
    if (result.status !== 0) throw new Error(`${command}: ${result.error?.message || result.stderr}`);
    return result.stdout;
}
function duration(file) {
    return Number(run('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', file]).trim());
}
function validate() {
    const ids = new Set();
    for (const e of catalog.episodes) {
        if (!/^[a-z][a-z_]+$/.test(e.id) || ids.has(e.id)) throw new Error('Invalid or repeated episode id');
        ids.add(e.id);
        if (e.hooks.length !== 5 || e.scenes.length !== 4) throw new Error('Each series needs five hooks and four story scenes');
        if (e.scenes.at(-1).kind !== 'cta') throw new Error('Missing final CTA');
        for (const scene of [...e.hooks, ...e.scenes]) {
            if (!scene.text?.trim() || !scene.voice?.trim()) throw new Error('Empty scene');
            if (/ни у кого|единственн|гарантир/i.test(scene.text + scene.voice)) throw new Error('Unsupported exclusivity claim');
        }
        for (const proof of e.proof) if (!fs.existsSync(path.join(root, proof))) throw new Error(`Missing evidence ${proof}`);
        if (e.cta_url !== 'https://wearbase.ru/ru/wardrobe') throw new Error('Unexpected landing page');
    }
}

async function render(page, episode, variant, out, clipDir, voice) {
    const id = `${episode.id}-v${variant + 1}`;
    const work = path.join(out, id);
    fs.mkdirSync(work, {recursive: true});
    const output = path.join(work, `${id}.mp4`);
    const clip = path.join(clipDir, `${episode.id}.mp4`);
    const fingerprint = createHash('sha256')
        .update(JSON.stringify({episode, variant, version: catalog.version}))
        .update(fs.readFileSync(__filename))
        .update(fs.readFileSync(path.join(__dirname, 'scene.html')))
        .update(JSON.stringify(voice.metadata))
        .update(fs.existsSync(clip) ? fs.readFileSync(clip) : 'no-clip')
        .digest('hex');
    const manifestPath = path.join(work, 'manifest.json');
    if (fs.existsSync(output) && fs.existsSync(manifestPath)) {
        const existing = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
        if (existing.fingerprint !== fingerprint) throw new Error(`${id} exists with a different script; use a fresh --out directory`);
        if (duration(output) > 0) { console.log(`Cached ${id}`); return existing; }
    }
    const scenes = [{kind: 'hook', ...episode.hooks[variant]}, ...episode.scenes].map(s => ({...s}));
    scenes.forEach((scene, i) => { scene.duration = voice.metadata.scenes[i].end - voice.metadata.scenes[i].start; });
    const total = scenes.reduce((n, s) => n + s.duration, 0);
    const audioPath = path.join(work, 'voice.wav');
    run('ffmpeg', ['-v','error','-y','-i',voice.audioPath,'-af','loudnorm=I=-16:TP=-1.5:LRA=7','-ar','48000',audioPath]);
    await page.goto(pathToFileURL(path.join(__dirname, 'scene.html')).href);
    await page.evaluate(data => window.setup(data), {episode, scenes, clip: fs.existsSync(clip) ? pathToFileURL(clip).href : null});
    await page.evaluate(() => window.render(.5));
    const cover = path.join(work, 'cover.jpg');
    await page.screenshot({path: cover, type: 'jpeg', quality: 94});
    const silent = path.join(work, 'silent.mp4');
    const encoder = spawn('ffmpeg', ['-v','error','-y','-f','image2pipe','-vcodec','mjpeg','-r','15','-i','pipe:0','-an','-vf','scale=in_range=full:out_range=limited,format=yuv420p','-color_range','tv','-c:v','libx264','-r','30','-g','60','-preset','fast','-crf','20','-pix_fmt','yuv420p',silent]);
    let errors = '';
    encoder.stderr.on('data', chunk => errors += chunk);
    const closed = once(encoder, 'close');
    const frames = Math.ceil(total * 15);
    try {
        for (let frame = 0; frame < frames; frame++) {
            await page.evaluate(t => window.render(t), Math.min(frame / 15, total - .001));
            const jpeg = await page.screenshot({type: 'jpeg', quality: 88});
            if (!encoder.stdin.write(jpeg)) await once(encoder.stdin, 'drain');
        }
        encoder.stdin.end();
        const [code] = await closed;
        if (code !== 0) throw new Error(errors);
    } catch (error) { encoder.kill(); throw error; }
    run('ffmpeg', ['-v','error','-y','-i',silent,'-i',audioPath,'-map','0:v:0','-map','1:a:0','-c:v','copy','-c:a','aac','-b:a','128k','-shortest','-movflags','+faststart','-use_editlist','0',output]);
    const manifest = {version:1,campaign:catalog.campaign,id,series:episode.id,variant,hook:episode.hooks[variant].text,fingerprint,video:output,cover,duration_ms:Math.round(duration(output)*1000),caption:episode.hooks[variant].text.replaceAll('\n',' ')+'\n\n'+episode.caption,cta_url:episode.cta_url,cta_label:'Цифровой гардероб',scenes,voice:voice.metadata,ai_generated:true,synthetic_clip:fs.existsSync(clip),claim_notes:episode.claim,publication_status:'preview'};
    fs.writeFileSync(manifestPath, JSON.stringify(manifest,null,2)+'\n');
    console.log(`Rendered ${id}: ${(manifest.duration_ms/1000).toFixed(1)} s, ${manifest.synthetic_clip ? 'HF clip + motion' : 'motion graphics'}, ${output}`);
    return manifest;
}

async function main() {
    validate();
    if (args.includes('--validate')) { console.log('Six series, 30 hooks, evidence paths and CTA validated.'); return; }
    const variant = Number(option('--variant','0'));
    if (!Number.isInteger(variant) || variant < 0 || variant > 4) throw new Error('--variant must be 0–4');
    let selected = catalog.episodes;
    const id = option('--episode','');
    if (id) selected = selected.filter(e => e.id === id);
    if (!selected.length) throw new Error('Unknown episode');
    const out = path.resolve(option('--out',path.join(root,'public_html/images/social/wardrobe-qwen-v1')));
    const clips = path.resolve(option('--clips',path.join(root,'var/wardrobe-reels/clips')));
    fs.mkdirSync(out,{recursive:true});
    const voices = new Map();
    for (const e of selected) {
        const voice = prepareVoice([e.hooks[variant], ...e.scenes], path.join(root, 'var/wardrobe-reels/qwen-audio'));
        if (Math.abs(duration(voice.audioPath) - voice.metadata.duration) > .05) throw new Error('Qwen WAV duration differs from scene timings');
        voices.set(e.id, voice);
        console.log(`Qwen ${e.id}: ${voice.metadata.duration.toFixed(1)} s, ${voice.audioPath}`);
    }
    if (args.includes('--voice-only')) return;
    const browser = await chromium.launch({headless:true,args:['--allow-file-access-from-files']});
    try {
        const page = await browser.newPage({viewport:{width:1080,height:1920},deviceScaleFactor:1});
        const manifests = [];
        for (const e of selected) manifests.push(await render(page,e,variant,out,clips,voices.get(e.id)));
        const index = path.join(out, 'manifest.json');
        const previous = fs.existsSync(index) ? JSON.parse(fs.readFileSync(index, 'utf8')) : [];
        const merged = new Map(previous.map(m => [m.id, m]));
        for (const manifest of manifests) merged.set(manifest.id, manifest);
        fs.writeFileSync(index,JSON.stringify([...merged.values()],null,2)+'\n');
    } finally { await browser.close(); }
}
main().catch(error => { console.error(error.message); process.exitCode=1; });
