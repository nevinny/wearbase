#!/usr/bin/env python3
"""VTT-субтитры YouTube (авто и ручные) → чистый текст одной строкой.

Формат совпадает с архивом ~/yt-kb/txt/<channel>/<video_id>.txt (склейка пробелами):
срезает заголовок WEBVTT, таймкоды, inline-теги <c>/<00:00:00.000>, позиционные
атрибуты и rolling-дубли строк (авто-субтитры повторяют предыдущую строку в каждом cue).

Использование: python3 vtt2txt.py input.vtt > output.txt
"""
import re
import sys


def vtt_to_text(src: str) -> str:
    out = []
    for line in src.splitlines():
        line = line.strip()
        if not line or '-->' in line:
            continue
        if line == 'WEBVTT' or line.startswith(('Kind:', 'Language:', 'NOTE', 'STYLE', '::cue')):
            continue
        line = re.sub(r'<[^>]+>', '', line).strip()
        line = line.replace('&nbsp;', ' ').replace('&amp;', '&').replace('&gt;', '>').replace('&lt;', '<')
        if not line:
            continue
        if out and line == out[-1]:  # rolling-дубль авто-субтитров
            continue
        out.append(line)
    return ' '.join(out)


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('usage: vtt2txt.py <file.vtt>')
    with open(sys.argv[1], encoding='utf-8') as f:
        print(vtt_to_text(f.read()))
