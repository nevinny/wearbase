"""Ссылки: извлечение и (опционально) проверка доступности.

LLM любит выдумывать правдоподобные, но несуществующие URL и «битые» якоря.
По умолчанию модуль только собирает ссылки; с флагом --check-links делает
HEAD/GET-запросы (только stdlib urllib) и помечает битые/редиректы.
"""
import re
import urllib.request
import urllib.error

_MD_LINK = re.compile(r"\[[^\]]*\]\((https?://[^)\s]+)\)")
_HTML_HREF = re.compile(r'(?i)href=["\'](https?://[^"\']+)["\']')
_BARE = re.compile(r"(?<!\()(?<!\")\bhttps?://[^\s)<>\"']+")


def extract_links(raw, fmt):
    urls = []
    if fmt == "md":
        urls += _MD_LINK.findall(raw)
    if fmt == "html":
        urls += _HTML_HREF.findall(raw)
    urls += _BARE.findall(raw)
    seen, out = set(), []
    for u in urls:
        u = u.rstrip(".,;)")
        if u not in seen:
            seen.add(u); out.append(u)
    return out


def _check(url, timeout=8):
    req = urllib.request.Request(url, method="HEAD",
                                 headers={"User-Agent": "article-qa-toolkit/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, None
    except urllib.error.HTTPError as e:
        if e.code in (405, 403):  # HEAD не разрешён — пробуем GET
            try:
                req2 = urllib.request.Request(url, headers={"User-Agent": "article-qa-toolkit/1.0"})
                with urllib.request.urlopen(req2, timeout=timeout) as r:
                    return r.status, None
            except Exception as e2:
                return None, str(e2)
        return e.code, None
    except Exception as e:
        return None, str(e)


def analyze(raw, fmt, check=False):
    urls = extract_links(raw, fmt)
    findings = []
    broken = 0
    statuses = {}
    if check and urls:
        for u in urls:
            status, err = _check(u)
            statuses[u] = status if status else ("ERR: " + (err or "")[:60])
            if status is None or status >= 400:
                broken += 1
                findings.append({"level": "error", "msg": "Битая ссылка [%s]: %s" % (statuses[u], u), "detail": None})
            elif 300 <= status < 400:
                findings.append({"level": "info", "msg": "Редирект [%s]: %s" % (status, u), "detail": None})
    elif urls:
        findings.append({"level": "info", "msg": "Найдено ссылок: %d (проверка выключена; включите --check-links)" % len(urls), "detail": None})

    score = None
    if check:
        score = round(max(0.0, 100.0 - (broken / max(len(urls), 1)) * 100), 1) if urls else 100.0

    return {
        "name": "Ссылки",
        "score": score,
        "weight": 1.0,
        "metrics": {"links": len(urls), "checked": bool(check), "broken": broken if check else None},
        "findings": findings,
    }
