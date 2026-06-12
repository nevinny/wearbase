#!/usr/bin/env bash
# Демо-запуск тулкита на тестовой статье.
set -e
cd "$(dirname "$0")"
PY="$(command -v python3.12 || command -v python3)"
echo "Используется: $PY"
echo

# Базовая проверка + Quality Gate + AVI + сохранение JSON-отчёта.
# (--gate возвращает код 1 при провале порога; в демо не роняем скрипт через || true)
"$PY" check_article.py examples/sample_article.md \
    --keyword "выбрать парфюм" \
    --gate --avi \
    --json reports/sample_report.json || true

echo
echo "JSON-отчёт: reports/sample_report.json"
echo "Реальность перевода: $PY check_article.py ru.md --lang ru --compare en.md"
echo "Проверить ссылки:    $PY check_article.py examples/sample_article.md --check-links"
echo "LLM-судья:           OPENAI_API_KEY=... $PY check_article.py examples/sample_article.md --llm"
