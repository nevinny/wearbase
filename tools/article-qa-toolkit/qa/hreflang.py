"""Hreflang / isReal — проверка реальности перевода.

Из методологии SEO Guide 4.9 (§07, кейс A2): массовые «фейковые переводы»
(API вернул оригинал / EN-fallback вместо перевода) создают дубли и режут
индексацию. Правило: hreflang / «Latest Stories» / sitemap — только для
`isReal=true`. Дисциплина проверки:
  1. язык контента совпадает с заявленным (`--lang`);
  2. при сравнении с оригиналом (`--compare`): similarity < 0.70 (иначе API
     вернул оригинал) и определённый язык ≠ языку оригинала.

Определение языка — грубое (кириллица/латиница из textstats), достаточно для
ru/en. Без `--lang`/`--compare` модуль ничего не проверяет (score = n/a).
"""
import difflib
from .textstats import detect_lang, words


def analyze(text, lang=None, compare_text=None, declared_lang=None):
    detected = detect_lang(text)
    findings = []
    is_real = True
    score = None
    metrics = {"detected_lang": detected}

    declared = declared_lang if declared_lang in ("ru", "en") else None

    if declared and detected != declared:
        is_real = False
        findings.append({"level": "warn",
                         "msg": "Заявленный язык '%s' ≠ определённый '%s'" % (declared, detected),
                         "detail": "Похоже на непереведённый контент или EN-fallback."})

    if compare_text is not None:
        src_lang = detect_lang(compare_text)
        a = " ".join(w.lower() for w in words(text))
        b = " ".join(w.lower() for w in words(compare_text))
        sim = difflib.SequenceMatcher(None, a, b).ratio()
        metrics["similarity_to_source"] = round(sim, 3)
        metrics["source_lang"] = src_lang
        if sim > 0.70:
            is_real = False
            findings.append({"level": "error",
                             "msg": "Перевод слишком похож на оригинал: similarity = %.2f (>0.70)" % sim,
                             "detail": "Вероятно, API вернул оригинал вместо перевода → isReal=false."})
        if detected == src_lang and declared and declared != src_lang:
            is_real = False
            findings.append({"level": "error",
                             "msg": "Язык контента совпадает с языком оригинала (%s) — перевода не произошло" % src_lang,
                             "detail": None})
        score = 100.0 if is_real else 0.0
    elif declared:
        # язык совпал, сравнения с оригиналом не было — содержательной проверки нет,
        # поэтому не раздуваем общий балл (None пропускается из средневзвешенной);
        # 0 ставим только при реальном несоответствии языка.
        score = None if is_real else 0.0
    else:
        findings.append({"level": "info",
                         "msg": "Проверка hreflang/isReal не запущена — укажите --lang и/или --compare <оригинал>",
                         "detail": None})

    metrics["is_real"] = is_real
    return {
        "name": "Hreflang / isReal",
        "score": score,
        "weight": 1.0,
        "metrics": metrics,
        "findings": findings,
    }
