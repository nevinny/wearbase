# Мониторинг LLM-рига (Grafana + Prometheus + GPU exporter)

Статус на 2026-09-25. Файл не коммитить (по просьбе автора задачи).

## Что где

| Компонент | Хост | Порт | Как запущен |
|---|---|---|---|
| Grafana 13.2.2 | Mac (dev-машина) | 3300 (не 3000 — занят чужим `next-server`, PID процесса другого проекта `drugs/frontend`) | вручную, фоновый процесс (`nohup ... &`, disowned) — см. «Известная проблема» ниже |
| Prometheus 3.11.3 | риг, 192.168.2.43 / Tailscale `wearbase-llm` | 9090 | systemd `prometheus.service` (как было) |
| node_exporter | риг | 9100 | systemd (как было) |
| nvidia_gpu_exporter 1.15.1 | риг | 9835 (только 127.0.0.1, наружу не торчит) | systemd `nvidia_gpu_exporter.service`, новый |

Grafana слушает **127.0.0.1:3300** (не 0.0.0.0) — открыт только на самом Mac, наружу не торчит.

URL: **http://localhost:3300** (не 3000 — порт занят, см. ниже).

Логин по умолчанию: **admin / admin** — смени при первом входе.

## Как поменять IP рига (единственное место)

Адрес Prometheus для Grafana задан **только** в одном файле:

```
/opt/homebrew/etc/grafana/provisioning/datasources/prometheus.yml
```
поле `url:`. Сейчас там **Tailscale-адрес** рига (`http://100.102.162.120:9090`, узел `wearbase-llm`), а не LAN-адрес `192.168.2.43` из задания — см. «Отклонение от задания» ниже. После правки нужно перезапустить Grafana, чтобы она перечитала provisioning datasources (на лету не подхватывает). Штатный `brew services restart grafana` **не работает на этой машине** (см. ниже) — перезапускай вручную:
```bash
pkill -f "grafana server"
nohup /opt/homebrew/opt/grafana/bin/grafana server \
  --config /opt/homebrew/etc/grafana/grafana.ini \
  --homepath /opt/homebrew/opt/grafana/share/grafana \
  --packaging=brew \
  cfg:default.paths.logs=/opt/homebrew/var/log/grafana \
  cfg:default.paths.data=/opt/homebrew/var/lib/grafana \
  cfg:default.paths.plugins=/opt/homebrew/var/lib/grafana/plugins \
  > /opt/homebrew/var/log/grafana/grafana-manual.out 2>&1 < /dev/null &
disown
```

## Отклонение от задания №1: IP датасорса — Tailscale, а не 192.168.2.43

Задание просило `http://192.168.2.43:9090`. Порт 9090 на рige **не был открыт в ufw** (default-deny, только 11434/8088/1080/11435/OpenSSH разрешены) — прямой LAN-путь с Mac до Prometheus не работал (connection timeout). У рига уже поднят Tailscale (узел `wearbase-llm`, `100.102.162.120`), и Mac тоже в той же tailnet — путь `100.102.162.120:9090` уже работал **без каких-либо правок firewall**. Это и безопаснее (никаких новых дырок в ufw), и соответствует правилу инфры «Tailscale для внутренней связи, не открывать внутренние порты наружу». Использован Tailscale-адрес; **ufw на риге не трогал**.

Если понадобится LAN-путь вместо Tailscale — придётся добавить на риге `sudo ufw allow from 192.168.2.0/24 to any port 9090 proto tcp` и поменять `url` в datasource-файле на `http://192.168.2.43:9090`.

## Отклонение от задания №2: порт Grafana — 3300, не 3000

Порт 3000 на Mac занят чужим Next.js dev-сервером (`/Volumes/SAMSUNG-origin/Users/zyablik/work/drugs/frontend`, PID менялся между запусками). Трогать/убивать его не стал. Порт Grafana задан в `/opt/homebrew/etc/grafana/grafana.ini` (`[server] http_port = 3300`, `http_addr = 127.0.0.1`). Если освободишь 3000 сам — поменяй `http_port` обратно и перезапусти Grafana.

## Известная проблема: `brew services start grafana` не работает на этой машине

`brew services start grafana` падает: `launchctl bootstrap gui/503 ... Input/output error (5)`, стабильно (проверено несколько раз, в т.ч. с освобождённым портом 3300 — не конфликт порта). Причина **не установлена до конца**: сессия launchd — обычная Aqua (`launchctl managername` → `Aqua`, не SSH/агент), и другие пользовательские LaunchAgent'ы из того же `~/Library/LaunchAgents` на том же внешнем томе (`com.zyablik.tg-agent-router`, `tg-camp-photos`, `tg-release-watch` и др.) в этой сессии загружены и работают — значит версия «весь launchd на внешнем томе сломан» не подтверждается. При этом `brew services list` показывает **все** homebrew-сервисы (`httpd`, `postgresql@14/16`, `php`) как `none` (не запущены), но это могло быть их обычным состоянием «выключены», а не следствием той же поломки — не проверял целенаправленно (не стал трогать чужие сервисы). Для полной диагностики нужен `sudo launchctl bootstrap` с паролем (в этой сессии sudo без пароля недоступен на Mac, в отличие от рига) — не выполнял, задача не про это.

Обошёл через ручной фоновый запуск:
```bash
nohup /opt/homebrew/opt/grafana/bin/grafana server \
  --config /opt/homebrew/etc/grafana/grafana.ini \
  --homepath /opt/homebrew/opt/grafana/share/grafana \
  --packaging=brew \
  cfg:default.paths.logs=/opt/homebrew/var/log/grafana \
  cfg:default.paths.data=/opt/homebrew/var/lib/grafana \
  cfg:default.paths.plugins=/opt/homebrew/var/lib/grafana/plugins \
  > /opt/homebrew/var/log/grafana/grafana-manual.out 2>&1 < /dev/null &
disown
```
Процесс живёт, но **не переживёт перезагрузку Mac** и не автостартует при логине (в отличие от штатного `brew services`). Если понадобится автостарт — нужно чинить launchd/том (вне рамок этой задачи) или добавить команду выше в существующий крон-диспетчер (`com.wearbase.cron.plist` / `bin/mac-rag-start.sh`-подобный лаунчер).

Логи: `/opt/homebrew/var/log/grafana/grafana.log` (и `grafana-manual.out` для stdout/stderr самого процесса).

## Provisioning (файлы, не клики в UI)

```
/opt/homebrew/etc/grafana/provisioning/
├── datasources/prometheus.yml       # datasource "Prometheus (rig)", uid=prometheus-rig, isDefault
└── dashboards/
    ├── dashboards.yml                # provider "rig-dashboards", папка "Rig"
    └── json/
        ├── node-exporter-full.json   # grafana.com id 1860, как есть (без правок)
        └── nvidia-gpu-exporter.json  # grafana.com id 14574, как есть (без правок)
```

Оба дашборда используют **template-переменную типа "datasource"** (`ds_prometheus` / `datasource`), а не старый формат `${DS_PROMETHEUS}`/`__inputs` — поэтому JSON не пришлось патчить sed'ом: при единственном провизионированном Prometheus-датасорсе переменная резолвится в него автоматически (`isDefault: true` в datasource.yml + `query: "prometheus"` в переменной, единственный кандидат).

`[paths] provisioning` в `grafana.ini` явно указан на `/opt/homebrew/etc/grafana/provisioning` (а не relative `conf/provisioning` внутри Cellar — тот вайпается при `brew upgrade`).

## Риг: nvidia_gpu_exporter

- Бинарник: `/usr/local/bin/nvidia_gpu_exporter` (release v1.15.1, вариант **не-nvml**, т.е. дёргает `nvidia-smi`, как и просили)
- systemd: `/etc/systemd/system/nvidia_gpu_exporter.service` — `DynamicUser=yes`, слушает **только 127.0.0.1:9835**, `MemoryMax=128M`, `Restart=on-failure`
- В `/etc/prometheus/prometheus.yml` добавлен job `nvidia_gpu_exporter` (`scrape_interval: 15s`, target `localhost:9835`). Бэкап конфига до правки: `/etc/prometheus/prometheus.yml.bak.20260925112807`
- `promtool check config` — OK; применено через `systemctl restart prometheus` (не ollama — по заданию можно). Рестарт занял ~1 минуту (WAL replay 1017 сегментов — на риге слабый диск/CPU, это нормально, не баг)
- Ollama/бенч моделей не трогал, `/etc/systemd/system/ollama.service.d/` не трогал, GPU нагрузку не создавал (только `nvidia-smi` через экспортер)

## Проверки (пройдены)

- `curl http://localhost:3300/api/health` → `{"database":"ok", ...}`
- Prometheus targets на риге: `node_exporter`, `prometheus`, `nvidia_gpu_exporter` — все `health: up`
- `nvidia_smi_memory_used_bytes` на риге отдаёт 5 серий (по числу GPU: 2×RTX 2060 SUPER, RTX A4000, 2×CMP 30HX)
- С Mac через Tailscale: `node_memory_MemAvailable_bytes` → 1 серия, `nvidia_smi_memory_used_bytes` → 5 серий
- Через саму Grafana (`POST /api/ds/query`, датасорс `prometheus-rig`) — оба метрика возвращают данные (5 фреймов для GPU, 1 для памяти)
- Оба дашборда видны в Grafana API (`/api/search?type=dash-db`): «Node Exporter Full» (`/d/rYdddlPWk/node-exporter-full`), «Nvidia GPU Metrics» (`/d/vlvPlrgnk/nvidia-gpu-metrics`), в папке `Rig`
- Панели дашбордов вживую (в браузере) не смотрел глазами — только через API; визуально стоит открыть и проверить рендер хотя бы раз
- `lastScrapeDuration` job'а `nvidia_gpu_exporter` ≈ 1.5с (nvidia-smi на слабом CPU рига небыстрый) — укладывается в дефолтный `scrape_timeout: 10s`, но заметно медленнее node_exporter (0.04с) и самого Prometheus (0.02с). Если под нагрузкой бенча вырастет ближе к 10с — поднять `scrape_timeout` для этого job'а явно в конфиге
- Ручной процесс Grafana (`nohup ... & disown`) реально пережил завершение породившего его bash-вызова: `ps -o pid,ppid` показывает `PPID=1` (усыновлён launchd/init) — переживёт закрытие текущей сессии агента, но **не** переживёт перезагрузку Mac (см. TODO)

## Что не сделано / TODO

- Автостарт Grafana при перезагрузке Mac (упирается в системную проблему launchd + внешний том, см. выше)
- Смена пароля admin/admin — не сделал, оставил как есть по умолчанию
- Текстовый коллектор `~/llm-mem.log` — пропущен по заданию (п.3, "не нужен")
