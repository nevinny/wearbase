# План HADI-эксперимента с сиротами графа (docs/hadi_orphan_links.md).
# Mac: нужен Qdrant (эмбеддинги брендов) + выгрузки прода/GSC в W/data (см. README).
# Выход: W/orphan_plan.json (ключи — slug'и) → на проде app:linkgraph:orphan-experiment apply.
#
# Популяция: активные бренды с 0 входящих ссылок в РЕАЛЬНОМ HTML (crawl.tsv), не off-niche,
# не foreign, опубликованы > 14 дней назад. Группа — md5(соль:prod_id) % 2 (детерминированно).
# Доноры по ярусам (treatment = только входящие; control не донор и не таргет):
#   free   — не из популяции, известен Google, есть свободный слот (out-degree < 12)
#   fill   — не из популяции, известен Google, есть fill-ребро (вытесняем)
#   orphan — другая treatment-сирота со свободным слотом; только если доза < 2 после free/fill
# Цель 3 входящих, порог сходства SCORE_MIN.
import csv, sys, json, hashlib, datetime as dt, urllib.request
from collections import Counter, defaultdict

W, QURL, QKEY = sys.argv[1:4]
EXPERIMENT, SALT, SCORE_MIN, DOSE, TODAY = 'orphans-2026-09', 'orph-2026-09', 0.6, 3, dt.date(2026, 9, 26)
T = lambda n: list(csv.DictReader(open(f'{W}/data/{n}.tsv'), delimiter='\t'))

def qdrant(path, body):
    r = urllib.request.Request(QURL + path, json.dumps(body).encode(), {'Content-Type': 'application/json', 'api-key': QKEY})
    return json.load(urllib.request.urlopen(r, timeout=60))['result']

b = {r['id']: r for r in T('brands')}
og = {r['id']: r for r in T('borigin')}
act = {i for i, r in b.items() if r['status'] == 'active'}
slug2id = {b[i]['slug']: i for i in act}
mac = {r['slug']: int(r['id']) for r in T('mac_slugs')}
mac2prod = {mac[b[i]['slug']]: i for i in act if b[i]['slug'] in mac}

rin = Counter()
for line in open(f'{W}/data/crawl.tsv'):
    s, code, links = line.rstrip('\n').split('\t')
    for l in filter(None, links.split(',')):
        if l in slug2id: rin[slug2id[l]] += 1

sl = lambda u: u.split('/ru/brands/')[1].strip('/')
state = {sl(r['page_url']): r['coverage_state'] for r in T('gidx') if '/ru/brands/' in r['page_url']}
known = lambda i: state.get(b[i]['slug']) not in (None, 'URL is unknown to Google', 'Not found (404)')
live = lambda i: dt.date.fromisoformat((b[i]['published_at'] or b[i]['created_at'])[:10])

pop = [i for i in act if rin[i] == 0 and og[i]['niche_status'] != 'off' and og[i]['origin_status'] != 'foreign'
       and live(i) <= TODAY - dt.timedelta(14)]
arm = {i: 'treatment' if int(hashlib.md5(f'{SALT}:{i}'.encode()).hexdigest(), 16) % 2 == 0 else 'control' for i in pop}
treat = sorted((i for i in pop if arm[i] == 'treatment'), key=lambda i: hashlib.md5(f'order:{i}'.encode()).hexdigest())

E = [e for e in T('edges') if e['brand_id'] in act]
out = Counter(e['brand_id'] for e in E)
fill = Counter(e['brand_id'] for e in E if e['source'] == 'fill')
popset = set(pop)
free_cap = {i: 12 - out[i] for i in act if i not in popset and known(i) and out[i] < 12}
fill_cap = {i: fill[i] for i in act if i not in popset and known(i) and fill[i]}
orph_cap = {i: 12 - out[i] for i in treat if out[i] < 12}

donor_mac = [mac[b[i]['slug']] for i in set(free_cap) | set(fill_cap) | set(orph_cap) if b[i]['slug'] in mac]
cands = {}
for n, i in enumerate(treat):
    m = mac.get(b[i]['slug'])
    pts = qdrant('/collections/brand_chunks/points/scroll', {'filter': {'must': [{'key': 'brand_id', 'match': {'value': m}}]},
                 'limit': 16, 'with_vector': True, 'with_payload': False})['points'] if m else []
    if not pts:
        cands[i] = []; continue
    dim = len(pts[0]['vector'])
    mean = [sum(p['vector'][k] for p in pts) / len(pts) for k in range(dim)]
    hits = qdrant('/collections/brand_chunks/points/search', {'vector': mean, 'limit': 150, 'with_payload': True,
                  'filter': {'must': [{'key': 'brand_id', 'match': {'any': donor_mac}}]}})
    best = {}
    for h in hits:
        d = mac2prod.get(h['payload']['brand_id'])
        if d and d != i and h['score'] >= SCORE_MIN: best[d] = max(best.get(d, 0), h['score'])
    cands[i] = sorted(best.items(), key=lambda x: -x[1])
    if n % 100 == 0: print(f'  qdrant {n}/{len(treat)}', file=sys.stderr)

dose, edges, taken = Counter(), [], set()
def give(i, tiers):
    for d, s in cands[i]:
        if (d, i) in taken: continue
        for tier, cap in tiers:
            if cap.get(d, 0) > 0:
                cap[d] -= 1; taken.add((d, i)); dose[i] += 1
                edges.append({'target': b[i]['slug'], 'donor': b[d]['slug'], 'tier': tier, 'score': round(s, 3)})
                return True
    return False

for _ in range(DOSE):                      # ярусы free/fill — по кругу, чтобы доза распределялась ровно
    for i in treat:
        if dose[i] < DOSE: give(i, [('free', free_cap), ('fill', fill_cap)])
dose12 = Counter(min(dose[i], DOSE) for i in treat)
for _ in range(2):                         # ярус orphan — только добор до 2
    for i in treat:
        if dose[i] < 2: give(i, [('orphan', orph_cap)])

plan = {'experiment': EXPERIMENT, 'built_at': str(TODAY), 'score_min': SCORE_MIN,
        'population': [{'slug': b[i]['slug'], 'arm': arm[i], 'baseline_state': state.get(b[i]['slug'], '')} for i in pop],
        'edges': edges}
json.dump(plan, open(f'{W}/orphan_plan.json', 'w'), ensure_ascii=False, indent=1)
print('population', len(pop), Counter(arm.values()))
print('dose after free/fill', dict(sorted(dose12.items())))
print('dose final', dict(sorted(Counter(min(dose[i], DOSE) for i in treat).items())))
print('edges by tier', Counter(e['tier'] for e in edges), 'no embeddings', sum(1 for i in treat if not cands[i]))
