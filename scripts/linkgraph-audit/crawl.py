# Краул активных страниц брендов прода: slug -> ссылки на другие бренды в отрендеренном HTML
import csv, re, sys, urllib.request
from concurrent.futures import ThreadPoolExecutor
S = sys.argv[1]
slugs = [r['slug'] for r in csv.DictReader(open(f'{S}/data/brands.tsv'), delimiter='\t') if r['status'] == 'active']
rx = re.compile(r'href="(?:https://wearbase\.ru)?/ru/brands/([a-z0-9-]+)"')
def get(s):
    try:
        req = urllib.request.Request(f'https://wearbase.ru/ru/brands/{s}', headers={'User-Agent': 'wearbase-linkaudit/1.0'})
        with urllib.request.urlopen(req, timeout=25) as r:
            h = r.read().decode('utf-8', 'replace'); code = r.status
    except urllib.error.HTTPError as e:
        return s, e.code, ''
    except Exception:
        return s, 0, ''
    return s, code, ','.join(sorted(set(rx.findall(h)) - {s}))
with open(f'{S}/data/crawl.tsv', 'w') as f, ThreadPoolExecutor(4) as ex:
    for s, code, links in ex.map(get, slugs):
        f.write(f'{s}\t{code}\t{links}\n')
