"""Загрузка словарей паттернов и подсчёт их вхождений в текст."""
import os
import re


def load_list(patterns_dir, filename):
    path = os.path.join(patterns_dir, filename)
    items = []
    if not os.path.exists(path):
        return items
    with open(path, "r", encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            items.append(line.lower())
    return items


def _compile(phrase):
    """Однословные паттерны матчим по границам слова, многословные — как подстроку."""
    esc = re.escape(phrase)
    if " " in phrase:
        return re.compile(esc)
    return re.compile(r"(?<!\w)" + esc + r"(?!\w)", re.UNICODE)


def count_matches(text_lower, phrases):
    """Возвращает список (phrase, count) для найденных паттернов, по убыванию частоты."""
    found = []
    for ph in phrases:
        n = len(_compile(ph).findall(text_lower))
        if n:
            found.append((ph, n))
    found.sort(key=lambda t: (-t[1], t[0]))
    return found
