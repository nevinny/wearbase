#!/usr/bin/env bash
# Run from the Mac; SSH requests credentials interactively, never through arguments/files.
set -euo pipefail
script_dir="$(cd "$(dirname "$0")" && pwd)"

if [[ "${1:-}" != "--server" ]]; then
    destination="${1:-zyablik@192.168.2.43}"
    if [[ ! "$destination" =~ ^[a-zA-Z0-9_.-]+@[a-zA-Z0-9_.-]+$ ]]; then
        echo 'Expected user@hostname or user@IPv4.' >&2
        exit 2
    fi
    run_id="$(date -u +%Y%m%dT%H%M%SZ)-$$"
    local_output="$script_dir/../../var/wardrobe-reels/voices-$run_id"
    mkdir -p "$local_output"
    run_status=0
    tar -C "$script_dir" -cf - voice_samples.py voice-server.sh | ssh -o ConnectTimeout=10 "$destination" '
        set -eu
        umask 077
        mkdir -p "$HOME/wearbase-tts-pilot"
        tar -xf - -C "$HOME/wearbase-tts-pilot"
        bash "$HOME/wearbase-tts-pilot/voice-server.sh" --server
    ' || run_status=$?
    # Keep successful Qwen takes even if the subsequent Chatterbox job fails.
    if [[ "$run_status" -ne 255 ]]; then
        if scp -r "$destination:wearbase-tts-pilot/output/." "$local_output/"; then
            echo "Downloaded pilot output (inspect JSON review status): $local_output"
        else
            echo 'Output was not downloaded. Check the remote output/ folder.' >&2
            [[ "$run_status" -ne 0 ]] || run_status=1
        fi
    fi
    exit "$run_status"
fi

cd "$script_dir"
command -v python3 >/dev/null
command -v git >/dev/null
command -v flock >/dev/null
exec 9>voice-pilot.lock
flock -n 9 || { echo 'Another voice pilot is running.' >&2; exit 1; }
if [[ -e output/qwen-warm.wav || -e output/chatterbox-natural.wav ]]; then
    echo 'Previous samples exist in output/. Preserve them and choose a fresh pilot folder for another take.' >&2
    exit 1
fi
# This lock only serializes this pilot. It does not reserve resources from Ollama.
python3 voice_samples.py qwen --check
python3 - <<'PY'
import shutil
import sys
if not (3, 10) <= sys.version_info[:2] <= (3, 12):
    raise SystemExit('Use Python 3.10–3.12 for the pilot environments.')
if shutil.disk_usage('.').free < 20 * 1024 ** 3:
    raise SystemExit('Reserve 20 GiB free disk space for both environments and models.')
PY

export HF_HOME="$script_dir/model-cache"
export PIP_CACHE_DIR="$script_dir/pip-cache"
export HF_HUB_DISABLE_TELEMETRY=1
export OMP_NUM_THREADS=2
export MKL_NUM_THREADS=2
export TOKENIZERS_PARALLELISM=false

if [[ ! -e .venv-qwen/ready ]]; then
    python3 -m venv .venv-qwen
    .venv-qwen/bin/python -m pip install 'torch==2.6.0' 'torchaudio==2.6.0' --index-url https://download.pytorch.org/whl/cu124
    .venv-qwen/bin/python -m pip install 'qwen-tts==0.1.1' 'pyloudnorm==0.1.1' 'numpy<2'
    .venv-qwen/bin/python -m pip freeze > qwen-installed.txt
    touch .venv-qwen/ready
fi
if [[ ! -e .venv-chatterbox/ready ]]; then
    python3 -m venv .venv-chatterbox
    .venv-chatterbox/bin/python -m pip install 'torch==2.6.0' 'torchaudio==2.6.0' --index-url https://download.pytorch.org/whl/cu124
    # V3 API is present in current upstream; record the exact installed commit.
    .venv-chatterbox/bin/python -m pip install 'git+https://github.com/resemble-ai/chatterbox.git@master'
    .venv-chatterbox/bin/python -m pip freeze > chatterbox-installed.txt
    .venv-chatterbox/bin/python - <<'PY'
import inspect
from chatterbox.mtl_tts import ChatterboxMultilingualTTS
assert 't3_model' in inspect.signature(ChatterboxMultilingualTTS.from_local).parameters, 'Chatterbox V3 API is required.'
PY
    touch .venv-chatterbox/ready
fi

mkdir -p output
cp qwen-installed.txt chatterbox-installed.txt output/
.venv-qwen/bin/python voice_samples.py qwen --download-only
.venv-chatterbox/bin/python voice_samples.py chatterbox --download-only
.venv-qwen/bin/python voice_samples.py qwen --offline
.venv-chatterbox/bin/python voice_samples.py chatterbox --offline
