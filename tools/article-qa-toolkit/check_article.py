#!/usr/bin/env python3
"""article-qa-toolkit — проверка качества статьи после генерации LLM.

Реализует проверки методологии SEO Guide 4.9 (Anti-AI TIER-1/2, Quality Gate
SB/RV/HL, AVI, Information Gain, hreflang isReal) + базовые (читаемость, повторы,
SEO, структура, фактчек, ссылки).

Запуск:
    python3 check_article.py статья.md
    python3 check_article.py статья.html --keyword "купить духи" --check-links
    python3 check_article.py черновик.md --gate --avi          # пороги публикации + AVI
    python3 check_article.py ru.md --lang ru --compare en.md    # проверка реальности перевода
    python3 check_article.py *.md --json reports/out.json
    OPENAI_API_KEY=... python3 check_article.py статья.txt --llm

Код завершения: 0 если итог >= --min-score И (если задан --gate) gate пройден;
иначе 1 — удобно встраивать в CI/пайплайн генерации контента.
"""
import argparse
import glob
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

from qa import (loader, readability, ai_patterns, repetition, hedging, seo,
                structure, factuality, information_gain, hreflang, links,
                quality_gate, avi, llm_judge, report)
from qa.textstats import detect_lang, words

PATTERNS_DIR = os.path.join(HERE, "patterns")


def run_one(path, args, compare_text=None):
    fmt, raw, plain = loader.load(path)
    nw = len(words(plain))
    if nw < 30:   # пустой/слишком короткий файл — не оцениваем по модулям (иначе ложное «ХОРОШО»)
        return [{
            "name": "Контент",
            "score": 0.0,
            "weight": 1.0,
            "metrics": {"words": nw, "gate_passed": False},
            "findings": [{"level": "error",
                          "msg": "Пустой или слишком короткий файл: %d слов — нечего оценивать" % nw,
                          "detail": "Минимум для осмысленной проверки — ~30 слов (методология: инфо-статья ≥1200)."}],
        }]
    lang = args.lang if args.lang != "auto" else detect_lang(plain)
    declared = args.lang if args.lang in ("ru", "en") else None

    base = {
        "readability": readability.analyze(plain, lang),
        "ai": ai_patterns.analyze(plain, PATTERNS_DIR, lang),
        "repetition": repetition.analyze(plain, lang),
        "hedging": hedging.analyze(plain, PATTERNS_DIR, lang),
        "seo": seo.analyze(plain, raw, fmt, PATTERNS_DIR, keyword=args.keyword, lang=lang),
        "structure": structure.analyze(plain, raw, fmt, lang),
        "factuality": factuality.analyze(plain, lang),
        "infogain": information_gain.analyze(plain, lang),
    }
    modules = [base["readability"], base["ai"], base["repetition"], base["hedging"],
               base["seo"], base["structure"], base["factuality"], base["infogain"]]

    modules.append(hreflang.analyze(plain, lang, compare_text=compare_text, declared_lang=declared))
    modules.append(links.analyze(raw, fmt, check=args.check_links))

    if args.gate or args.avi:
        g = quality_gate.compute(base, lang)
        modules.append(g)
        if args.avi:
            modules.append(avi.compute(base, g, lang))

    if args.llm:
        modules.append(llm_judge.analyze(plain))

    return modules


def main(argv=None):
    ap = argparse.ArgumentParser(description="Проверка качества статьи после LLM")
    ap.add_argument("files", nargs="+", help="файлы статей (.txt/.md/.html), можно маской")
    ap.add_argument("--keyword", help="целевой SEO-ключ для проверки вхождения/плотности")
    ap.add_argument("--lang", default="auto", choices=["auto", "ru", "en"], help="язык (по умолчанию авто)")
    ap.add_argument("--gate", action="store_true", help="Quality Gate SB/RV/HL (порог публикации, влияет на exit-код)")
    ap.add_argument("--avi", action="store_true", help="посчитать AVI (Article Value Index, 5 осей offline)")
    ap.add_argument("--compare", help="файл-оригинал для проверки реальности перевода (hreflang isReal)")
    ap.add_argument("--check-links", action="store_true", help="проверять доступность ссылок (HTTP)")
    ap.add_argument("--llm", action="store_true", help="включить LLM-судью (нужен OPENAI_API_KEY)")
    ap.add_argument("--json", dest="json_out", help="сохранить отчёт(ы) в JSON-файл")
    ap.add_argument("--no-color", action="store_true", help="без ANSI-цветов")
    ap.add_argument("--min-score", type=float, default=0.0, help="порог прохождения для кода возврата")
    args = ap.parse_args(argv)

    paths = []
    for pat in args.files:
        hits = glob.glob(pat)
        paths.extend(hits if hits else [pat])
    paths = [p for p in paths if os.path.isfile(p)]
    if not paths:
        print("Файлы не найдены.", file=sys.stderr)
        return 2

    compare_text = None
    if args.compare and os.path.isfile(args.compare):
        _, _, compare_text = loader.load(args.compare)

    color = sys.stdout.isatty() and not args.no_color
    all_reports = []
    worst = 100.0
    gate_failed = False
    for path in paths:
        try:
            modules = run_one(path, args, compare_text=compare_text)
        except Exception as e:
            print("Ошибка при обработке %s: %s" % (path, e), file=sys.stderr)
            continue
        print(report.render(path, modules, color=color))
        rep = report.to_dict(path, modules)
        all_reports.append(rep)
        if rep["overall"] is not None:
            worst = min(worst, rep["overall"])
        for m in rep["modules"]:
            if (m.get("metrics") or {}).get("gate_passed") is False:
                gate_failed = True

    if args.json_out:
        os.makedirs(os.path.dirname(args.json_out) or ".", exist_ok=True)
        with open(args.json_out, "w", encoding="utf-8") as fh:
            json.dump(all_reports if len(all_reports) > 1 else (all_reports[0] if all_reports else {}),
                      fh, ensure_ascii=False, indent=2)
        print("JSON-отчёт сохранён: %s" % args.json_out)

    ok = worst >= args.min_score and not (args.gate and gate_failed)
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
