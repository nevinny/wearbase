# Популяция эксперимента с сиротами + баланс групп T/C
import csv,sys,hashlib,datetime as dt
from collections import Counter,defaultdict
S=sys.argv[1]; T=lambda n: list(csv.DictReader(open(f'{S}/data/{n}.tsv'),delimiter='\t'))
b={r['id']:r for r in T('brands')}; og={r['id']:r for r in T('borigin')}
act={i for i,r in b.items() if r['status']=='active'}
slug2id={b[i]['slug']:i for i in act}
rin=Counter()
for line in open(f'{S}/data/crawl.tsv'):
    s,code,links=line.rstrip('\n').split('\t')
    for l in filter(None,links.split(',')):
        if l in slug2id: rin[slug2id[l]]+=1
sl=lambda u:u.split('/ru/brands/')[1].strip('/')
st={sl(r['page_url']):r['coverage_state'] for r in T('gidx') if '/ru/brands/' in r['page_url']}
live=lambda i: dt.date.fromisoformat((b[i]['published_at'] or b[i]['created_at'])[:10])
cut=dt.date(2026,9,26)-dt.timedelta(14)
pop=[i for i in act if rin[i]==0 and og[i]['niche_status']!='off' and og[i]['origin_status']!='foreign' and live(i)<=cut]
excl=Counter()
for i in act:
    if rin[i]!=0: continue
    if og[i]['niche_status']=='off': excl['niche off']+=1
    elif og[i]['origin_status']=='foreign': excl['foreign']+=1
    elif live(i)>cut: excl['fresh<14d']+=1
arm=lambda i: 'T' if int(hashlib.md5(f'orph-2026-09:{i}'.encode()).hexdigest(),16)%2==0 else 'C'
print('real-HTML orphans',sum(rin[i]==0 for i in act),'excluded',dict(excl),'population',len(pop))
G=defaultdict(list)
for i in pop: G[arm(i)].append(i)
def row(ids):
    s=[st.get(b[i]['slug']) for i in ids]
    kn=sum(x not in (None,'URL is unknown to Google','Not found (404)') for x in s)
    ix=sum(x in ('Submitted and indexed','Served (Search Analytics)') for x in s)
    dl=sorted(int(b[i]['dlen']) for i in ids)
    return f"n={len(ids)} known={100*kn/len(ids):.1f}% indexed={100*ix/len(ids):.1f}% desc_med={dl[len(dl)//2]}"
for a in 'TC': print(a,row(G[a]))
print('publish week distribution (T/C):')
W=defaultdict(Counter)
for i in pop: d=live(i); W[d-dt.timedelta(d.weekday()) if d>=dt.date(2026,6,12) else 'pre-graph'][arm(i)]+=1
for w in sorted(W,key=str): print(' ',w,W[w]['T'],W[w]['C'])
open(f'{S}/data/population.tsv','w').write('slug\tprod_id\tarm\tbaseline_state\n'+''.join(f"{b[i]['slug']}\t{i}\t{arm(i)}\t{st.get(b[i]['slug'],'')}\n" for i in pop))
