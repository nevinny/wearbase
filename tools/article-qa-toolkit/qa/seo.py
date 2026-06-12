"""SEO-проверки: переспам ключей, частотное ядро, вхождение целевого ключа.

  * Частотное ядро: топ значимых слов (без стоп-слов) и их плотность.
  * Keyword stuffing: если какое-то слово занимает >3.5% — это переспам,
    риск пессимизации за неестественность.
  * Целевой ключ (--keyword): плотность и наличие в title, первом абзаце и
    подзаголовках (классические зоны релевантности).
"""
from collections import Counter
from .textstats import words, detect_lang
from .lex import load_list
from .structure import extract_headings, extract_title


def _stopwords(patterns_dir):
    sw = set(load_list(patterns_dir, "stopwords_ru.txt"))
    sw |= set(load_list(patterns_dir, "stopwords_en.txt"))
    return sw


def analyze(plaintext, raw, fmt, patterns_dir, keyword=None, lang=None):
    lang = lang or detect_lang(plaintext)
    sw = _stopwords(patterns_dir)
    toks = [w.lower() for w in words(plaintext)]
    n = max(len(toks), 1)
    content = [t for t in toks if t not in sw and len(t) > 2]
    freq = Counter(content)
    top = freq.most_common(15)

    findings = []
    penalty = 0
    for w, c in top:
        d = c / n * 100
        if d > 3.5:
            findings.append({"level": "warn",
                             "msg": "Переспам словом «%s»: %.1f%% (%d вхождений)" % (w, d, c),
                             "detail": "Снизьте частоту — выглядит неестественно для поисковика."})
            penalty += min(20, (d - 3.5) * 8)

    kw_metrics = {}
    if keyword:
        kw = keyword.lower().strip()
        low_plain = plaintext.lower()
        kc = low_plain.count(kw)
        # плотность по словам ключа
        kw_words = max(len(words(kw)), 1)
        kd = kc * kw_words / n * 100
        first_para = " ".join(toks[:100])
        in_first = kw in " ".join(words(plaintext.lower())[:100]) or kw in low_plain[:600]
        title = (extract_title(raw, fmt) or "").lower()
        heads = " ".join(t for _, t in extract_headings(raw, fmt)).lower()
        in_title = kw in title
        in_heads = kw in heads
        kw_metrics = {"keyword": keyword, "count": kc, "density_pct": round(kd, 2),
                      "in_title": in_title, "in_first_100w": in_first, "in_headings": in_heads}
        if kc == 0:
            findings.append({"level": "warn", "msg": "Целевой ключ «%s» не встречается в тексте" % keyword, "detail": None}); penalty += 25
        else:
            if kd > 4:
                findings.append({"level": "warn", "msg": "Переспам ключа «%s»: %.1f%%" % (keyword, kd), "detail": None}); penalty += 12
            elif kd < 0.3:
                findings.append({"level": "info", "msg": "Ключ «%s» редок: %.1f%% (можно усилить)" % (keyword, kd), "detail": None}); penalty += 4
            if not in_title and (extract_title(raw, fmt)):
                findings.append({"level": "info", "msg": "Ключа нет в title", "detail": None}); penalty += 5
            if not in_first:
                findings.append({"level": "info", "msg": "Ключа нет в первом абзаце", "detail": None}); penalty += 4
            if not in_heads and extract_headings(raw, fmt):
                findings.append({"level": "info", "msg": "Ключа нет ни в одном подзаголовке", "detail": None}); penalty += 3

    score = round(max(0.0, 100.0 - penalty), 1)
    return {
        "name": "SEO (ключи и плотность)",
        "score": score,
        "weight": 1.0,
        "metrics": {"top_keywords": [(w, round(c / n * 100, 2)) for w, c in top[:10]],
                    "target": kw_metrics},
        "findings": findings,
    }
