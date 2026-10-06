const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {spawnSync} = require('node:child_process');

const hash = bytes => createHash('sha256').update(bytes).digest('hex');
function spokenText(text) {
    return text.replace(/\bAI\b/g, 'искусственный интеллект').replace(/\bwearbase\b/gi, 'Веар бейс').replace(/\s+/g, ' ').trim();
}
function validateVoice(data, texts) {
    if (data.version !== 1 || data.engine !== 'qwen3-tts' || data.scenes?.length !== texts.length
        || !Number.isFinite(data.duration) || data.duration <= 3 || data.duration >= 58
        || !Number.isFinite(data.asr_similarity) || data.asr_similarity < .78
        || !/^[a-f0-9]{64}$/.test(data.reference_sha256 || '')
        || data.models?.base?.repo_id !== 'Qwen/Qwen3-TTS-12Hz-1.7B-Base'
        || !/^[a-f0-9]{40}$/.test(data.models?.base?.revision || '')) {
        throw new Error('Invalid Qwen narration metadata');
    }
    let end = 0;
    for (const [i, scene] of data.scenes.entries()) {
        if (scene.text !== texts[i] || !Number.isFinite(scene.start) || !Number.isFinite(scene.end)
            || Math.abs(scene.start - end) > .001 || scene.end - scene.start < .5) {
            throw new Error('Qwen scene text or timing does not match this script');
        }
        end = scene.end;
    }
    if (Math.abs(end - data.duration) > .001) throw new Error('Qwen narration is not fully covered by scenes');
    const audio = Buffer.from(data.audio_base64 || '', 'base64');
    if (audio.length < 44 || audio.length > 12 * 1024 * 1024 || audio.toString('ascii', 0, 4) !== 'RIFF'
        || audio.toString('ascii', 8, 12) !== 'WAVE' || hash(audio) !== data.audio_sha256) {
        throw new Error('Invalid or corrupted Qwen WAV');
    }
    return audio;
}
function prepareVoice(scenes, cache, execute = spawnSync) {
    const texts = scenes.map(scene => spokenText(scene.voice));
    const host = process.env.WARDROBE_TTS_SSH || 'zyablik@192.168.2.43';
    if (!/^[a-zA-Z0-9_.-]+@[a-zA-Z0-9_.-]+$/.test(host)) throw new Error('WARDROBE_TTS_SSH must be user@host');
    const request = JSON.stringify({version: 1, texts});
    const command = 'exec "$HOME/wearbase-qwen-tts/.venv/bin/python" "$HOME/wearbase-qwen-tts/qwen_voice.py"';
    const result = execute('ssh', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3', host, command], {
        input: request, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024, timeout: 20 * 60 * 1000,
    });
    if (result.status !== 0) throw new Error(`Qwen server failed: ${result.error?.message || result.stderr || result.status}`);
    const data = JSON.parse(result.stdout);
    const audio = validateVoice(data, texts);
    const {audio_base64, ...metadata} = data;
    fs.mkdirSync(cache, {recursive: true});
    const name = `${hash(request)}-${hash(JSON.stringify(metadata))}`;
    const audioPath = path.join(cache, `${name}.wav`);
    fs.writeFileSync(audioPath, audio);
    fs.writeFileSync(path.join(cache, `${name}.json`), JSON.stringify(metadata, null, 2) + '\n');
    return {audioPath, metadata};
}

module.exports = {prepareVoice, spokenText, validateVoice};
