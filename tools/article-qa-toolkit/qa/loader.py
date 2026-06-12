"""Загрузка статьи и приведение к чистому тексту.

Поддерживает .txt, .md/.markdown, .html/.htm. Из markdown/HTML извлекается
plain-text для лингвистических проверок; «сырой» вид сохраняется отдельно —
он нужен модулям, которые анализируют разметку (структура, ссылки, SEO).
"""
import re
import html as _html


def detect_format(path):
    p = path.lower()
    if p.endswith((".html", ".htm")):
        return "html"
    if p.endswith((".md", ".markdown")):
        return "md"
    return "txt"


def strip_html(raw):
    raw = re.sub(r"(?is)<(script|style|head)[^>]*>.*?</\1>", " ", raw)
    raw = re.sub(r"(?i)<(br|/p|/div|/li|/h[1-6]|/tr|/section|/article)\s*/?>", "\n", raw)
    text = re.sub(r"(?s)<[^>]+>", " ", raw)
    return _html.unescape(text)


def strip_markdown(raw):
    text = raw
    text = re.sub(r"(?s)```.*?```", " ", text)          # блоки кода
    text = re.sub(r"`[^`]*`", " ", text)                # inline-код
    text = re.sub(r"!\[[^\]]*\]\([^)]*\)", " ", text)   # картинки
    text = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", text)  # ссылки -> текст
    text = re.sub(r"(?m)^\s{0,3}#{1,6}\s*", "", text)   # заголовки
    text = re.sub(r"(?m)^\s{0,3}>\s?", "", text)        # цитаты
    text = re.sub(r"(?m)^\s{0,3}[-*+]\s+", "", text)    # маркеры списка
    text = re.sub(r"(?m)^\s{0,3}\d+\.\s+", "", text)    # нумерованный список
    text = re.sub(r"(\*\*|__|\*|_|~~)", "", text)        # выделение
    return text


def normalize_ws(text):
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    lines = [re.sub(r"[ \t]+", " ", ln).strip() for ln in text.split("\n")]
    text = "\n".join(lines)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def to_plaintext(raw, fmt):
    if fmt == "html":
        return normalize_ws(strip_html(raw))
    if fmt == "md":
        return normalize_ws(strip_markdown(raw))
    return normalize_ws(raw)


def load(path):
    """Возвращает (fmt, raw, plaintext)."""
    with open(path, "r", encoding="utf-8", errors="replace") as fh:
        raw = fh.read()
    fmt = detect_format(path)
    return fmt, raw, to_plaintext(raw, fmt)
