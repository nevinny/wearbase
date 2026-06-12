"""Quality Gate — порог публикации SpamBrain / Reader Value / Human-likeness.

Реализует pre-publish gate из методологии SEO Guide 4.9 (§29, §27):
проходит только контент с **SpamBrain ≥ 7/10 И Reader Value ≥ 7/10 И
Human-likeness ≥ 8/10** (все три одновременно). В CI даёт exit 1 при провале.

Это не сторонний «ИИ-детектор» (тип ZeroGPT, ненадёжно), а внутренний
эвристический гейт: оси выводятся из уже посчитанных модулей (без повторного
прохода по тексту), поэтому жёсткий порог оправдан.

  * Human-likeness ← AI-почерк (TIER-маркеры/штампы) + штраф за неестественный ритм.
  * SpamBrain     ← переспам ключей + плотность TIER-маркеров + thin-content + «голый авторитет».
  * Reader Value  ← Information Gain + структура + читаемость + объём.
"""


def _score(base, key, default=50.0):
    m = base.get(key) or {}
    s = m.get("score")
    return float(s) if s is not None else float(default)


def compute(base, lang="ru"):
    ai = base.get("ai", {})
    rd = base.get("readability", {})
    seo_m = base.get("seo", {})
    fact = base.get("factuality", {})

    rdm = rd.get("metrics", {})
    words_n = rdm.get("words", 0) or 0
    cv = rdm.get("sentence_len_cv", 0.5)
    ease = rdm.get("flesch_reading_ease", 50.0)

    # --- Human-likeness (0..10) ---
    hl = _score(base, "ai", 50.0) / 10.0
    if cv < 0.35 or cv > 1.1:
        hl -= 1.5
    hl = max(0.0, min(10.0, hl))

    # --- SpamBrain (0..10) ---
    sb = 10.0
    top = (seo_m.get("metrics", {}) or {}).get("top_keywords", []) or []
    max_kd = max([d for _, d in top], default=0)
    if max_kd > 4:
        sb -= 2
    elif max_kd > 3.5:
        sb -= 1
    tier_density = (ai.get("metrics", {}) or {}).get("ai_tier_per_1000w", 0)
    if tier_density > 8:
        sb -= 2
    elif tier_density > 5:
        sb -= 1
    if words_n and words_n < 300:
        sb -= 3
    elif words_n and words_n < 1200:
        sb -= 1
    for f in fact.get("findings", []):
        if "без указания источника" in f.get("msg", ""):
            sb -= 1
            break
    sb = max(0.0, min(10.0, sb))

    # --- Reader Value (0..10) ---
    ig_score = _score(base, "infogain", 50.0)
    struct_score = _score(base, "structure", 50.0)
    length_factor = min(100.0, words_n / 1200.0 * 100.0) if words_n else 0.0
    rv = (ig_score * 0.4 + struct_score * 0.2 + ease * 0.2 + length_factor * 0.2) / 10.0
    rv = max(0.0, min(10.0, rv))

    passed = sb >= 7 and rv >= 7 and hl >= 8
    mark = "PASS ✓" if passed else "FAIL ✗"

    findings = [{
        "level": "info" if passed else "error",
        "msg": "Quality Gate: %s — SpamBrain %.1f (≥7) · Reader Value %.1f (≥7) · Human-likeness %.1f (≥8)" % (mark, sb, rv, hl),
        "detail": None if passed else "Не публиковать до устранения проваленных осей.",
    }]
    for name, val, thr in [("SpamBrain", sb, 7), ("Reader Value", rv, 7), ("Human-likeness", hl, 8)]:
        if val < thr:
            findings.append({"level": "warn",
                             "msg": "%s ниже порога: %.1f < %d" % (name, val, thr), "detail": None})

    score = round((sb + rv + hl) / 3.0 * 10, 1)
    banner = "GATE: %s   SB %.1f · RV %.1f · HL %.1f" % (mark, sb, rv, hl)
    return {
        "name": "Quality Gate (SB/RV/HL)",
        "score": score,
        "weight": 0.0,   # производный вердикт — не входит в средневзвешенную
        "metrics": {
            "spambrain": round(sb, 1),
            "reader_value": round(rv, 1),
            "human_likeness": round(hl, 1),
            "gate_passed": passed,
            "thresholds": "SB>=7, RV>=7, HL>=8",
            "banner": banner,
            "banner_color": "green" if passed else "red",
        },
        "findings": findings,
    }
