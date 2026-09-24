#!/usr/bin/env python3
# usage: bench.py <label> [json-options]   — 1 warmup + 3 runs, пишет в ~/llmbench/results.jsonl
import json, sys, time, uuid, subprocess, urllib.request, os
label = sys.argv[1]; extra = (json.loads(sys.argv[2]) or {}) if len(sys.argv) > 2 else {}
base = open(os.path.expanduser('~/llmbench/prompt.txt')).read()
def sh(c): return subprocess.run(c, shell=True, capture_output=True, text=True).stdout.strip()
def call(nonce):
    opts = {"temperature": 0, "seed": 42, "num_predict": 400, "num_ctx": 8192}; opts.update(extra)
    body = {"model": "gemma4:26b", "prompt": f"[run {nonce}]\n" + base, "stream": False, "options": opts, "think": False}
    req = urllib.request.Request("http://127.0.0.1:11434/api/generate", json.dumps(body).encode(), {"Content-Type": "application/json"})
    t = time.time(); r = json.load(urllib.request.urlopen(req, timeout=1800)); r["wall"] = time.time() - t; return r
env = {
  "label": label, "ts": time.strftime("%Y-%m-%dT%H:%M:%S"), "options": extra,
  "service_env": sh("systemctl show ollama -p Environment --value"),
  "gpus_before": sh("nvidia-smi --query-gpu=index,name,memory.used,memory.total,power.limit,clocks.max.sm,clocks.sm,temperature.gpu,pcie.link.gen.current,pcie.link.width.current --format=csv,noheader"),
  "ram_before": sh("free -m | sed -n 2p"),
}
call("warmup-" + uuid.uuid4().hex[:8])
env["ps"] = sh("curl -s localhost:11434/api/ps")
env["gpus_loaded"] = sh("nvidia-smi --query-gpu=index,memory.used,temperature.gpu,power.draw --format=csv,noheader")
runs = []
for i in range(3):
    r = call(uuid.uuid4().hex)
    runs.append({"prompt_tokens": r["prompt_eval_count"], "prefill_tps": r["prompt_eval_count"] / (r["prompt_eval_duration"] / 1e9),
                 "gen_tokens": r["eval_count"], "decode_tps": r["eval_count"] / (r["eval_duration"] / 1e9), "wall": r["wall"],
                 "load_s": r.get("load_duration", 0) / 1e9, "text_head": r["response"][:300]})
env["runs"] = runs
env["gpus_after"] = sh("nvidia-smi --query-gpu=index,temperature.gpu,power.draw,clocks.sm --format=csv,noheader")
env["ram_after"] = sh("free -m | sed -n 2p")
env["journal_load"] = sh("journalctl -u ollama --since '-30min' --no-pager | grep -E 'offloaded|flash_attn|mmap|n_batch|n_ubatch|CUDA[0-9] model buffer' | tail -12 | cut -c40-")
open(os.path.expanduser('~/llmbench/results.jsonl'), 'a').write(json.dumps(env, ensure_ascii=False) + "\n")
m = lambda k: sum(x[k] for x in runs) / len(runs)
print(f"{label}: prefill {m('prefill_tps'):.0f} t/s, decode {m('decode_tps'):.1f} t/s, wall {m('wall'):.1f}s, prompt {runs[0]['prompt_tokens']} tok")
