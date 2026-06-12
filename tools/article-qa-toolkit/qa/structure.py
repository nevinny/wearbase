"""Структура документа: заголовки, иерархия, абзацы, title/description.

Проверяет то, что важно и для читателя, и для SEO:
  * Ровно один H1.
  * Иерархия заголовков без «прыжков» (H2 -> H4 мимо H3).
  * Не слишком длинные абзацы (стена текста).
  * Длина <title> (50–60) и meta description (120–160) — если это HTML/MD c front-matter.

Экспортирует extract_headings / extract_title / extract_meta_description —
их переиспользует модуль SEO.
"""
import re
from .textstats import words

PARA_MAX_WORDS = 150


def extract_headings(raw, fmt):
    """Список (level:int, text:str) в порядке появления."""
    out = []
    if fmt == "html":
        for m in re.finditer(r"(?is)<h([1-6])[^>]*>(.*?)</h\1>", raw):
            txt = re.sub(r"(?s)<[^>]+>", " ", m.group(2))
            out.append((int(m.group(1)), re.sub(r"\s+", " ", txt).strip()))
    elif fmt == "md":
        for line in raw.splitlines():
            m = re.match(r"^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$", line)
            if m:
                out.append((len(m.group(1)), m.group(2).strip()))
    return out


def extract_title(raw, fmt):
    if fmt == "html":
        m = re.search(r"(?is)<title[^>]*>(.*?)</title>", raw)
        if m:
            return re.sub(r"\s+", " ", m.group(1)).strip()
    # YAML front-matter: title: ...
    m = re.search(r"(?ims)^\s*title\s*[:=]\s*[\"']?(.+?)[\"']?\s*$", raw[:600])
    if m:
        return m.group(1).strip()
    if fmt == "md":
        for line in raw.splitlines():
            mm = re.match(r"^\s{0,3}#\s+(.*)", line)
            if mm:
                return mm.group(1).strip()
    return None


def extract_meta_description(raw, fmt):
    if fmt == "html":
        m = re.search(r'(?is)<meta[^>]+name=["\']description["\'][^>]*content=["\'](.*?)["\']', raw)
        if not m:
            m = re.search(r'(?is)<meta[^>]+content=["\'](.*?)["\'][^>]*name=["\']description["\']', raw)
        if m:
            return re.sub(r"\s+", " ", m.group(1)).strip()
    m = re.search(r"(?ims)^\s*(?:description|meta_description)\s*[:=]\s*[\"']?(.+?)[\"']?\s*$", raw[:800])
    if m:
        return m.group(1).strip()
    return None


def _paragraphs(plaintext):
    return [p.strip() for p in re.split(r"\n\s*\n", plaintext) if p.strip()]


def analyze(plaintext, raw, fmt, lang=None):
    headings = extract_headings(raw, fmt)
    title = extract_title(raw, fmt)
    meta = extract_meta_description(raw, fmt)
    paras = _paragraphs(plaintext)

    findings = []
    penalty = 0

    if fmt in ("md", "html"):
        h1 = [h for h in headings if h[0] == 1]
        if len(h1) == 0:
            findings.append({"level": "warn", "msg": "Нет заголовка H1", "detail": None}); penalty += 12
        elif len(h1) > 1:
            findings.append({"level": "warn", "msg": "Несколько H1 (%d) — должен быть один" % len(h1), "detail": None}); penalty += 10
        # прыжки уровней
        prev = 0
        for lvl, txt in headings:
            if prev and lvl > prev + 1:
                findings.append({"level": "warn",
                                 "msg": "Прыжок уровней заголовков: H%d -> H%d («%s»)" % (prev, lvl, txt[:40]),
                                 "detail": "Не пропускайте уровни."})
                penalty += 4
            prev = lvl
        if len(headings) < 2 and len(words(plaintext)) > 300:
            findings.append({"level": "info", "msg": "Мало подзаголовков для такого объёма", "detail": "Разбейте текст на секции."}); penalty += 5

    long_paras = [p for p in paras if len(words(p)) > PARA_MAX_WORDS]
    if long_paras:
        findings.append({"level": "warn",
                         "msg": "Длинные абзацы: %d шт. (>%d слов)" % (len(long_paras), PARA_MAX_WORDS),
                         "detail": "Дробите на абзацы по 2–4 предложения."})
        penalty += min(15, len(long_paras) * 5)

    if title is not None:
        L = len(title)
        if L < 30 or L > 65:
            findings.append({"level": "info", "msg": "Длина title = %d символов (оптимум 50–60)" % L, "detail": title[:80]}); penalty += 3
    if meta is not None:
        L = len(meta)
        if L < 110 or L > 165:
            findings.append({"level": "info", "msg": "Длина meta description = %d символов (оптимум 120–160)" % L, "detail": None}); penalty += 3

    score = round(max(0.0, 100.0 - penalty), 1)
    return {
        "name": "Структура и оформление",
        "score": score,
        "weight": 1.0,
        "metrics": {
            "headings": len(headings),
            "paragraphs": len(paras),
            "long_paragraphs": len(long_paras),
            "title_len": len(title) if title else None,
            "meta_desc_len": len(meta) if meta else None,
        },
        "findings": findings,
        "_headings": headings,
        "_title": title,
    }
