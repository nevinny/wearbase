"""Local Hugging Face video generation; no inference API, uploads or paid fallback."""

import argparse
import json
import os
from pathlib import Path
import subprocess
import time

os.environ.setdefault("PYTORCH_ENABLE_MPS_FALLBACK", "1")

ROOT = Path(__file__).resolve().parents[2]
BASE = "emilianJR/epiCRealism"
BASE_REVISION = "6522cf856b8c8e14638a0aaa7bd89b1b098aed17"
MOTION = "ByteDance/AnimateDiff-Lightning"
MOTION_REVISION = "027c893eec01df7330f5d4b733bc9485ee02e8b2"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("episode", choices=[e["id"] for e in json.loads((ROOT / "config/social/wardrobe_reels.json").read_text())["episodes"]])
    parser.add_argument("--output", type=Path, default=ROOT / "var/wardrobe-reels/clips")
    parser.add_argument("--width", type=int, default=256)
    parser.add_argument("--height", type=int, default=448)
    parser.add_argument("--frames", type=int, default=16)
    parser.add_argument("--seed", type=int, default=1052026)
    parser.add_argument("--prompt", help="Override the visual prompt for a local creative test.")
    parser.add_argument("--offline", action="store_true")
    args = parser.parse_args()
    if args.width % 8 or args.height % 8 or min(args.width, args.height) < 64 or not 8 <= args.frames <= 24:
        parser.error("Dimensions must be multiples of 8, at least 64; use 8–24 frames.")

    import torch
    from diffusers import AnimateDiffPipeline, EulerDiscreteScheduler, MotionAdapter
    from huggingface_hub import hf_hub_download
    from safetensors.torch import load_file

    device = "mps" if torch.backends.mps.is_available() else "cuda" if torch.cuda.is_available() else "cpu"
    dtype = torch.float32 if device == "cpu" else torch.float16
    episode = next(e for e in json.loads((ROOT / "config/social/wardrobe_reels.json").read_text())["episodes"] if e["id"] == args.episode)
    prompt = args.prompt or episode["prompt"]
    args.output.mkdir(parents=True, exist_ok=True)
    output = args.output / f"{args.episode}.mp4"
    if output.exists():
        parser.error(f"Refusing to replace {output}; choose a fresh output directory.")
    temporary_output = output.with_name(output.stem + ".partial.mp4")
    started = time.monotonic()
    print(f"Loading pinned Hugging Face weights on {device}. First run downloads models.", flush=True)
    adapter = MotionAdapter().to(dtype=dtype)
    weights = hf_hub_download(MOTION, "animatediff_lightning_4step_diffusers.safetensors", revision=MOTION_REVISION, local_files_only=args.offline)
    adapter.load_state_dict(load_file(weights))
    pipe = AnimateDiffPipeline.from_pretrained(BASE, revision=BASE_REVISION, motion_adapter=adapter, torch_dtype=dtype, use_safetensors=True, local_files_only=args.offline)
    pipe.scheduler = EulerDiscreteScheduler.from_config(pipe.scheduler.config, timestep_spacing="trailing", beta_schedule="linear")
    pipe.to(device)
    pipe.enable_attention_slicing()
    pipe.enable_vae_slicing()
    pipe.unet.enable_forward_chunking(chunk_size=1, dim=1)
    print(f"Generating {args.episode}: {args.width}x{args.height}, {args.frames} frames, 4 steps.", flush=True)
    result = pipe(prompt=prompt, guidance_scale=1.0, num_inference_steps=4, width=args.width, height=args.height, num_frames=args.frames, generator=torch.Generator("cpu").manual_seed(args.seed))
    subprocess.run([
        "ffmpeg", "-v", "error", "-y", "-f", "rawvideo", "-pixel_format", "rgb24",
        "-video_size", f"{args.width}x{args.height}", "-framerate", "8", "-i", "pipe:0",
        "-an", "-c:v", "libx264", "-pix_fmt", "yuv420p", "-movflags", "+faststart", str(temporary_output),
    ], input=b"".join(frame.convert("RGB").tobytes() for frame in result.frames[0]), check=True)
    temporary_output.replace(output)
    result.frames[0][len(result.frames[0]) // 2].save(args.output / f"{args.episode}-frame.png")
    metadata = {"base": BASE, "base_revision": BASE_REVISION, "motion": MOTION, "motion_revision": MOTION_REVISION, "license": "CreativeML OpenRAIL-M", "prompt": prompt, "seed": args.seed, "device": device, "width": args.width, "height": args.height, "frames": args.frames, "seconds_elapsed": round(time.monotonic() - started, 2), "kind": "synthetic_scene_not_product_capture"}
    output.with_suffix(".json").write_text(json.dumps(metadata, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({"output": str(output), **metadata}, ensure_ascii=False), flush=True)


if __name__ == "__main__":
    main()
