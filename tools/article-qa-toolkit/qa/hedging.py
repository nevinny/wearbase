"""«Вода» и hedging: слова, которые ослабляют утверждения и раздувают объём.

LLM, когда не уверена или тянет до нужного объёма, насыпает «возможно»,
«как правило», «в целом», "basically", "essentially". Высокая плотность таких
слов = размытый, неинформативный текст.
"""
from .textstats import words, detect_lang
from .lex import load_list, count_matches


def analyze(text, patterns_dir, lang=None):
    lang = lang or detect_lang(text)
    low = text.lower()
    wl = words(text)
    n_words = max(len(wl), 1)

    hedges = load_list(patterns_dir, "hedging_%s.txt" % lang)
    hits = count_matches(low, hedges)
    total = sum(c for _, c in hits)
    density = total / n_words * 100   # % от всех слов

    findings = []
    for ph, c in hits[:10]:
        if c >= 3:
            findings.append({"level": "info", "msg": "Вода: «%s» ×%d" % (ph, c), "detail": None})
    if density > 2.5:
        findings.append({"level": "warn",
                         "msg": "Высокая плотность «воды»: %.1f%% слов" % density,
                         "detail": "Уберите hedging-слова — текст станет конкретнее и короче."})

    score = round(max(0.0, 100.0 - max(0.0, density - 1.0) * 22), 1)

    return {
        "name": "Вода и hedging",
        "score": score,
        "weight": 1.0,
        "metrics": {"hedging_hits": total, "hedging_pct": round(density, 2)},
        "findings": findings,
    }
