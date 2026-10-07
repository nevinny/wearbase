"""Generate a complete Russian narration on the owned GPU server over JSON stdin/stdout."""

import argparse
import base64
from contextlib import redirect_stdout
from difflib import SequenceMatcher
import fcntl
import gc
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import sys
import time
import wave

from voice_samples import check_resources, STYLES, TEXT


PROTOCOL = 1
MODELS = {
    "design": "Qwen/Qwen3-TTS-12Hz-1.7B-VoiceDesign",
    "base": "Qwen/Qwen3-TTS-12Hz-1.7B-Base",
    "asr": "Systran/faster-whisper-small",
}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def tokens(text):
    return re.findall(r"[а-яa-z0-9]+", text.lower().replace("ё", "е"))


def validate_request(request):
    if not isinstance(request, dict):
        raise ValueError("Expected a JSON object.")
    texts = request.get("texts")
    if request.get("version") != PROTOCOL or not isinstance(texts, list) or len(texts) != 5:
        raise ValueError("Expected protocol 1 and five scene texts.")
    if any(not isinstance(t, str) or not t.strip() or len(t) > 300 for t in texts) or sum(map(len, texts)) > 1200:
        raise ValueError("Scene texts must be nonempty and total at most 1200 characters.")
    return texts


def align_scenes(texts, words, duration):
    expected, offsets = [], []
    for text in texts:
        offsets.append(len(expected))
        expected.extend(tokens(text))
    actual, timestamps = [], []
    for word in words:
        for token in tokens(word["word"]):
            actual.append(token)
            timestamps.append((float(word["start"]), float(word["end"])))
    if not actual or not expected:
        raise ValueError("No recognized speech.")
    if any(not 0 <= start < end <= duration + .1 for start, end in timestamps):
        raise ValueError("Invalid word timestamps.")
    if any(current[0] < previous[0] for previous, current in zip(timestamps, timestamps[1:])):
        raise ValueError("Word timestamps are out of order.")
    matcher = SequenceMatcher(None, expected, actual, autojunk=False)
    if matcher.ratio() < .78:
        raise ValueError(f"Narration differs from the script: similarity {matcher.ratio():.2f}.")
    mapping = {block.a + i: block.b + i for block in matcher.get_matching_blocks() for i in range(block.size)}
    anchors = []
    for i, offset in enumerate(offsets):
        end = offsets[i + 1] if i + 1 < len(offsets) else len(expected)
        matches = [mapping[j] for j in range(offset, end) if j in mapping]
        if len(matches) < max(2, (end - offset) * .6):
            raise ValueError(f"Scene {i + 1} is missing or differs from the script.")
        anchors.append((matches[0], matches[-1]))
    boundaries = [0.0]
    for previous, current in zip(anchors, anchors[1:]):
        boundaries.append((timestamps[previous[1]][1] + timestamps[current[0]][0]) / 2)
    boundaries.append(duration)
    if any(b - a < .5 for a, b in zip(boundaries, boundaries[1:])):
        raise ValueError("Scene timing is too short or out of order.")
    return [{"text": text, "start": boundaries[i], "end": boundaries[i + 1]} for i, text in enumerate(texts)], round(matcher.ratio(), 4)


def snapshots(data, setup):
    from huggingface_hub import HfApi, snapshot_download

    lock = data / "models.json"
    if not lock.exists():
        if not setup:
            raise RuntimeError("Qwen is not installed. Run qwen-server.sh first.")
        revisions = {key: {"repo_id": repo, "revision": HfApi().model_info(repo).sha} for key, repo in MODELS.items()}
        lock.write_text(json.dumps(revisions, indent=2) + "\n")
    revisions = json.loads(lock.read_text())
    if set(revisions) != set(MODELS) or any(revisions[key]["repo_id"] != repo for key, repo in MODELS.items()):
        raise RuntimeError("Unexpected model lock; use a fresh data directory.")
    paths = {key: snapshot_download(**model, max_workers=1, local_files_only=not setup) for key, model in revisions.items()}
    return paths, revisions


def load_qwen(path):
    import torch
    from qwen_tts import Qwen3TTSModel

    return Qwen3TTSModel.from_pretrained(
        path, device_map="cuda:0", dtype=torch.bfloat16 if torch.cuda.is_bf16_supported() else torch.float16,
        attn_implementation="sdpa", low_cpu_mem_usage=True,
    )


def write_audio(path, waveform, rate):
    import numpy as np
    import soundfile as sf

    waveform = np.asarray(waveform, dtype=np.float32).squeeze()
    if waveform.ndim != 1 or not np.isfinite(waveform).all() or not 3 < len(waveform) / rate < 58:
        raise ValueError("Invalid narration length or waveform.")
    if float(np.sqrt(np.mean(waveform ** 2))) < .001:
        raise ValueError("Narration is silent.")
    peak = float(np.max(np.abs(waveform)))
    sf.write(path, waveform * min(1, .95 / peak), rate, subtype="PCM_16")


def transcribe(path, model_path):
    from faster_whisper import WhisperModel

    model = WhisperModel(model_path, device="cpu", compute_type="int8", cpu_threads=2, local_files_only=True)
    segments, _ = model.transcribe(str(path), language="ru", beam_size=5, word_timestamps=True, vad_filter=False)
    words = [{"word": w.word, "start": w.start, "end": w.end} for segment in segments for w in (segment.words or [])]
    del model
    gc.collect()
    return words


def run(data, setup, request):
    if setup and shutil.disk_usage(data).free < 12 * 1024 ** 3:
        raise RuntimeError("Reserve at least 12 GiB disk space before downloading Qwen and Whisper.")
    paths, revisions = snapshots(data, setup)
    reference, profile = data / "reference.wav", data / "reference.json"
    if not reference.exists() and not setup:
        raise RuntimeError("Missing brand voice; run qwen-server.sh first.")
    texts = None if setup else validate_request(request)
    reference_metadata = json.loads(profile.read_text()) if reference.exists() and profile.exists() else None
    if reference_metadata and reference_metadata["sha256"] != digest(reference.read_bytes()):
        raise RuntimeError("Reference voice changed without its metadata; prepare a new profile.")
    if setup and reference_metadata:
        return {"ready": True, "models": revisions, "reference_sha256": digest(reference.read_bytes()), "listening_review": "pending"}
    key = digest(json.dumps({"texts": texts, "models": revisions, "reference": reference_metadata, "worker": digest(Path(__file__).read_bytes())}, sort_keys=True).encode())
    audio, result_file = data / f"{key}.wav", data / f"{key}.json"
    if not setup and audio.exists() and result_file.exists():
        result = json.loads(result_file.read_text())
        if result["audio_sha256"] == digest(audio.read_bytes()):
            return {**result, "audio_base64": base64.b64encode(audio.read_bytes()).decode()}
        raise RuntimeError("Cached narration checksum failed.")
    resources = check_resources("qwen")
    os.environ["CUDA_VISIBLE_DEVICES"] = resources["gpu"]["uuid"]
    import torch

    if not torch.cuda.is_available():
        raise RuntimeError("CUDA unavailable; CPU synthesis is disabled.")
    torch.set_num_threads(2)
    torch.manual_seed(1062026)
    started = time.monotonic()
    model = load_qwen(paths["design" if setup else "base"])
    with torch.inference_mode():
        if setup:
            waves, rate = model.generate_voice_design(text=TEXT, language="Russian", instruct=STYLES["warm"], max_new_tokens=600)
        else:
            if reference_metadata is None:
                raise RuntimeError("Missing reference transcript; repeat server setup.")
            waves, rate = model.generate_voice_clone(
                text=" ".join(texts), language="Russian", ref_audio=str(reference), ref_text=reference_metadata["text"],
                x_vector_only_mode=False, max_new_tokens=696,
            )
    temporary = data / ("reference.partial.wav" if setup else f"{key}.partial.wav")
    write_audio(temporary, waves[0], rate)
    del waves, model
    gc.collect()
    torch.cuda.empty_cache()
    words = transcribe(temporary, paths["asr"])
    with wave.open(str(temporary)) as wav:
        duration = wav.getnframes() / wav.getframerate()
    if setup:
        _, similarity = align_scenes([TEXT], words, duration)
        temporary.replace(reference)
        profile.write_text(json.dumps({"text": TEXT, "instruction": STYLES["warm"], "sha256": digest(reference.read_bytes()), "asr_similarity": similarity}, ensure_ascii=False, indent=2) + "\n")
        return {"ready": True, "models": revisions, "reference_sha256": digest(reference.read_bytes()), "listening_review": "pending"}
    scenes, similarity = align_scenes(texts, words, duration)
    temporary.replace(audio)
    result = {
        "version": PROTOCOL, "engine": "qwen3-tts", "models": revisions,
        "reference_sha256": digest(reference.read_bytes()), "audio_sha256": digest(audio.read_bytes()),
        "sample_rate": rate, "duration": duration, "scenes": scenes, "seed": 1062026,
        "asr_similarity": similarity, "transcript": "".join(word["word"] for word in words),
        "seconds_elapsed": round(time.monotonic() - started, 2), "listening_review": "pending",
    }
    result_file.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n")
    return {**result, "audio_base64": base64.b64encode(audio.read_bytes()).decode()}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--setup", action="store_true")
    parser.add_argument("--data", type=Path, default=Path(__file__).resolve().parent / "data")
    args = parser.parse_args()
    request = None if args.setup else json.loads(sys.stdin.read(16000))
    if not args.setup:
        validate_request(request)
    args.data.mkdir(parents=True, exist_ok=True)
    os.environ.setdefault("HF_HOME", str(args.data / "huggingface"))
    os.environ.setdefault("HF_HUB_DISABLE_TELEMETRY", "1")
    os.environ.setdefault("TOKENIZERS_PARALLELISM", "false")
    if not args.setup:
        os.environ["HF_HUB_OFFLINE"] = "1"
        os.environ["TRANSFORMERS_OFFLINE"] = "1"
    with (args.data / "worker.lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        # Dependencies can print progress. Only the final JSON belongs on stdout.
        with redirect_stdout(sys.stderr):
            result = run(args.data, args.setup, request)
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(f"Qwen narration failed: {error}", file=sys.stderr)
        sys.exit(1)
