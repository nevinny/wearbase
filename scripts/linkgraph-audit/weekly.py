# Проверка: градиент индексации по реальным входящим внутри НЕДЕЛИ публикации (июль–август)
import csv, sys, datetime as dt
from collections import defaultdict, Counter
S = sys.argv[1]
T = lambda n: list(csv.DictReader(open(f'{S}/data/{n}.tsv'), delimiter='\t'))
D = lambda s: dt.date.fromisoformat(s[:10]) if s else None
act = {b['slug']: b for b in T('brands') if b['status'] == 'active'}
rin = Counter()
for line in open(f'{S}/data/crawl.tsv'):
    s, code, links = line.rstrip('\n').split('\t')
    for l in filter(None, links.split(',')):
        if l in act: rin[l] += 1
sl = lambda u: u.split('/ru/brands/')[1].strip('/') if '/ru/brands/' in u else None
st = {sl(r['page_url']): r['coverage_state'] for r in T('gidx')}
IDX = ('Submitted and indexed', 'Served (Search Analytics)')
G = defaultdict(lambda: defaultdict(list))
for s, b in act.items():
    d = D(b['published_at'])
    if not d or d < dt.date(2026, 7, 1): continue
    w = d - dt.timedelta(d.weekday()); g = '0' if rin[s] == 0 else '1-2' if rin[s] <= 2 else '3+'
    G[w][g].append(st.get(s) in IDX)
print('week\t0: n idx%\t1-2: n idx%\t3+: n idx%')
tot = defaultdict(lambda: [0, 0, 0])  # weighted diff 3+ vs 0
for w in sorted(G):
    row = [str(w)]
    for g in ('0', '1-2', '3+'):
        a = G[w][g]; row.append(f'{len(a)} {100*sum(a)/len(a):.0f}' if a else '-')
    print('\t'.join(row))
    a0, a3 = G[w]['0'], G[w]['3+']
    if len(a0) >= 10 and len(a3) >= 10:
        wgt = min(len(a0), len(a3)); t = tot['x']
        t[0] += wgt * (sum(a3)/len(a3) - sum(a0)/len(a0)); t[1] += wgt
if tot['x'][1]: print(f"взвешенная разница 3+ минус 0 внутри недель: {100*tot['x'][0]/tot['x'][1]:+.1f} п.п.")
