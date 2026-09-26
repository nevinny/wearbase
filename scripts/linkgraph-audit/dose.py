# Пробрендовый «доза→отклик»: входящие ссылки (граф brand_related и реальный HTML-краул)
# против индексации GSC/Яндекса и показов GSC, в стратах по месяцу публикации.
import csv, sys, datetime as dt
from collections import defaultdict, Counter
S = sys.argv[1]
T = lambda n: list(csv.DictReader(open(f'{S}/data/{n}.tsv'), delimiter='\t'))
D = lambda s: dt.date.fromisoformat(s[:10]) if s else None
GRAPH_DAY = dt.date(2026, 6, 12)
brands = {b['id']: b for b in T('brands')}
active = {i: b for i, b in brands.items() if b['status'] == 'active'}
slug2id = {b['slug']: i for i, b in active.items()}
live = {i: D(b['published_at']) or D(b['created_at']) for i, b in active.items()}

gin, gin_nofill, first_edge = Counter(), Counter(), {}
for e in T('edges'):
    s, t = e['brand_id'], e['related_brand_id']
    if s in active and t in active:
        gin[t] += 1
        if e['source'] != 'fill': gin_nofill[t] += 1
        d = D(e['created_at']); first_edge[t] = min(first_edge.get(t, d), d)

rin, crawled = Counter(), set()
try:
    for line in open(f'{S}/data/crawl.tsv'):
        s, code, links = line.rstrip('\n').split('\t')
        if code != '200': continue
        crawled.add(s)
        for l in filter(None, links.split(',')):
            if l in slug2id: rin[slug2id[l]] += 1
except FileNotFoundError: pass

sl = lambda u: u.split('/ru/brands/')[1].split('?')[0].strip('/') if '/ru/brands/' in u else None
gstate = {slug2id[sl(r['page_url'])]: r['coverage_state'] for r in T('gidx') if sl(r['page_url']) in slug2id}
imp, imp30 = Counter(), Counter()
for r in T('gpage'):
    s = sl(r['page_url'])
    if s in slug2id:
        i = slug2id[s]; imp[i] += int(r['impressions'])
        if live[i] and 0 <= (D(r['day']) - live[i]).days < 30: imp30[i] += int(r['impressions'])
yfirst = {r['brand_id']: D(r['first_seen_at']) for r in T('yidx') if r['page_type'] in ('brand', '')}
if not yfirst: yfirst = {r['brand_id']: D(r['first_seen_at']) for r in T('yidx')}
YSYNC = min(yfirst.values())

def bucket(n): return '0' if n == 0 else '1-2' if n <= 2 else '3-5' if n <= 5 else '6-11' if n <= 11 else '12+'
ORDER = ['0', '1-2', '3-5', '6-11', '12+']
def cohort(i):
    d = live[i]
    return 'pre-graph(<06-12)' if d < GRAPH_DAY else d.strftime('%Y-%m')

def table(title, deg, rows_filter=lambda i: True):
    print(f'\n### {title}')
    print('cohort\tbucket\tn\tGSC_known%\tGSC_indexed%\tYandex_in%\tY_latency_med_d\tGSC_imp_med\tGSC_imp30_mean\tdesc_len_med')
    G = defaultdict(list)
    for i in active:
        if rows_filter(i): G[(cohort(i), bucket(deg[i]))].append(i)
    for c in sorted({k[0] for k in G}):
        for b in ORDER:
            ids = G.get((c, b))
            if not ids or len(ids) < 10: continue
            n = len(ids)
            st = [gstate.get(i) for i in ids]
            known = [s for s in st if s and s != 'URL is unknown to Google' and s != 'Not found (404)']
            idx = [s for s in st if s in ('Submitted and indexed', 'Served (Search Analytics)')]
            chk = [s for s in st if s]
            yin = [i for i in ids if i in yfirst]
            lat = sorted((yfirst[i] - live[i]).days for i in yin if live[i] >= YSYNC)
            med = lambda a: a[len(a)//2] if a else ''
            print(f'{c}\t{b}\t{n}\t{100*len(known)/max(len(chk),1):.0f}\t{100*len(idx)/max(len(chk),1):.0f}\t{100*len(yin)/n:.0f}\t{med(lat)}\t'
                  f'{med(sorted(imp[i] for i in ids))}\t{sum(imp30[i] for i in ids)/n:.1f}\t{med(sorted(int(active[i]["dlen"]) for i in ids))}')

print('active', len(active), 'crawled_ok', len(crawled), 'gsc_state', len(gstate), 'yandex_in', sum(1 for i in active if i in yfirst), 'YSYNC', YSYNC)
print('graph in-degree dist', Counter(bucket(gin[i]) for i in active))
if crawled: print('real (HTML) in-degree dist', Counter(bucket(rin[i]) for i in active))
table('Граф brand_related: входящие (все тиры)', gin)
if crawled: table('Реальные входящие из HTML-краула', rin)
