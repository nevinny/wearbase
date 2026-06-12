"""Information Gain — доля конкретики как прокси «новой ценности».

В методологии SEO Guide 4.9 (§01, §27) Information Gain — сильнейший фактор
ценности: контент должен нести НОВУЮ информацию (first-party данные, цифры,
кейсы, конкретные сущности), а не пересказ общеизвестного.

Полноценно «новизну относительно выдачи» офлайн измерить нельзя, поэтому модуль
считает ПРОКСИ: какая доля предложений содержит конкретику — числа, проценты,
суммы, даты, прямые цитаты, именованные сущности. Низкая доля = «вода»/пересказ.
"""
import re
from .textstats import sentences, words, detect_lang

CURRENCY = "$€₽£¥"
_NUM = re.compile(r"\d")
_NUM_TOKEN = re.compile(r"\d+(?:[.,]\d+)?\s?%?")
_QUOTE = re.compile(r"[«\"“][^»\"”]{6,}[»\"”]")
# Заглавное слово НЕ в начале предложения (грубый прокси именованной сущности).
_PROPER = re.compile(r"(?<=[\w,;:]) [A-ZА-ЯЁ][A-Za-zА-Яа-яЁё]{2,}")


def analyze(text, lang=None):
    lang = lang or detect_lang(text)
    sents = sentences(text)
    n = max(len(sents), 1)
    specific = 0
    data_points = 0
    for s in sents:
        has = False
        if _NUM.search(s):
            has = True
            data_points += len(_NUM_TOKEN.findall(s))
        if any(c in s for c in CURRENCY):
            has = True
        if _QUOTE.search(s):
            has = True
        if _PROPER.search(" " + s):
            has = True
        if has:
            specific += 1
    ratio = specific / n
    score = round(min(100.0, ratio * 175), 1)

    findings = []
    if n >= 5 and ratio < 0.20:
        findings.append({"level": "warn",
                         "msg": "Низкий Information Gain: лишь %.0f%% предложений с конкретикой" % (ratio * 100),
                         "detail": "Добавьте first-party данные, цифры, кейсы, конкретные сущности — это сильнейший фактор ценности (гайд §27)."})
    elif n >= 5 and ratio < 0.35:
        findings.append({"level": "info",
                         "msg": "Средний Information Gain: %.0f%% предложений с конкретикой" % (ratio * 100),
                         "detail": "Больше конкретных данных усилит ценность."})

    return {
        "name": "Information Gain (конкретика)",
        "score": score,
        "weight": 1.0,
        "metrics": {"specific_sentence_ratio": round(ratio, 2),
                    "data_points": data_points, "sentences": n},
        "findings": findings,
    }
