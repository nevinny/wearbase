"""Читаемость и ритм текста.

Метрики:
  * Flesch Reading Ease и Flesch–Kincaid Grade. Для русского используются
    коэффициенты адаптации Оборневой (2006), для английского — классические.
  * Доля слишком длинных предложений.
  * "Burstiness" — коэффициент вариации длины предложений. У человека ритм
    рваный; LLM часто выдаёт монотонно ровные предложения (CV низкий). НО:
    после агрессивного «очеловечивания» бывает обратное — гипер-burstiness
    (очень рваный, рубленый стиль). Инсайт SEO Guide 4.9 (§05, photo_25):
    цель — ЕСТЕСТВЕННАЯ вариативность, а не максимум разброса. Поэтому
    штрафуем оба края: и слишком ровно, и слишком рвано.
"""
from .textstats import basic_counts, words

LONG_SENTENCE = {"ru": 35, "en": 30}   # порог «длинного» предложения, слов
CV_IDEAL = 0.6                          # ориентир «естественной» вариативности


def _ease_band(ease):
    if ease >= 70:
        return "легко читается"
    if ease >= 50:
        return "средняя сложность"
    if ease >= 30:
        return "сложный текст"
    return "очень сложный текст"


def _burst_score(cv):
    """Пик при естественной вариативности (~0.6); штраф за оба края."""
    if cv <= CV_IDEAL:
        return max(0.0, min(100.0, cv / CV_IDEAL * 100))
    return max(0.0, min(100.0, 100.0 - (cv - CV_IDEAL) / CV_IDEAL * 60))


def analyze(text, lang=None):
    lang, sents, wl, n_sent, n_words, syl = basic_counts(text, lang)
    asl = n_words / n_sent          # средняя длина предложения (слов)
    asw = syl / n_words             # среднее число слогов на слово

    if lang == "ru":
        ease = 206.835 - 1.3 * asl - 60.1 * asw
        grade = 0.5 * asl + 8.4 * asw - 15.59
    else:
        ease = 206.835 - 1.015 * asl - 84.6 * asw
        grade = 0.39 * asl + 11.8 * asw - 15.59
    ease = max(0.0, min(100.0, ease))

    lengths = [len(words(s)) for s in sents] or [0]
    mean = sum(lengths) / len(lengths)
    std = (sum((x - mean) ** 2 for x in lengths) / len(lengths)) ** 0.5
    cv = (std / mean) if mean else 0.0

    long_thr = LONG_SENTENCE[lang]
    n_long = sum(1 for x in lengths if x > long_thr)
    long_ratio = n_long / len(lengths)

    findings = []
    if ease < 40:
        findings.append({"level": "warn",
                         "msg": "Низкая читаемость (Flesch Reading Ease = %.0f, %s)" % (ease, _ease_band(ease)),
                         "detail": "Упростите длинные предложения и термины."})
    if long_ratio > 0.25:
        findings.append({"level": "warn",
                         "msg": "Много длинных предложений: %d из %d (>%d слов)" % (n_long, len(lengths), long_thr),
                         "detail": "Разбейте их — это снижает читаемость и выдаёт машинный стиль."})
    if len(lengths) >= 5:
        if cv < 0.35:
            findings.append({"level": "warn",
                             "msg": "Монотонный ритм: CV длины предложений = %.2f (<0.35)" % cv,
                             "detail": "Слишком ровные предложения — типичный признак LLM. Чередуйте короткие и длинные."})
        elif cv > 1.1:
            findings.append({"level": "warn",
                             "msg": "Гипер-burstiness: CV длины предложений = %.2f (>1.1)" % cv,
                             "detail": "Слишком рваный, рубленый ритм (часто после агрессивного rewrite) — тоже неестественно. Цель — умеренная вариативность."})

    # score: 60% читаемость + 40% естественность ритма
    score = round(0.6 * ease + 0.4 * _burst_score(cv), 1)

    return {
        "name": "Читаемость и ритм",
        "score": score,
        "weight": 1.5,
        "metrics": {
            "lang": lang,
            "flesch_reading_ease": round(ease, 1),
            "flesch_kincaid_grade": round(grade, 1),
            "avg_sentence_len_words": round(asl, 1),
            "avg_syllables_per_word": round(asw, 2),
            "sentence_len_cv": round(cv, 2),
            "long_sentences": n_long,
            "sentences": len(lengths),
            "words": n_words,
        },
        "findings": findings,
    }
