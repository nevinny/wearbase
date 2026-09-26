# Недельный ряд: Яндекс pages_in_search/shows/clicks + GSC показы /ru/brands + число опубликованных брендов
import csv, sys, datetime as dt
from collections import defaultdict
S = sys.argv[1]
D = lambda s: dt.date.fromisoformat(s[:10])
brands = list(csv.DictReader(open(f'{S}/data/brands.tsv'), delimiter='\t'))
live = sorted(D(b['published_at'] or b['created_at']) for b in brands if b['status'] == 'active')
wk = lambda d: d - dt.timedelta(d.weekday())
Y = defaultdict(lambda: [None, 0, 0, 0])
for r in csv.DictReader(open(f'{S}/data/yhist.tsv'), delimiter='\t'):
    w = Y[wk(D(r['day']))]
    if r['pages_in_search']: w[0] = int(r['pages_in_search'])
    w[1] += int(r['shows'] or 0); w[2] += int(r['clicks'] or 0); w[3] += 1
G = defaultdict(lambda: [0, 0])
for r in csv.DictReader(open(f'{S}/data/gpage.tsv'), delimiter='\t'):
    if '/ru/brands/' in r['page_url']:
        g = G[wk(D(r['day']))]; g[0] += int(r['impressions']); g[1] += int(r['clicks'])
print('week\tlive_brands\ty_pages\ty_pages/live\ty_shows\ty_clicks\tg_imp_ru_brands\tg_imp/live\tg_clicks')
for w in sorted(Y):
    if w < dt.date(2026, 4, 1): continue
    n = sum(1 for d in live if d <= w + dt.timedelta(6))
    p, s, c, _ = Y[w]; gi, gc = G.get(w, [0, 0])
    print(f'{w}\t{n}\t{p or ""}\t{(p/n) if p and n else 0:.2f}\t{s}\t{c}\t{gi}\t{gi/n if n else 0:.1f}\t{gc}')
