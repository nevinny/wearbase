#!/usr/bin/env python3
"""
Трекер задач v2: одна задача — один файл, сквозная нумерация, индекс генерируется.

Зачем: docs/tasktracker.md — один файл на 44 КБ, в который параллельные сессии
пишут одновременно (71 коммит за 30 суток). Записи затирали друг друга. Здесь
общей точки записи нет: каждая задача — свой файл, индекс собирается из файлов.

Команды:
    task.py new "<заголовок>" [--owner NAME] [--status STATUS] [--source TEXT]
    task.py done <NNNN>          — перенести в docs/tasks/done/
    task.py reopen <NNNN>        — вернуть из done/ в активные
    task.py index                — пересобрать docs/tasks/INDEX.md
    task.py list [--status S]    — показать задачи в терминале
"""

import argparse
import datetime
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
TASKS_DIR = os.path.join(ROOT, 'docs', 'tasks')
DONE_DIR = os.path.join(TASKS_DIR, 'done')
INDEX_PATH = os.path.join(TASKS_DIR, 'INDEX.md')

# Порядок важен: он же порядок разделов в индексе.
STATUSES = ['новая', 'в работе', 'ждёт решения', 'сделана', 'отменена']
DONE_STATUSES = {'сделана', 'отменена'}

NAME_RE = re.compile(r'^(\d{4})-')

# Транслитерация для слага: имена файлов латиницей — их проще грепать,
# передавать в командах и открывать из любого окружения.
TRANSLIT = {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e',
    'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm',
    'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u',
    'ф': 'f', 'х': 'h', 'ц': 'c', 'ч': 'ch', 'ш': 'sh', 'щ': 'sch', 'ъ': '',
    'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya',
}


def today() -> str:
    return datetime.date.today().isoformat()


def slugify(title: str) -> str:
    """Заголовок → короткий латинский слаг для имени файла."""
    text = ''.join(TRANSLIT.get(ch, ch) for ch in title.lower())
    text = re.sub(r'[^a-z0-9]+', '-', text).strip('-')

    return (text[:60].rstrip('-') or 'task')


def all_task_files() -> list:
    """Все файлы задач — и активные, и завершённые."""
    found = []
    for directory in (TASKS_DIR, DONE_DIR):
        if not os.path.isdir(directory):
            continue
        for name in os.listdir(directory):
            if name.endswith('.md') and NAME_RE.match(name):
                found.append(os.path.join(directory, name))

    return sorted(found)


def parse_frontmatter(path: str) -> dict:
    """Читает только шапку между --- и ---; тело задачи не трогаем."""
    meta = {}
    with open(path, encoding='utf-8') as handle:
        if handle.readline().strip() != '---':
            return meta
        for line in handle:
            if line.strip() == '---':
                break
            if ':' not in line:
                continue
            key, _, value = line.partition(':')
            meta[key.strip()] = value.strip()

    return meta


def next_number() -> int:
    """Максимальный существующий номер + 1. Гонку решает атомарное создание."""
    numbers = [
        int(NAME_RE.match(os.path.basename(p)).group(1))
        for p in all_task_files()
    ]

    return (max(numbers) + 1) if numbers else 1


def cmd_new(args) -> int:
    os.makedirs(TASKS_DIR, exist_ok=True)
    slug = slugify(args.title)
    number = next_number()

    # Атомарное выделение номера: 'x' падает, если файл уже создан соседней
    # сессией. Тогда берём следующий номер и пробуем снова — без блокировок.
    for _ in range(100):
        name = f'{number:04d}-{slug}.md'
        path = os.path.join(TASKS_DIR, name)
        try:
            handle = open(path, 'x', encoding='utf-8')
        except FileExistsError:
            number += 1
            continue

        with handle:
            handle.write(TEMPLATE.format(
                id=f'{number:04d}',
                title=args.title.replace('"', "'"),
                status=args.status,
                owner=args.owner or os.environ.get('CLAUDE_SESSION', 'не назначен'),
                source=args.source or '',
                created=today(),
                updated=today(),
            ))

        print(path)
        cmd_index(args)

        return 0

    print('не удалось выделить номер: 100 подряд заняты', file=sys.stderr)

    return 1


def find_task(number: str):
    """Ищет файл задачи по номеру в активных и завершённых."""
    number = number.zfill(4)
    for path in all_task_files():
        if os.path.basename(path).startswith(number + '-'):
            return path

    return None


def set_status(path: str, status: str) -> None:
    """Правит status и updated в шапке, тело не трогает."""
    with open(path, encoding='utf-8') as handle:
        lines = handle.readlines()

    inside = False
    for i, line in enumerate(lines):
        if line.strip() == '---':
            if inside:
                break
            inside = True
            continue
        if not inside:
            continue
        if line.startswith('status:'):
            lines[i] = f'status: {status}\n'
        elif line.startswith('updated:'):
            lines[i] = f'updated: {today()}\n'

    with open(path, 'w', encoding='utf-8') as handle:
        handle.writelines(lines)


def move_task(number: str, to_done: bool, args) -> int:
    path = find_task(number)
    if path is None:
        print(f'задача {number} не найдена', file=sys.stderr)

        return 1

    target_dir = DONE_DIR if to_done else TASKS_DIR
    os.makedirs(target_dir, exist_ok=True)
    # Имя файла не меняем: номер остаётся грепаемым, ссылки на него живут.
    target = os.path.join(target_dir, os.path.basename(path))

    if os.path.abspath(path) != os.path.abspath(target):
        os.rename(path, target)

    set_status(target, 'сделана' if to_done else 'в работе')
    print(target)
    cmd_index(args)

    return 0


def cmd_done(args) -> int:
    return move_task(args.number, True, args)


def cmd_reopen(args) -> int:
    return move_task(args.number, False, args)


def collect() -> list:
    """Метаданные всех задач для индекса и списка."""
    rows = []
    for path in all_task_files():
        meta = parse_frontmatter(path)
        meta['path'] = os.path.relpath(path, TASKS_DIR)
        meta.setdefault('id', NAME_RE.match(os.path.basename(path)).group(1))
        meta.setdefault('title', os.path.basename(path))
        meta.setdefault('status', 'новая')
        rows.append(meta)

    return sorted(rows, key=lambda r: r['id'])


def cmd_index(args) -> int:
    """Пересобирает INDEX.md из файлов задач. Руками его не правят."""
    rows = collect()
    out = [
        '# Задачи — индекс',
        '',
        '> Файл СГЕНЕРИРОВАН из `docs/tasks/*.md`. Руками не править —',
        '> изменения затрутся. Правьте шапку самой задачи, затем',
        '> `python3 tools/tasks/task.py index`.',
        '',
        f'Всего задач: {len(rows)} · обновлено {today()}',
        '',
    ]

    for status in STATUSES:
        group = [r for r in rows if r.get('status') == status]
        if not group:
            continue
        out.append(f'## {status} ({len(group)})')
        out.append('')
        out.append('| # | Задача | Владелец | Обновлена |')
        out.append('|---|---|---|---|')
        for row in group:
            title = row['title'].replace('|', '\\|')
            out.append(
                f"| {row['id']} | [{title}]({row['path']}) "
                f"| {row.get('owner', '')} | {row.get('updated', '')} |"
            )
        out.append('')

    unknown = [r for r in rows if r.get('status') not in STATUSES]
    if unknown:
        out.append(f'## статус не распознан ({len(unknown)})')
        out.append('')
        for row in unknown:
            out.append(f"- {row['id']} — `{row.get('status')}` — {row['path']}")
        out.append('')

    os.makedirs(TASKS_DIR, exist_ok=True)
    with open(INDEX_PATH, 'w', encoding='utf-8') as handle:
        handle.write('\n'.join(out))

    return 0


def cmd_list(args) -> int:
    for row in collect():
        if args.status and row.get('status') != args.status:
            continue
        print(f"{row['id']}  {row.get('status', ''):<14} {row['title']}")

    return 0


TEMPLATE = """---
id: {id}
title: {title}
status: {status}
owner: {owner}
source: {source}
created: {created}
updated: {updated}
---

## Суть

<что не так или что нужно сделать — 2-3 строки, понятные через месяц без контекста>

## Почему это важно

<чем грозит, если не делать; на что влияет — деньги, воронка, прод>

## Где смотреть

<файлы со строками, MR, таблицы, чаты>

## Что сделать

- [ ] <шаг>

## Заметки

<находки по ходу; сюда же — что проверено и оказалось неверным>
"""


def main() -> int:
    parser = argparse.ArgumentParser(description='Трекер задач v2')
    sub = parser.add_subparsers(dest='cmd', required=True)

    p_new = sub.add_parser('new', help='завести задачу')
    p_new.add_argument('title')
    p_new.add_argument('--owner', default='')
    p_new.add_argument('--source', default='')
    p_new.add_argument('--status', default='новая', choices=STATUSES)
    p_new.set_defaults(func=cmd_new)

    p_done = sub.add_parser('done', help='перенести в done/')
    p_done.add_argument('number')
    p_done.set_defaults(func=cmd_done)

    p_reopen = sub.add_parser('reopen', help='вернуть из done/')
    p_reopen.add_argument('number')
    p_reopen.set_defaults(func=cmd_reopen)

    p_index = sub.add_parser('index', help='пересобрать INDEX.md')
    p_index.set_defaults(func=cmd_index)

    p_list = sub.add_parser('list', help='показать задачи')
    p_list.add_argument('--status', default='', choices=[''] + STATUSES)
    p_list.set_defaults(func=cmd_list)

    args = parser.parse_args()

    return args.func(args)


if __name__ == '__main__':
    sys.exit(main())
