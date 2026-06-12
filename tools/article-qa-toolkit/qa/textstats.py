"""Базовая токенизация и слоговая статистика (общая для всех проверок).

Никаких внешних зависимостей — только стандартная библиотека.
Поддерживает русский и английский; язык определяется по соотношению
кириллицы и латиницы.
"""
import re

VOWELS_RU = set("аеёиоуыэюя")
VOWELS_EN = set("aeiouy")

# Слово: буквы кириллицы/латиницы, допускаем дефис и апостроф внутри.
WORD_RE = re.compile(r"[A-Za-zА-Яа-яЁё]+(?:[-'’][A-Za-zА-Яа-яЁё]+)*")

# Конец предложения: . ! ? … (возможно с закрывающей кавычкой/скобкой), затем пробел.
_SENT_SPLIT_RE = re.compile(r"(?<=[.!?…])[\"'»”’)\]]?\s+")


def detect_lang(text):
    """'ru' если кириллицы не меньше, чем латиницы, иначе 'en'."""
    cyr = len(re.findall(r"[А-Яа-яЁё]", text))
    lat = len(re.findall(r"[A-Za-z]", text))
    if cyr == 0 and lat == 0:
        return "en"
    return "ru" if cyr >= lat else "en"


def sentences(text):
    """Грубое, но практичное разбиение на предложения.

    Переносы строк считаем границами: заголовки и пункты списков — отдельные
    единицы, иначе они «склеиваются» со следующим предложением.
    """
    out = []
    for block in re.split(r"\n+", text):
        block = block.strip()
        if not block:
            continue
        flat = re.sub(r"[ \t]+", " ", block)
        out.extend(p.strip() for p in _SENT_SPLIT_RE.split(flat) if p.strip())
    return out


def words(text):
    """Список слов (только буквенные токены)."""
    return WORD_RE.findall(text)


def syllables_ru(word):
    n = sum(1 for ch in word.lower() if ch in VOWELS_RU)
    return n if n > 0 else 1


def syllables_en(word):
    w = re.sub(r"[^a-z]", "", word.lower())
    if not w:
        return 1
    groups = re.findall(r"[aeiouy]+", w)
    n = len(groups)
    if w.endswith("e") and n > 1:   # немое 'e'
        n -= 1
    return n if n > 0 else 1


def syllables(word, lang):
    return syllables_ru(word) if lang == "ru" else syllables_en(word)


def basic_counts(text, lang=None):
    """Возвращает (lang, sents, words_list, n_sent, n_words, n_syllables)."""
    lang = lang or detect_lang(text)
    sents = sentences(text)
    wl = words(text)
    n_sent = max(len(sents), 1)
    n_words = max(len(wl), 1)
    syl = sum(syllables(w, lang) for w in wl)
    return lang, sents, wl, n_sent, n_words, syl
