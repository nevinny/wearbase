#!/usr/bin/env bash
set -euo pipefail
script_dir="$(cd "$(dirname "$0")" && pwd)"
if [[ "${1:-}" != "--server" ]]; then
    destination="${1:-llm}"
    [[ "$destination" =~ ^([a-zA-Z0-9_.-]+@)?[a-zA-Z0-9_.-]+$ ]] || { echo 'Expected SSH alias or [user@]host.' >&2; exit 2; }
    tar -C "$script_dir" -cf - qwen-server.sh qwen_voice.py voice_samples.py requirements-tts.txt | ssh -o ConnectTimeout=10 "$destination" '
        set -eu
        umask 077
        mkdir -p "$HOME/wearbase-qwen-tts"
        tar -xf - -C "$HOME/wearbase-qwen-tts"
        bash "$HOME/wearbase-qwen-tts/qwen-server.sh" --server
    '
    exit
fi
cd "$script_dir"
exec 9>install.lock
flock -n 9 || { echo 'Another Qwen installation is running.' >&2; exit 1; }
python3 - <<'PY'
import shutil
import sys
if not (3, 10) <= sys.version_info[:2] <= (3, 12):
    raise SystemExit('Python 3.10–3.12 with venv/pip is required.')
if shutil.disk_usage('.').free < 18 * 1024 ** 3:
    raise SystemExit('Reserve 18 GiB for the environment and model downloads.')
PY
export PIP_CACHE_DIR="$script_dir/pip-cache"
export OMP_NUM_THREADS=2
export MKL_NUM_THREADS=2
python3 -m venv .venv
.venv/bin/python -m pip install 'torch==2.6.0' 'torchaudio==2.6.0' --index-url https://download.pytorch.org/whl/cu124
.venv/bin/python -m pip install -r requirements-tts.txt
.venv/bin/python -m pip freeze > installed.txt
.venv/bin/python qwen_voice.py --setup
