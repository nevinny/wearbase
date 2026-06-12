"""Опциональный «LLM-судья»: вторая модель оценивает текст по рубрике.

Эвристики выше ловят форму (штампы, ритм, повторы). Смысловые вещи —
связность, фактологичность, польза, оригинальность, соответствие интенту —
надёжнее оценивает модель. Этот модуль шлёт статью в OpenAI-совместимый
эндпоинт (подходят OpenAI, vLLM, локальный сервер) и просит вернуть строгий JSON.

Конфигурируется переменными окружения (ничего не хардкодим):
    OPENAI_API_KEY    — ключ (обязателен, иначе модуль молча пропускается)
    OPENAI_BASE_URL   — базовый URL, по умолчанию https://api.openai.com/v1
    QA_LLM_MODEL      — модель, по умолчанию gpt-4o-mini

Зависимостей нет — HTTP через urllib.
"""
import os
import json
import urllib.request
import urllib.error

RUBRIC = ["factuality", "coherence", "originality", "helpfulness", "eeat"]

_SYSTEM = (
    "Ты — строгий редактор. Оцени текст статьи по шкале 0–100 по критериям: "
    "factuality (нет ли сомнительных/выдуманных утверждений), "
    "coherence (логичность и связность), "
    "originality (не шаблонно ли, не 'AI-вода' ли), "
    "helpfulness (отвечает ли на реальный запрос, есть ли польза и конкретика), "
    "eeat (экспертность/достоверность). "
    "Верни СТРОГО JSON без пояснений и markdown, формата: "
    '{"factuality":int,"coherence":int,"originality":int,"helpfulness":int,'
    '"eeat":int,"summary":"одно предложение","issues":["...","..."]}'
)


def available():
    return bool(os.environ.get("OPENAI_API_KEY"))


def analyze(plaintext, model=None, max_chars=12000):
    if not available():
        return {"name": "LLM-судья", "score": None, "weight": 0.0,
                "metrics": {"skipped": "OPENAI_API_KEY не задан"}, "findings": []}

    base = os.environ.get("OPENAI_BASE_URL", "https://api.openai.com/v1").rstrip("/")
    model = model or os.environ.get("QA_LLM_MODEL", "gpt-4o-mini")
    key = os.environ["OPENAI_API_KEY"]
    payload = {
        "model": model,
        "temperature": 0,
        "messages": [
            {"role": "system", "content": _SYSTEM},
            {"role": "user", "content": plaintext[:max_chars]},
        ],
    }
    req = urllib.request.Request(
        base + "/chat/completions",
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json", "Authorization": "Bearer " + key},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=90) as r:
            data = json.loads(r.read().decode("utf-8"))
        content = data["choices"][0]["message"]["content"].strip()
        content = content.strip("`")
        if content.lower().startswith("json"):
            content = content[4:].strip()
        verdict = json.loads(content)
    except Exception as e:
        return {"name": "LLM-судья", "score": None, "weight": 0.0,
                "metrics": {"error": str(e)[:200]},
                "findings": [{"level": "warn", "msg": "LLM-судья недоступен: %s" % str(e)[:120], "detail": None}]}

    scores = [float(verdict.get(k, 0)) for k in RUBRIC]
    overall = round(sum(scores) / len(scores), 1)
    findings = [{"level": "info", "msg": "Вердикт LLM: " + str(verdict.get("summary", "")), "detail": None}]
    for issue in (verdict.get("issues") or [])[:8]:
        findings.append({"level": "warn", "msg": "LLM: " + str(issue), "detail": None})

    return {
        "name": "LLM-судья (%s)" % model,
        "score": overall,
        "weight": 2.0,
        "metrics": {k: verdict.get(k) for k in RUBRIC},
        "findings": findings,
    }
