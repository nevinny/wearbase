"""Сборка результатов проверок в единый отчёт и вывод в терминал."""

C = {
    "reset": "\033[0m", "bold": "\033[1m", "dim": "\033[2m",
    "red": "\033[31m", "yellow": "\033[33m", "green": "\033[32m",
    "cyan": "\033[36m", "grey": "\033[90m",
}
LEVEL_COLOR = {"error": "red", "warn": "yellow", "info": "grey"}
LEVEL_MARK = {"error": "✗", "warn": "▲", "info": "·"}


def _c(text, color, enable):
    if not enable:
        return text
    return C.get(color, "") + text + C["reset"]


def overall_score(modules):
    num = den = 0.0
    for m in modules:
        if m.get("score") is None or not m.get("weight"):
            continue
        num += m["score"] * m["weight"]
        den += m["weight"]
    return round(num / den, 1) if den else None


def verdict(score):
    if score is None:
        return ("—", "grey")
    if score >= 85:
        return ("ОТЛИЧНО — можно публиковать", "green")
    if score >= 70:
        return ("ХОРОШО — мелкие правки", "green")
    if score >= 55:
        return ("ТРЕБУЕТ ДОРАБОТКИ", "yellow")
    return ("ПЛОХО — переписать", "red")


def _bar(score, width=20, enable=True):
    if score is None:
        return _c("n/a", "grey", enable)
    filled = int(round(score / 100 * width))
    color = "green" if score >= 70 else ("yellow" if score >= 55 else "red")
    return _c("█" * filled, color, enable) + _c("░" * (width - filled), "grey", enable)


def render(path, modules, color=True):
    lines = []
    total = overall_score(modules)
    vtext, vcolor = verdict(total)

    lines.append("")
    lines.append(_c("═" * 64, "cyan", color))
    lines.append(_c("  ОТЧЁТ О КАЧЕСТВЕ: ", "bold", color) + path)
    lines.append(_c("═" * 64, "cyan", color))
    lines.append("  Итоговая оценка: %s  %s/100   %s" % (
        _bar(total, 20, color),
        _c("%5s" % (total if total is not None else "—"), vcolor, color),
        _c(vtext, vcolor, color)))

    # баннеры производных вердиктов (Quality Gate, AVI)
    banners = [m["metrics"] for m in modules
               if isinstance(m.get("metrics"), dict) and m["metrics"].get("banner")]
    if banners:
        lines.append("")
        for mm in banners:
            lines.append("  " + _c(mm["banner"], mm.get("banner_color", "cyan"), color))
    lines.append("")

    # таблица по модулям
    lines.append(_c("  Проверка                        балл", "bold", color))
    lines.append(_c("  " + "-" * 60, "grey", color))
    for m in modules:
        s = m.get("score")
        s_txt = ("%5.1f" % s) if s is not None else "  n/a"
        lines.append("  %-30s %s %s" % (m["name"][:30], _bar(s, 14, color), s_txt))
    lines.append("")

    # счётчики замечаний
    errs = warns = infos = 0
    for m in modules:
        for f in m["findings"]:
            lvl = f["level"]
            errs += lvl == "error"; warns += lvl == "warn"; infos += lvl == "info"
    lines.append("  Замечания: %s  %s  %s" % (
        _c("%d ошибок" % errs, "red", color),
        _c("%d предупреждений" % warns, "yellow", color),
        _c("%d заметок" % infos, "grey", color)))
    lines.append("")

    # детали
    for m in modules:
        if not m["findings"] and m.get("score") is None:
            continue
        head = "  ▸ %s" % m["name"]
        if m.get("score") is not None:
            head += _c("  [%.1f]" % m["score"], "grey", color)
        lines.append(_c(head, "bold", color))
        if not m["findings"]:
            lines.append(_c("      без замечаний", "green", color))
        for f in m["findings"]:
            mark = LEVEL_MARK[f["level"]]
            col = LEVEL_COLOR[f["level"]]
            lines.append("      " + _c(mark, col, color) + " " + f["msg"])
            if f.get("detail"):
                lines.append(_c("        " + f["detail"], "grey", color))
        lines.append("")

    return "\n".join(lines)


def to_dict(path, modules):
    clean = []
    for m in modules:
        clean.append({k: v for k, v in m.items() if not k.startswith("_")})
    return {
        "file": path,
        "overall": overall_score(modules),
        "verdict": verdict(overall_score(modules))[0],
        "modules": clean,
    }
