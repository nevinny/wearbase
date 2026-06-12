"""AVI — Article Value Index (композит 0–100) из SEO Guide 4.9 (§27).

В гайде AVI существует в двух вариантах:
  * AVI-audit (6 осей) — для живой страницы, тянет GA4 (Engagement, Scroll depth,
    Micro-conversions). Офлайн недоступен.
  * AVI-gate (5 осей, pre-publish) — без GA4: SpamBrain, Reader Value,
    Human-likeness, Readability (FRES/ARI), E-E-A-T.

Этот модуль считает AVI-gate (5 осей), т.к. работает офлайн до публикации.
Оси GA4 помечаются N/A. Полосы интерпретации: ≥80 leader, 70–79 sweet spot,
<70 — на доработку.
"""

# Веса 5-осевого pre-publish-варианта (Σ = 1.0); перераспределены с 6-осевого
# audit-варианта, где Engagement/Scroll/Micro-conversions требуют live-данных.
WEIGHTS = {
    "SpamBrain": 0.20,
    "Reader Value": 0.25,
    "Human-likeness": 0.20,
    "Readability": 0.15,
    "E-E-A-T": 0.20,
}


def compute(base, gate, lang="ru"):
    gm = gate.get("metrics", {})
    sb = gm.get("spambrain", 5.0)
    rv = gm.get("reader_value", 5.0)
    hl = gm.get("human_likeness", 5.0)

    rd = base.get("readability", {})
    read_score = rd.get("score")
    read_score = float(read_score) if read_score is not None else 50.0

    ig = base.get("infogain", {})
    ig_score = ig.get("score")
    ig_score = float(ig_score) if ig_score is not None else 50.0
    fact = base.get("factuality", {})
    fact_score = fact.get("score")
    fact_score = float(fact_score) if fact_score is not None else 60.0
    eeat = max(0.0, min(100.0, ig_score * 0.7 + fact_score * 0.3))

    axes = {
        "SpamBrain": sb * 10,
        "Reader Value": rv * 10,
        "Human-likeness": hl * 10,
        "Readability": read_score,
        "E-E-A-T": eeat,
    }
    avi = round(sum(axes[k] * WEIGHTS[k] for k in axes), 1)

    if avi >= 80:
        band, col = "Leader (≥80)", "green"
    elif avi >= 70:
        band, col = "Sweet Spot (70–79)", "green"
    elif avi >= 55:
        band, col = "Требует доработки (<70)", "yellow"
    else:
        band, col = "Слабо (<55) — переписать", "red"

    findings = [{"level": "info",
                 "msg": "AVI-gate = %.1f/100 — %s" % (avi, band),
                 "detail": "5 осей (offline). GA4 Engagement / Scroll depth / Micro-conversions = N/A (нужны live-данные)."}]
    for k in ("SpamBrain", "Reader Value", "Human-likeness", "Readability", "E-E-A-T"):
        findings.append({"level": "info",
                         "msg": "   %-14s %5.0f/100  (вес %.0f%%)" % (k, axes[k], WEIGHTS[k] * 100),
                         "detail": None})

    return {
        "name": "AVI (Article Value Index)",
        "score": avi,
        "weight": 0.0,   # производный индекс — не входит в средневзвешенную
        "metrics": {
            "avi": avi,
            "band": band,
            "mode": "gate (5 осей, offline)",
            "axes": {k: round(v, 1) for k, v in axes.items()},
            "na_axes": ["GA4 Engagement", "Scroll depth", "Micro-conversions"],
            "banner": "AVI: %.1f/100 — %s" % (avi, band),
            "banner_color": col,
        },
        "findings": findings,
    }
