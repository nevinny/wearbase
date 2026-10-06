"""Russian voice auditions on the owned NVIDIA server; no paid inference APIs."""

import argparse
import csv
import json
import os
from pathlib import Path
import shutil
import subprocess
import time


TEXT = (
    "Шкаф полный. А надеть опять нечего? "
    "Ты просто не видишь всё, что у тебя уже есть. "
    "Добавь вещи в цифровой гардероб и собирай образы из своей одежды. "
    "Начни с пяти вещей."
)
STYLES = {
    "warm": "Adult female voice, native Russian pronunciation. Warm, confident, natural conversational delivery. A little recognition and surprise in the opening question. Clear speech, natural short pauses, moderate pace, like talking to a friend.",
    "lively": "Adult female voice, native Russian pronunciation. Lively, engaging conversational delivery with a lightly playful opening question. Friendly and confident, natural sentence stress, brisk but clearly articulated speech.",
}
MODELS = {
    "qwen": "Qwen/Qwen3-TTS-12Hz-1.7B-VoiceDesign",
    "chatterbox": "ResembleAI/chatterbox",
}


def check_resources(engine):
    # A quiet rig must be explicitly brought online by its operator.
    if not Path("/proc/modules").exists() or not any(
        line.startswith("nvidia ") for line in Path("/proc/modules").read_text().splitlines()
    ):
        raise RuntimeError("NVIDIA driver is not loaded. Check the rig's working/quiet mode first.")
    memory = dict(line.split(":", 1) for line in Path("/proc/meminfo").read_text().splitlines())
    available_mib = int(memory["MemAvailable"].split()[0]) // 1024
    if available_mib < 4096:
        raise RuntimeError(f"Only {available_mib} MiB system RAM available; reserve at least 4096 MiB for this pilot.")
    result = subprocess.run([
        "nvidia-smi", "--query-gpu=uuid,name,memory.free,utilization.gpu,temperature.gpu",
        "--format=csv,noheader,nounits",
    ], text=True, capture_output=True, check=True)
    processes = subprocess.run([
        "nvidia-smi", "--query-compute-apps=gpu_uuid", "--format=csv,noheader,nounits",
    ], text=True, capture_output=True, check=True)
    occupied = set(processes.stdout.split())
    # Measured: Qwen3-TTS 1.7B peaks at ~4.3 GiB VRAM.
    minimum = 6144
    # QWEN_TTS_GPU pins a GPU (uuid) chosen by the operator; other workloads on it are allowed.
    pin_file = Path(__file__).with_name("gpu-pin")
    pinned = os.environ.get("QWEN_TTS_GPU", "").strip() or (pin_file.read_text().strip() if pin_file.exists() else "")
    candidates = []
    for row in csv.reader(result.stdout.splitlines(), skipinitialspace=True):
        uuid, name, free, utilization, temperature = [value.strip() for value in row]
        if pinned and uuid != pinned:
            continue
        if (pinned or uuid not in occupied) and int(free) >= minimum and int(utilization) <= 10 and int(temperature) < 75:
            candidates.append({"uuid": uuid, "name": name, "free_mib": int(free)})
    if not candidates:
        raise RuntimeError(f"No unoccupied GPU with {minimum} MiB free. Existing workloads have not been stopped.")
    gpu = max(candidates, key=lambda item: item["free_mib"])
    return {"gpu": gpu, "ram_available_mib": available_mib}


def download(engine, output, offline):
    from huggingface_hub import HfApi, snapshot_download

    lock = output / f"{engine}-model.json"
    if lock.exists():
        metadata = json.loads(lock.read_text())
        if metadata["repo_id"] != MODELS[engine]:
            raise RuntimeError("Model lock belongs to another engine; use a fresh output directory.")
    else:
        if offline:
            raise RuntimeError("Download the model once before using --offline.")
        metadata = {"repo_id": MODELS[engine], "revision": HfApi().model_info(MODELS[engine]).sha}
        lock.write_text(json.dumps(metadata, indent=2) + "\n")
    patterns = None if engine == "qwen" else [
        "ve.pt", "t3_mtl23ls_v3.safetensors", "s3gen.pt",
        "grapheme_mtl_merged_expanded_v1.json", "conds.pt", "Cangjie5_TC.json", "LICENSE*", "README.md",
    ]
    snapshot = snapshot_download(**metadata, allow_patterns=patterns, max_workers=1, local_files_only=offline)
    return snapshot, metadata


def save_sample(output, name, waveform, sample_rate, metadata):
    import numpy as np
    import pyloudnorm as pyln
    import soundfile as sf

    waveform = np.asarray(waveform, dtype=np.float32).squeeze()
    duration = len(waveform) / sample_rate
    if waveform.ndim != 1 or not np.isfinite(waveform).all() or not 3 <= duration <= 40:
        raise RuntimeError(f"Invalid audio in {name}; manual investigation required.")
    loudness = pyln.Meter(sample_rate).integrated_loudness(waveform)
    if not np.isfinite(loudness):
        raise RuntimeError(f"Silent audio in {name}.")
    sf.write(output / f"{name}.raw.wav", waveform, sample_rate, subtype="PCM_24")
    normalized = pyln.normalize.loudness(waveform, loudness, -16.0)
    # Limit sample peaks without compressing or removing the model's watermark.
    peak = float(np.max(np.abs(normalized)))
    normalized *= min(1.0, (10 ** (-1.5 / 20)) / max(peak, 1e-9))
    sf.write(output / f"{name}.wav", normalized, sample_rate, subtype="PCM_24")
    metadata.update({
        "text": TEXT, "duration_seconds": round(duration, 3), "sample_rate": sample_rate,
        "raw_lufs": round(float(loudness), 2),
        "listening_lufs": round(float(pyln.Meter(sample_rate).integrated_loudness(normalized)), 2),
        "human_listening_review": "pending", "sample": f"{name}.wav",
    })
    (output / f"{name}.json").write_text(json.dumps(metadata, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps(metadata, ensure_ascii=False), flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("engine", choices=MODELS)
    parser.add_argument("--output", type=Path, default=Path("output"))
    parser.add_argument("--check", action="store_true", help="Only inspect RAM/GPU; no downloads or changes.")
    parser.add_argument("--download-only", action="store_true")
    parser.add_argument("--offline", action="store_true")
    parser.add_argument("--reference", type=Path, help="Russian reference for Chatterbox; default is qwen-warm.raw.wav.")
    args = parser.parse_args()
    if args.check:
        print(json.dumps(check_resources(args.engine), ensure_ascii=False))
        return
    args.output.mkdir(parents=True, exist_ok=True)
    if not args.offline and shutil.disk_usage(args.output).free < 10 * 1024 ** 3:
        parser.error("Reserve at least 10 GiB disk space before downloading this model.")
    os.environ.setdefault("HF_HUB_DISABLE_TELEMETRY", "1")
    snapshot, model_metadata = download(args.engine, args.output, args.offline)
    if args.download_only:
        print(json.dumps({"snapshot": snapshot, **model_metadata}))
        return
    reference = args.reference or args.output / "qwen-warm.raw.wav"
    if args.engine == "chatterbox" and not reference.is_file():
        parser.error("Chatterbox needs a Russian reference; generate Qwen samples first or pass --reference.")
    names = [f"{args.engine}-{style}" for style in (STYLES if args.engine == "qwen" else ["natural", "expressive"])]
    if any((args.output / f"{name}.wav").exists() for name in names):
        parser.error("Samples already exist; choose a fresh --output directory.")
    resources = check_resources(args.engine)
    os.environ["CUDA_VISIBLE_DEVICES"] = resources["gpu"]["uuid"]
    import torch

    if not torch.cuda.is_available():
        raise RuntimeError("CUDA unavailable in this environment; there is no CPU fallback.")
    torch.set_num_threads(2)
    started = time.monotonic()
    if args.engine == "qwen":
        from qwen_tts import Qwen3TTSModel

        model = Qwen3TTSModel.from_pretrained(
            snapshot, device_map="cuda:0", dtype=torch.bfloat16 if torch.cuda.is_bf16_supported() else torch.float16,
            attn_implementation="sdpa", low_cpu_mem_usage=True,
        )
        for style, instruction in STYLES.items():
            torch.manual_seed(1062026)
            with torch.inference_mode():
                waves, rate = model.generate_voice_design(
                    text=TEXT, language="Russian", instruct=instruction, max_new_tokens=480,
                )
            save_sample(args.output, f"qwen-{style}", waves[0], rate, {
                **model_metadata, **resources, "instruction": instruction, "seed": 1062026,
                "seconds_since_load_start": round(time.monotonic() - started, 2), "license": "Apache-2.0",
            })
    else:
        from chatterbox.mtl_tts import ChatterboxMultilingualTTS

        model = ChatterboxMultilingualTTS.from_local(snapshot, device="cuda", t3_model="v3")
        for style, exaggeration in [("natural", 0.35), ("expressive", 0.6)]:
            torch.manual_seed(1062026)
            with torch.inference_mode():
                wave = model.generate(TEXT, language_id="ru", audio_prompt_path=str(reference), exaggeration=exaggeration, cfg_weight=0.5)
            save_sample(args.output, f"chatterbox-{style}", wave.detach().cpu().numpy(), model.sr, {
                **model_metadata, **resources, "reference": str(reference), "exaggeration": exaggeration,
                "seed": 1062026, "seconds_since_load_start": round(time.monotonic() - started, 2), "license": "MIT",
            })
    (args.output / "script.txt").write_text(TEXT + "\n")


if __name__ == "__main__":
    main()
