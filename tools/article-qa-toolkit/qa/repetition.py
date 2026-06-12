"""Повторы и лексическое разнообразие.

  * Повторяющиеся n-граммы (3–5 слов): модель часто переписывает одну мысль
    одними и теми же оборотами.
  * TTR (type-token ratio) и MATTR (скользящее окно) — насколько богат словарь.
    TTR зависит от длины текста, поэтому для оценки используем MATTR (окно 50),
    который от длины почти не зависит.

Низкое разнообразие + частые повторы n-грамм = «жвачка», типичная для LLM
при больших объёмах.
"""
from collections import Counter
from .textstats import words, detect_lang


def _ngrams(tokens, n):
    return [" ".join(tokens[i:i + n]) for i in range(len(tokens) - n + 1)]


def _mattr(tokens, window=50):
    if len(tokens) < window:
        return len(set(tokens)) / len(tokens) if tokens else 0.0
    ratios = []
    for i in range(len(tokens) - window + 1):
        win = tokens[i:i + window]
        ratios.append(len(set(win)) / window)
    return sum(ratios) / len(ratios)


def analyze(text, lang=None):
    lang = lang or detect_lang(text)
    toks = [w.lower() for w in words(text)]
    n = len(toks)
    types = len(set(toks))
    ttr = types / n if n else 0.0
    mattr = _mattr(toks, 50)

    repeats = {}
    for k in (3, 4, 5):
        counter = Counter(_ngrams(toks, k))
        repeats[k] = [(g, c) for g, c in counter.most_common(8) if c >= 3]

    findings = []
    total_rep = sum(c for lst in repeats.values() for _, c in lst)
    for k in (5, 4, 3):
        for g, c in repeats[k][:4]:
            findings.append({"level": "warn",
                             "msg": "Повтор фразы (%d слов) ×%d: «%s»" % (k, c, g),
                             "detail": None})
    if mattr < 0.65 and n >= 100:
        findings.append({"level": "warn",
                         "msg": "Бедный словарь: MATTR = %.2f (норма > 0.70)" % mattr,
                         "detail": "Много повторов одних и тех же слов. Добавьте синонимы и конкретику."})

    # score: 70% разнообразие (MATTR, норма 0.75) + штраф за повторы n-грамм
    div_score = max(0.0, min(100.0, mattr / 0.75 * 100))
    rep_penalty = min(40, total_rep * 3)
    score = round(max(0.0, 0.7 * div_score + 30 - rep_penalty), 1)
    score = max(0.0, min(100.0, score))

    return {
        "name": "Повторы и разнообразие",
        "score": score,
        "weight": 1.5,
        "metrics": {
            "ttr": round(ttr, 3),
            "mattr_w50": round(mattr, 3),
            "unique_words": types,
            "total_words": n,
            "repeated_ngrams": total_rep,
        },
        "findings": findings,
    }
