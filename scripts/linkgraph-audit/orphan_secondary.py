# Вторичные срезы HADI orphans-2026-09 к orphan_readout.py (те же W/data): страты по baseline, «истинно неизвестные»,
# per-protocol, чувствительность определения known, боты по окнам с перестановочным p, доза.
# Доза 0/1 — сироты без эмбеддингов (тоньше контент) → конфаундер, доза-ответ по ней не читать. Решение — только ITT в orphan_readout.py.
import csv, sys, re, random, datetime as dt
from collections import Counter, defaultdict
W = sys.argv[1]
T = lambda n: list(csv.DictReader(open(f'{W}/data/{n}.tsv'), delimiter='\t'))
exp = T('exp'); start = dt.date(2026, 9, 26)
sl = lambda u: u.split('/brands/')[1].split('?')[0].strip('/') if '/brands/' in u else None
isru = lambda u: '/ru/brands/' in u
state = {sl(r['page_url']): r for r in T('gidx') if isru(r['page_url'])}
imp_since, imp_any_loc, imp_ever = Counter(), Counter(), Counter()
for r in T('gpage'):
    s = sl(r['page_url'])
    if not s: continue
    i = int(r['impressions']); imp_ever[s] += i
    if dt.date.fromisoformat(r['day']) >= start:
        imp_any_loc[s] += i
        if isru(r['page_url']): imp_since[s] += i
UNK = (None, 'URL is unknown to Google', 'Not found (404)')
cov = lambda s: (state.get(s) or {}).get('coverage_state')
known = lambda s: cov(s) not in UNK or imp_since[s] > 0
known_cov = lambda s: cov(s) not in UNK
known_ever = lambda s: cov(s) not in UNK or imp_ever[s] > 0

# боты: дни × слаг
hits = {b: defaultdict(Counter) for b in ('Googlebot', 'YandexBot')}
rx = re.compile(r'\[(\d+/\w+/\d+):.*"GET /ru/brands/([a-z0-9-]+)[ ?].*(Googlebot|YandexBot)')
for line in open(f'{W}/data/bots.log', errors='replace'):
    m = rx.search(line)
    if m: hits[m.group(3)][m.group(2)][dt.datetime.strptime(m.group(1), '%d/%b/%Y').date()] += 1
def bot(b, s, a, z): return sum(v for d, v in hits[b][s].items() if a <= d <= z)

# доза = неоткаченные рёбра эксперимента на таргет
dose = Counter(r['target_slug'] for r in T('exp_edges') if not r['reverted_at'])
arm = {r['slug']: r['arm'] for r in exp}
base = {r['slug']: r['baseline_state'] for r in exp}
G = {a: [r['slug'] for r in exp if r['arm'] == a] for a in ('treatment', 'control')}

def perm_p(f, n=5000, seed=1):
    """двусторонний перестановочный p для разницы долей/средних f(slug) T−C"""
    sl_all = G['treatment'] + G['control']; v = {s: f(s) for s in sl_all}; nt = len(G['treatment'])
    obs = sum(v[s] for s in G['treatment']) / nt - sum(v[s] for s in G['control']) / len(G['control'])
    rnd = random.Random(seed); xs = list(sl_all); ge = 0
    for _ in range(n):
        rnd.shuffle(xs); d = sum(v[s] for s in xs[:nt]) / nt - sum(v[s] for s in xs[nt:]) / (len(xs) - nt)
        ge += abs(d) >= abs(obs) - 1e-12
    return obs, ge / n

def share(f, rows): return 100 * sum(bool(f(s)) for s in rows) / len(rows) if rows else float('nan')

print(f'GSC показы до {max(r["day"] for r in T("gpage"))} (лаг выгрузки ~3 дня)\n')
print('== Страты по baseline (pre-treatment, рандомизация внутри страты сохраняется) ==')
for name, cond in (('baseline unknown/404/пусто', lambda s: base[s] in ('', 'URL is unknown to Google', 'Not found (404)')),
                   ('baseline known', lambda s: base[s] not in ('', 'URL is unknown to Google', 'Not found (404)'))):
    t = [s for s in G['treatment'] if cond(s)]; c = [s for s in G['control'] if cond(s)]
    print(f'{name}: n {len(t)}/{len(c)}  known {share(known, t):.1f}/{share(known, c):.1f}  '
          f'cov-only {share(known_cov, t):.1f}/{share(known_cov, c):.1f}  imp>0 {share(lambda s: imp_since[s] > 0, t):.1f}/{share(lambda s: imp_since[s] > 0, c):.1f}')
pre = lambda s: imp_ever[s] - imp_any_loc[s]
t = [s for s in G['treatment'] if base[s] in ('', 'URL is unknown to Google', 'Not found (404)') and pre(s) == 0]
c = [s for s in G['control'] if base[s] in ('', 'URL is unknown to Google', 'Not found (404)') and pre(s) == 0]
print(f'истинно неизвестные (baseline unknown И 0 показов до старта): n {len(t)}/{len(c)}  known {share(known, t):.1f}/{share(known, c):.1f}  '
      f'indexed {share(lambda s: cov(s) in ("Submitted and indexed", "Served (Search Analytics)") or imp_since[s] > 0, t):.1f}/'
      f'{share(lambda s: cov(s) in ("Submitted and indexed", "Served (Search Analytics)") or imp_since[s] > 0, c):.1f}')
pp = [s for s in G['treatment'] if dose[s] >= 2]
print(f'per-protocol: T доза ≥2 n {len(pp)} known {share(known, pp):.1f} vs C {share(known, G["control"]):.1f}\n')
for name, f in (('known (primary)', known), ('known по coverage_state', known_cov), ('known + показы когда-либо, любая локаль', known_ever),
                ('показы ru с 26.09 > 0', lambda s: imp_since[s] > 0), ('показы любая локаль с 26.09 > 0', lambda s: imp_any_loc[s] > 0)):
    d, p = perm_p(lambda s: float(bool(f(s))))
    print(f'{name}: T {share(f, G["treatment"]):.1f} / C {share(f, G["control"]):.1f}  Δ {100*d:+.1f} п.п.  p={p:.2f}')
d, p = perm_p(lambda s: imp_since[s]); print(f'показы ru на страницу с 26.09: Δ {d:+.2f}  p={p:.2f}')

print('\n== Свежесть coverage_state в популяции ==')
for a in G:
    c = Counter('после старта' if (state.get(s) or {}).get('last_checked_at', '')[:10] >= str(start) else 'до старта' for s in G[a])
    print(a, dict(c))

print('\n== Боты по окнам: страниц с ≥1 заходом %, заходов/стр ==')
wins = [('до старта 16–25.09', dt.date(2026, 9, 16), dt.date(2026, 9, 25)),
        ('W0–W2 26.09–09.10', dt.date(2026, 9, 26), dt.date(2026, 10, 9)),
        ('W2–W4 10.10–23.10', dt.date(2026, 10, 10), dt.date(2026, 10, 23)),
        ('весь пост 26.09–23.10', dt.date(2026, 9, 26), dt.date(2026, 10, 23))]
for b in ('YandexBot', 'Googlebot'):
    for wn, a, z in wins:
        d1, p1 = perm_p(lambda s: float(bot(b, s, a, z) > 0)); d2, p2 = perm_p(lambda s: bot(b, s, a, z))
        t1 = share(lambda s: bot(b, s, a, z) > 0, G['treatment']); c1 = share(lambda s: bot(b, s, a, z) > 0, G['control'])
        mt = sum(bot(b, s, a, z) for s in G['treatment']) / len(G['treatment']); mc = sum(bot(b, s, a, z) for s in G['control']) / len(G['control'])
        print(f'{b:9s} {wn:24s} стр≥1: {t1:5.1f}/{c1:5.1f} Δ{100*d1:+5.1f} p={p1:.2f} | зах/стр {mt:.3f}/{mc:.3f} p={p2:.2f}')

print('\n== Доза (treatment по числу эксп. рёбер) vs control ==')
a, z = dt.date(2026, 9, 26), dt.date(2026, 10, 23)
groups = defaultdict(list)
for s in G['treatment']: groups[f'T dose {min(dose[s], 3)}'].append(s)
groups['C'] = G['control']
for k in sorted(groups):
    r = groups[k]
    print(f'{k:10s} n={len(r):4d} known {share(known, r):5.1f}  Yandex стр≥1 {share(lambda s: bot("YandexBot", s, a, z) > 0, r):5.1f}  '
          f'Yandex зах/стр {sum(bot("YandexBot", s, a, z) for s in r)/len(r):.2f}  Google стр≥1 {share(lambda s: bot("Googlebot", s, a, z) > 0, r):4.1f}')

print('\n== Лог ботов по дням (все карточки) — дыры ==')
days = Counter()
for b in hits:
    for s in hits[b]:
        for d, v in hits[b][s].items(): days[d] += v
print(' '.join(f'{d:%d.%m}:{days[d]}' for d in sorted(days) if d >= dt.date(2026, 10, 8)))
