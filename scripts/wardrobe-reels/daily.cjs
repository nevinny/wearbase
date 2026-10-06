#!/usr/bin/env node
// One local Reel per campaign day. Publishing remains with app:social:publish-tick.
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const args = process.argv.slice(2);
function option(name, fallback) {
    const i = args.indexOf(name);
    return i < 0 ? fallback : args[i + 1];
}
function parseDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) throw new Error('Expected YYYY-MM-DD');
    const date = new Date(value + 'T00:00:00Z');
    if (!Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== value) throw new Error('Invalid date');
    return date;
}
function run(command, parameters) {
    const result = spawnSync(command, parameters, {cwd: root, stdio: 'inherit'});
    if (result.status !== 0) throw new Error(`${command} failed: ${result.error?.message || result.status}`);
}
function removeStaleLock(lock) {
    if (!fs.existsSync(lock)) return;
    const pid = parseInt(fs.readFileSync(lock, 'utf8'), 10);
    if (Number.isInteger(pid) && pid > 0) {
        try { process.kill(pid, 0); return; } catch (error) { if (error.code !== 'ESRCH') return; }
    }
    console.warn(`Removing stale lock ${lock} (pid ${pid} is not alive).`);
    fs.unlinkSync(lock);
}
function main() {
    const start = parseDate(option('--start', ''));
    const today = option('--date', new Intl.DateTimeFormat('en-CA', {timeZone: 'Europe/Moscow', year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date()));
    const day = Math.round((parseDate(today) - start) / 86400000);
    if (day < 0 || day >= 30) { console.log('Outside the 30-day pilot. No repeated posts.'); return; }
    const catalog = JSON.parse(fs.readFileSync(path.join(root, 'config/social/wardrobe_reels.json'), 'utf8'));
    const episode = catalog.episodes[day % 6];
    const variant = Math.floor(day / 6);
    console.log(`Day ${day + 1}: ${episode.id}, hook ${variant + 1}, slot ${today} 19:00 Europe/Moscow`);
    if (args.includes('--plan')) return;
    const work = path.join(root, 'var/wardrobe-reels');
    fs.mkdirSync(work, {recursive: true});
    const lock = path.join(work, 'daily.lock');
    removeStaleLock(lock);
    let fd;
    try { fd = fs.openSync(lock, 'wx'); } catch (error) {
        if (error.code === 'EEXIST') throw new Error(`Daily run already in progress (lock ${lock}).`);
        throw error;
    }
    fs.writeFileSync(fd, String(process.pid));
    try {
        const clip = path.join(work, 'clips', `${episode.id}.mp4`);
        if (args.includes('--hf') && !fs.existsSync(clip)) {
            try {
                run(path.join(root, 'var/wardrobe-reels-venv/bin/python'), [path.join(__dirname, 'generate_video.py'), episode.id, '--offline']);
            } catch (error) {
                console.warn(`HF generation failed; using local motion graphics. ${error.message}`);
            }
        }
        const out = path.join(root, 'public_html/images/social/wardrobe-qwen-daily');
        run(process.execPath, [path.join(__dirname, 'render.cjs'), '--episode', episode.id, '--variant', String(variant), '--out', out]);
        if (args.includes('--schedule')) {
            run(process.env.PHP_BIN || '/opt/homebrew/bin/php', ['-d', 'memory_limit=512M', 'bin/console', 'app:social:enqueue-wardrobe-reels', path.join(out, `${episode.id}-v${variant + 1}`, 'manifest.json'), '--start', today, '--schedule']);
        }
    } finally {
        fs.closeSync(fd);
        fs.unlinkSync(lock);
    }
}
try { main(); } catch (error) { console.error(error.message); process.exitCode = 1; }
