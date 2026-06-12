"""Детектор «AI-почерка»: штампы, TIER-1/2 слова-маркеры, тире, повторы зачинов.

Сигналы, по которым ловят LLM-текст:
  * Шаблонные фразы-связки и вводные клише ("стоит отметить", "delve into" …).
  * TIER-1/2 слова-маркеры ИИ из методологии SEO Guide 4.9 (§05): частота
    `delve, tapestry, leverage, robust …` в 3–10× выше человеческой. Плотность
    считается по формуле гайда: (tier1×2 + tier2) / 1000 слов; пороги штрафа
    >8 → 12, >5 → 8, >2 → 4. Словарь и синонимы — в patterns/ai_blacklists.json.
  * Перебор с длинным тире «—»: модели расставляют его заметно чаще людей.
  * Конструкция «не только …, но и» / "not only … but also".
  * Одинаковые первые слова у многих предложений (модель «зацикливается»).

Чем больше плотность таких маркеров, тем ниже балл.
"""
import json
import os
import re
from collections import Counter

from .textstats import sentences, words, detect_lang
from .lex import load_list, count_matches

_OPENER_OK = {
    "ru": {"и", "а", "но", "это", "в", "на", "по", "для", "при", "если", "когда", "как"},
    "en": {"the", "a", "an", "this", "it", "in", "on", "for", "if", "when", "as", "but", "and"},
}


def _load_tiers(patterns_dir):
    path = os.path.join(patterns_dir, "ai_blacklists.json")
    try:
        with open(path, "r", encoding="utf-8") as fh:
            data = json.load(fh)
        return data.get("tier1", {}), data.get("tier2", {})
    except Exception:
        return {}, {}


def _count_word(low, word):
    return len(re.findall(r"(?<!\w)" + re.escape(word.lower()) + r"(?!\w)", low))


def analyze(text, patterns_dir, lang=None):
    lang = lang or detect_lang(text)
    low = text.lower()
    wl = words(text)
    n_words = max(len(wl), 1)
    per1k = 1000.0 / n_words

    cliches = load_list(patterns_dir, "ai_cliches_%s.txt" % lang)
    hits = count_matches(low, cliches)
    n_cliche = sum(c for _, c in hits)
    cliche_density = n_cliche * per1k

    # TIER-1/2 слова-маркеры ИИ (формула гайда SEO 4.9)
    tier1, tier2 = _load_tiers(patterns_dir)
    # без двойного счёта: single-word клише, совпадающие с TIER-словом, учитываем
    # только как TIER-маркер (а не ещё раз как штамп)
    _tier_set = set(tier1) | set(tier2)
    hits = [(ph, c) for ph, c in hits if ph not in _tier_set]
    n_cliche = sum(c for _, c in hits)
    cliche_density = n_cliche * per1k
    t1 = [(w, _count_word(low, w)) for w in tier1]
    t2 = [(w, _count_word(low, w)) for w in tier2]
    t1 = sorted([(w, c) for w, c in t1 if c], key=lambda x: -x[1])
    t2 = sorted([(w, c) for w, c in t2 if c], key=lambda x: -x[1])
    n_t1 = sum(c for _, c in t1)
    n_t2 = sum(c for _, c in t2)
    tier_density = (n_t1 * 2 + n_t2) * per1k
    if tier_density > 8:
        tier_pts = 12
    elif tier_density > 5:
        tier_pts = 8
    elif tier_density > 2:
        tier_pts = 4
    else:
        tier_pts = 0

    # длинное тире
    em = text.count("—") + text.count("–")
    em_density = em * per1k

    # «не только … но и» / «not only … but also»
    if lang == "ru":
        notonly = len(re.findall(r"не только\b.{0,80}?\bно и\b", low))
    else:
        notonly = len(re.findall(r"\bnot only\b.{0,80}?\bbut also\b", low))

    # повторяющиеся зачины предложений
    openers = Counter()
    for s in sentences(text):
        w = words(s)
        if w:
            openers[w[0].lower()] += 1
    repeated = [(w, c) for w, c in openers.most_common(6)
                if c >= 3 and w not in _OPENER_OK[lang]]

    findings = []
    for ph, c in hits[:10]:
        findings.append({"level": "warn", "msg": "Штамп: «%s» ×%d" % (ph, c), "detail": None})
    for w, c in (t1 + t2)[:10]:
        syn = tier1.get(w) or tier2.get(w)
        tier = "TIER-1" if w in tier1 else "TIER-2"
        findings.append({"level": "warn", "msg": "%s слово-маркер ИИ: «%s» ×%d" % (tier, w, c),
                         "detail": ("замените на «%s»" % syn) if syn else None})
    if tier_pts:
        findings.append({"level": "warn",
                         "msg": "Плотность TIER-маркеров = %.1f/1000 слов (формула гайда, штраф %d)" % (tier_density, tier_pts),
                         "detail": "Порог гайда: >8 критично, >5 много, >2 заметно."})
    if em_density > 8:
        findings.append({"level": "warn",
                         "msg": "Перебор с тире «—»: %d шт. (%.1f на 1000 слов)" % (em, em_density),
                         "detail": "LLM ставит длинное тире заметно чаще людей."})
    if notonly >= 2:
        findings.append({"level": "info",
                         "msg": "Конструкция «не только … но и» встречается %d раз" % notonly,
                         "detail": "Любимый оборот моделей — разнообразьте."})
    for w, c in repeated:
        findings.append({"level": "info",
                         "msg": "Повтор зачина: %d предложений начинаются со слова «%s»" % (c, w),
                         "detail": None})

    # score: штраф за клише + TIER-маркеры (формула гайда) + тире + повторы
    penalty = min(60, cliche_density * 18) + min(40, tier_pts * 3) \
        + min(15, max(0, em_density - 6) * 2) + min(15, sum(c for _, c in repeated) * 2)
    score = round(max(0.0, 100.0 - penalty), 1)

    return {
        "name": "AI-почерк (штампы и шаблоны)",
        "score": score,
        "weight": 2.0,
        "metrics": {
            "cliche_hits": n_cliche,
            "cliche_per_1000w": round(cliche_density, 2),
            "ai_tier1_hits": n_t1,
            "ai_tier2_hits": n_t2,
            "ai_tier_per_1000w": round(tier_density, 2),
            "tier_points": tier_pts,
            "em_dashes": em,
            "em_dash_per_1000w": round(em_density, 2),
            "not_only_but_also": notonly,
            "repeated_openers": dict(repeated),
        },
        "findings": findings,
    }
