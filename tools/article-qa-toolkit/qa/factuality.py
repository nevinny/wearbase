"""Поверхность для фактчека — где LLM вероятнее всего галлюцинирует.

Автоматически проверить факты без внешних источников нельзя, поэтому модуль
не «оценивает правду», а собирает список рискованных утверждений, которые
обязан проверить человек или второй проход LLM (llm_judge):
  * числа, проценты, денежные суммы;
  * годы и даты;
  * превосходные степени («самый», «первый», "best", "only");
  * ссылки на источники без ссылки ("по данным…", "исследование показало",
    "according to", "studies show") — частый признак выдуманного авторитета;
  * прямые цитаты в кавычках.

Балл — мягкий: много «голых» цифр и громких заявлений без источников снижает
доверие к тексту.
"""
import re
from .textstats import sentences, words, detect_lang

SUPERLATIVES = {
    "ru": ["самый", "самая", "самое", "самые", "крупнейший", "крупнейшая", "единственный",
           "единственная", "первый", "первая", "лучший", "лучшая", "наиболее", "максимальн",
           "минимальн", "рекордн", "беспрецедентн"],
    "en": ["most", "best", "first", "largest", "biggest", "only", "leading", "unprecedented",
           "record", "fastest", "greatest", "ultimate"],
}
AUTHORITY = {
    "ru": ["по данным", "по информации", "исследовани", "учёные", "ученые", "эксперты",
           "статистика", "согласно", "доказано", "опрос показал"],
    "en": ["according to", "studies show", "research shows", "experts say", "statistics",
           "data shows", "it is proven", "a study found", "surveys show"],
}


def _has(sentence_low, items):
    return any(k in sentence_low for k in items)


def analyze(text, lang=None):
    lang = lang or detect_lang(text)
    sents = sentences(text)
    n_sent = max(len(sents), 1)

    flagged = []
    n_numbers = n_super = n_auth = n_quote = 0
    for s in sents:
        low = s.lower()
        reasons = []
        if re.search(r"\d", s):
            reasons.append("цифры/проценты"); n_numbers += 1
        if re.search(r"\b(19|20)\d{2}\b", s):
            if "год/дата" not in reasons:
                reasons.append("год/дата")
        if _has(low, SUPERLATIVES[lang]):
            reasons.append("превосходная степень"); n_super += 1
        if _has(low, AUTHORITY[lang]):
            reasons.append("ссылка на «источник»"); n_auth += 1
        if re.search(r"[«\"“][^»\"”]{8,}[»\"”]", s):
            reasons.append("прямая цитата"); n_quote += 1
        if reasons:
            flagged.append((s.strip(), reasons))

    findings = []
    for s, reasons in flagged[:15]:
        snippet = s if len(s) <= 140 else s[:137] + "…"
        findings.append({"level": "info", "msg": "Проверить факт [%s]" % ", ".join(reasons),
                         "detail": snippet})

    # «голый авторитет»: заявления про исследования/экспертов без чисел рядом — особо подозрительно
    naked_authority = sum(1 for s, r in flagged if "ссылка на «источник»" in r)
    if naked_authority >= 2:
        findings.append({"level": "warn",
                         "msg": "Ссылки на «исследования/экспертов» без указания источника: %d" % naked_authority,
                         "detail": "Классический способ LLM выдумать авторитет. Добавьте конкретные ссылки."})

    risk_density = len(flagged) / n_sent
    # мягкий балл: текст без единого факта подозрительно «водянист», текст где
    # каждое второе предложение — голое заявление, тоже рискован. Оптимум ~посередине.
    score = round(max(0.0, 100.0 - naked_authority * 12 - max(0, risk_density - 0.5) * 60), 1)

    return {
        "name": "Фактчек (поверхность риска)",
        "score": score,
        "weight": 0.5,
        "metrics": {
            "sentences_to_check": len(flagged),
            "of_total_sentences": n_sent,
            "with_numbers": n_numbers,
            "with_superlatives": n_super,
            "with_authority_claims": n_auth,
            "with_quotes": n_quote,
        },
        "findings": findings,
    }
