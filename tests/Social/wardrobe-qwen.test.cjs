const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {prepareVoice, spokenText, validateVoice} = require('../../scripts/wardrobe-reels/qwen.cjs');

const texts = ['Шкаф полный.', 'Нечего надеть?', 'Добавь вещи.', 'Собирай образы.', 'Начни сегодня.'];
function fixture() {
    const rate = 8000, seconds = 10;
    const audio = Buffer.alloc(44 + rate * seconds * 2);
    audio.write('RIFF', 0); audio.writeUInt32LE(audio.length - 8, 4); audio.write('WAVEfmt ', 8);
    audio.writeUInt32LE(16, 16); audio.writeUInt16LE(1, 20); audio.writeUInt16LE(1, 22);
    audio.writeUInt32LE(rate, 24); audio.writeUInt32LE(rate * 2, 28);
    audio.writeUInt16LE(2, 32); audio.writeUInt16LE(16, 34);
    audio.write('data', 36); audio.writeUInt32LE(audio.length - 44, 40);
    // Synthetic test tone, never presented as a Qwen voice sample.
    for (let i = 0; i < rate * seconds; i++) audio.writeInt16LE(Math.round(3000 * Math.sin(i * 2 * Math.PI * 220 / rate)), 44 + i * 2);
    return {
        version: 1, engine: 'qwen3-tts', duration: seconds, asr_similarity: 1,
        sample_rate: rate, reference_sha256: 'a'.repeat(64),
        models: {base: {repo_id: 'Qwen/Qwen3-TTS-12Hz-1.7B-Base', revision: 'b'.repeat(40)}},
        scenes: texts.map((text, i) => ({text, start: i * 2, end: i * 2 + 2})),
        audio_sha256: createHash('sha256').update(audio).digest('hex'), audio_base64: audio.toString('base64'),
    };
}
test('whole script uses one SSH request and saves the returned WAV', () => {
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'wardrobe-qwen-'));
    let calls = 0;
    try {
        const result = prepareVoice(texts.map(voice => ({voice})), temp, (command, args, options) => {
            calls++;
            assert.equal(command, 'ssh');
            assert.ok(args.includes('BatchMode=yes'));
            assert.deepEqual(JSON.parse(options.input), {version: 1, texts});
            return {status: 0, stdout: JSON.stringify(fixture())};
        });
        assert.equal(calls, 1);
        assert.equal(fs.readFileSync(result.audioPath).length, 160044);
        assert.equal(result.metadata.engine, 'qwen3-tts');
        assert.equal(result.metadata.scenes.at(-1).end, 10);
        assert.equal(result.metadata.audio_base64, undefined);
    } finally { fs.rmSync(temp, {recursive: true, force: true}); }
});
test('server failure stops generation without a fallback voice', () => {
    let calls = 0;
    assert.throws(() => prepareVoice(texts.map(voice => ({voice})), '/unused', () => {
        calls++; return {status: 255, stderr: 'SSH unavailable'};
    }), /SSH unavailable/);
    assert.equal(calls, 1);
});
test('pronunciation normalization does not alter Cyrillic words', () => {
    assert.equal(spokenText(' AI для Wearbase.  Мои вещи. '), 'искусственный интеллект для Веар бейс. Мои вещи.');
});
test('another engine or an unpinned model is refused', () => {
    const data = fixture();
    data.engine = 'milena';
    assert.throws(() => validateVoice(data, texts), /metadata/);
    data.engine = 'qwen3-tts'; data.models.base.revision = 'main';
    assert.throws(() => validateVoice(data, texts), /metadata/);
});
test('audio from a different script is refused', () => {
    const data = fixture(); data.scenes[2].text = 'Купи новую одежду.';
    assert.throws(() => validateVoice(data, texts), /text or timing/);
});
test('overlapping scenes and truncated timelines are refused', () => {
    const data = fixture(); data.scenes[2].start = 3.5;
    assert.throws(() => validateVoice(data, texts), /text or timing/);
    data.scenes[2].start = 4; data.duration = 11;
    assert.throws(() => validateVoice(data, texts), /fully covered/);
});
test('corrupted audio is refused', () => {
    const data = fixture(); data.audio_sha256 = 'c'.repeat(64);
    assert.throws(() => validateVoice(data, texts), /corrupted/);
});
test('poor speech recognition match is refused', () => {
    const data = fixture(); data.asr_similarity = .4;
    assert.throws(() => validateVoice(data, texts), /metadata/);
});
test('SSH target defaults to the llm alias and accepts bare aliases', () => {
    const old = process.env.WARDROBE_TTS_SSH;
    delete process.env.WARDROBE_TTS_SSH;
    try {
        let host;
        prepareVoice(texts.map(voice => ({voice})), fs.mkdtempSync(path.join(os.tmpdir(), 'wardrobe-qwen-')), (command, args) => {
            host = args.at(-2); return {status: 0, stdout: JSON.stringify(fixture())};
        });
        assert.equal(host, 'llm');
        process.env.WARDROBE_TTS_SSH = 'bad host; rm';
        assert.throws(() => prepareVoice(texts.map(voice => ({voice})), '/unused', () => ({status: 0})), /SSH alias/);
    } finally { if (old === undefined) delete process.env.WARDROBE_TTS_SSH; else process.env.WARDROBE_TTS_SSH = old; }
});
