#!/usr/bin/env node
// QA-гейт озвученного рилса: распознаёт звук ГОТОВОГО mp4 (после mux) whisper.cpp на Mac и сверяет со сценарием.
// Зачем: qwen_voice.py проверяет только WAV до сборки, а mux с `-shortest` может срезать последнее слово.
// Идея — из гайда «Авто-монтаж» (vasinki.ru, см. docs/reels_pipeline_vasinki_adoption.md); код свой.
// Ничего не публикует и не правит: только отчёт и код выхода (0 — ок, 1 — брак, 2 — ошибка запуска).
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {spokenText} = require('./qwen.cjs');

const DEFAULT_MODEL = path.join(os.homedir(), 'tg-bots/agent-router/models/ggml-large-v3-turbo.bin');

// Слова для сверки: регистр, ё→е, без пунктуации. Цифры остаются токенами — «116» и «сто шестнадцать» не совпадут
// и попадут в отчёт как пропуск/лишнее; в сценарии озвучки числа лучше писать так, как их распознаёт whisper (цифрами).
function normalizeWords(text) {
    return String(text).toLowerCase().replace(/ё/g, 'е').replace(/[^\p{L}\p{N}]+/gu, ' ').trim().split(' ').filter(Boolean);
}

// Бренд whisper пишет каждый раз по-своему («Вербейс», «в Airbase»): в сценарии он — джокер на одно любое
// услышанное слово. Обрыв на бренде всё равно ловится: после предыдущего слова не прозвучит ничего.
const BRAND = 'WEARBASE';
function expectedWords(text) {
    const out = [];
    for (const w of normalizeWords(text)) {
        if (w === 'wearbase') out.push(BRAND);
        else if (w === 'бейс' && out.at(-1) === 'веар') out[out.length - 1] = BRAND;
        else out.push(w);
    }
    return out;
}
const same = (expected, heard) => expected === heard || expected === BRAND;

// Пословное выравнивание (LCS): что из сценария не прозвучало, что прозвучало лишним, и цел ли хвост.
function diffWords(expectedText, heardText, tail = 3) {
    const a = expectedWords(expectedText), b = normalizeWords(heardText);
    const dp = Array.from({length: a.length + 1}, () => new Array(b.length + 1).fill(0));
    for (let i = a.length - 1; i >= 0; i--) for (let j = b.length - 1; j >= 0; j--) {
        dp[i][j] = same(a[i], b[j]) ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    }
    const missing = [], extra = [];
    let i = 0, j = 0;
    while (i < a.length && j < b.length) {
        if (same(a[i], b[j])) { i++; j++; } else if (dp[i + 1][j] >= dp[i][j + 1]) missing.push({index: i, word: a[i++]}); else extra.push(b[j++]);
    }
    while (i < a.length) missing.push({index: i, word: a[i++]});
    while (j < b.length) extra.push(b[j++]);
    const tailFrom = Math.max(0, a.length - tail);
    return {
        expected: a.length, heard: b.length, matched: dp[0][0],
        recall: a.length ? dp[0][0] / a.length : 1,
        missing, extra, tailMissing: missing.filter(m => m.index >= tailFrom).map(m => m.word),
    };
}

// Брак: оборван хвост (типичный след `-shortest`) или пропало больше допустимой доли слов.
function verdict(diff, maxMissing = .1) {
    const reasons = [];
    if (diff.tailMissing.length) reasons.push(`не слышно конца: ${diff.tailMissing.join(' ')}`);
    if (diff.expected && diff.missing.length / diff.expected > maxMissing) reasons.push(`пропущено ${diff.missing.length} из ${diff.expected} слов`);
    return {ok: reasons.length === 0, reasons};
}

// Ожидаемый текст: из manifest.json рилса (voice.scenes — уже в произносимой форме) или из аргумента.
function expectedFromManifest(manifest) {
    const scenes = manifest?.voice?.scenes;
    if (!Array.isArray(scenes) || !scenes.length) throw new Error('В manifest нет voice.scenes: ролик без озвучки, сверять нечего');
    return scenes.map(s => s.text).join(' ');
}

function transcribe(video, model) {
    if (!fs.existsSync(model)) throw new Error(`Нет модели whisper: ${model} (задайте --model или WHISPER_MODEL)`);
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'reel-qa-'));
    try {
        const wav = path.join(dir, 'a.wav'), base = path.join(dir, 'words');
        const ff = spawnSync('ffmpeg', ['-v', 'error', '-y', '-i', video, '-vn', '-ar', '16000', '-ac', '1', '-c:a', 'pcm_s16le', wav], {encoding: 'utf8'});
        if (ff.status !== 0) throw new Error(`ffmpeg: ${ff.stderr || ff.error?.message}`);
        const wh = spawnSync('whisper-cli', ['-m', model, '-f', wav, '-l', 'ru', '-oj', '-np', '-of', base], {encoding: 'utf8', maxBuffer: 16 * 1024 * 1024});
        if (wh.status !== 0) throw new Error(`whisper-cli: ${wh.stderr || wh.error?.message}`);
        const json = JSON.parse(fs.readFileSync(base + '.json', 'utf8'));
        return (json.transcription || []).map(s => s.text).join(' ');
    } finally { fs.rmSync(dir, {recursive: true, force: true}); }
}

function main(argv) {
    const opt = name => { const i = argv.indexOf(name); return i === -1 ? undefined : argv[i + 1]; };
    const video = argv[0];
    if (!video || video.startsWith('--')) {
        console.error('Использование: qa-transcript.cjs <video.mp4> (--manifest manifest.json | --text "…" | --text-file f.txt) [--model ggml.bin] [--tail 3] [--max-missing 0.1] [--json]');
        return 2;
    }
    let expected;
    if (opt('--manifest')) {
        const raw = JSON.parse(fs.readFileSync(opt('--manifest'), 'utf8'));
        const manifest = Array.isArray(raw) ? raw.find(m => path.resolve(m.video || '') === path.resolve(video)) : raw;
        if (!manifest) throw new Error('В manifest нет записи для этого видео');
        expected = expectedFromManifest(manifest);
    } else if (opt('--text') !== undefined) expected = spokenText(opt('--text'));
    else if (opt('--text-file')) expected = spokenText(fs.readFileSync(opt('--text-file'), 'utf8'));
    else throw new Error('Нужен --manifest, --text или --text-file');
    const heard = transcribe(video, opt('--model') || process.env.WHISPER_MODEL || DEFAULT_MODEL);
    const diff = diffWords(expected, heard, Number(opt('--tail') || 3));
    const result = verdict(diff, Number(opt('--max-missing') || .1));
    if (argv.includes('--json')) console.log(JSON.stringify({video, heard, ...diff, ...result}, null, 2));
    else {
        console.log(`Слышно: ${heard.trim()}`);
        console.log(`Совпало ${diff.matched}/${diff.expected} слов (${(diff.recall * 100).toFixed(0)}%)`);
        if (diff.missing.length) console.log(`Пропущено: ${diff.missing.map(m => m.word).join(', ')}`);
        if (diff.extra.length) console.log(`Лишнее: ${diff.extra.join(', ')}`);
        console.log(result.ok ? 'QA: ок' : `QA: БРАК — ${result.reasons.join('; ')}`);
    }
    return result.ok ? 0 : 1;
}

if (require.main === module) {
    try { process.exitCode = main(process.argv.slice(2)); } catch (error) { console.error(error.message); process.exitCode = 2; }
}

module.exports = {normalizeWords, diffWords, verdict, expectedFromManifest};
