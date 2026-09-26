# Замер HADI-эксперимента с сиротами (docs/hadi_orphan_links.md). ITT по назначенной группе.
# W/data: exp.tsv (прод link_experiment + slug), gidx.tsv / gpage.tsv (Mac GSC), bots.log (прод var/log/bot_brand_hits.log)
# known   := последний coverage_state не unknown/404  ИЛИ  показы в GSC с даты назначения
# indexed := coverage_state ∈ {Submitted and indexed, Served (Search Analytics)}  ИЛИ  показы с даты назначения
# (инспекция GSC перестаёт перепроверять страницу, как только у неё появились показы → без «ИЛИ» статус замерзает)
import csv, sys, math, re, datetime as dt
from collections import Counter, defaultdict

W = sys.argv[1]
T = lambda n: list(csv.DictReader(open(f'{W}/data/{n}.tsv'), delimiter='\t'))
exp = T('exp')
start = min(dt.date.fromisoformat(r['assigned_at'][:10]) for r in exp)
sl = lambda u: u.split('/ru/brands/')[1].split('?')[0].strip('/') if '/ru/brands/' in u else None
state = {sl(r['page_url']): r['coverage_state'] for r in T('gidx') if sl(r['page_url'])}
imp = Counter()
for r in T('gpage'):
    if sl(r['page_url']) and dt.date.fromisoformat(r['day']) >= start: imp[sl(r['page_url'])] += int(r['impressions'])
bots = defaultdict(Counter)
try:
    rx = re.compile(r'\[(\d+/\w+/\d+):.*"GET /ru/brands/([a-z0-9-]+)[ ?].*(Googlebot|YandexBot)')
    for line in open(f'{W}/data/bots.log', errors='replace'):
        m = rx.search(line)
        if m and dt.datetime.strptime(m.group(1), '%d/%b/%Y').date() >= start: bots[m.group(3)][m.group(2)] += 1
except FileNotFoundError:
    pass

def metrics(rows):
    k = [r for r in rows if state.get(r['slug']) not in (None, 'URL is unknown to Google', 'Not found (404)') or imp[r['slug']] > 0]
    x = [r for r in rows if state.get(r['slug']) in ('Submitted and indexed', 'Served (Search Analytics)') or imp[r['slug']] > 0]
    kb = [r for r in rows if r['baseline_state'] not in ('', 'URL is unknown to Google', 'Not found (404)')]
    return len(rows), len(k) / len(rows), len(x) / len(rows), len(kb) / len(rows)

G = defaultdict(list)
for r in exp: G[r['arm']].append(r)
week = (dt.date.today() - start).days / 7
print(f'Эксперимент с {start}, неделя {week:.1f}')
print('arm\tn\tknown%\tindexed%\tknown_baseline%\tимп_сумма\tимп>0\tGooglebot\tYandexBot')
res = {}
for a in ('treatment', 'control'):
    rows = G[a]; n, k, x, kb = metrics(rows); res[a] = (n, k, x, kb)
    print(f"{a}\t{n}\t{100*k:.1f}\t{100*x:.1f}\t{100*kb:.1f}\t{sum(imp[r['slug']] for r in rows)}\t"
          f"{sum(imp[r['slug']] > 0 for r in rows)}\t{sum(bots['Googlebot'][r['slug']] for r in rows)}\t{sum(bots['YandexBot'][r['slug']] for r in rows)}")

(nt, kt, xt, kbt), (nc, kc, xc, kbc) = res['treatment'], res['control']
d = (kt - kbt) - (kc - kbc)                     # diff-in-diff по known относительно baseline
se = math.sqrt(kt * (1 - kt) / nt + kc * (1 - kc) / nc)
print(f'\nknown: T−C = {100*(kt-kc):+.1f} п.п. (±{196*se:.1f} п.п. 95%), diff-in-diff к baseline = {100*d:+.1f} п.п.')
print(f'indexed: T−C = {100*(xt-xc):+.1f} п.п.')
if week >= 6:
    v = 'VALIDATED' if kt - kc >= 0.10 else 'REVERT' if kt - kc <= -0.05 else 'INCONCLUSIVE → продлить до W10' if abs(kt - kc) <= 0.05 else 'между порогами → продлить до W10'
    print('Решение по правилам карточки:', v)
