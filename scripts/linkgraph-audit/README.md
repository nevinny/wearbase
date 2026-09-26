# linkgraph-audit — замер эффекта графа перелинковки

Разбор и выводы: [docs/internal_linking_graph_playbook.md](../../docs/internal_linking_graph_playbook.md).

Все скрипты принимают рабочую папку `W` (внутри — `data/`). Данные в git не кладём.

```bash
W=/tmp/lg; mkdir -p $W/data
# 1. Прод: бренды, рёбра, Яндекс (export.php печатает TSV, креды из .env.local)
scp scripts/linkgraph-audit/export.php regru:wearbase.ru/var/export_tmp.php
R='cd wearbase.ru && php var/export_tmp.php'
ssh regru "$R \"SELECT b.id,b.slug,b.status,b.published_at,b.created_at,b.city,CHAR_LENGTH(COALESCE(b.description,'')) dlen,(SELECT COUNT(*) FROM brand_style_brand bs WHERE bs.brand_id=b.id) nstyles FROM brand b WHERE b.status IN ('active','disabled')\"" > $W/data/brands.tsv
ssh regru "$R \"SELECT brand_id,related_brand_id,position,source,created_at FROM brand_related\"" > $W/data/edges.tsv
ssh regru "$R \"SELECT brand_id,page_url,page_type,first_seen_at,last_checked_at FROM yandex_index_status\"" > $W/data/yidx.tsv
ssh regru "$R \"SELECT * FROM yandex_history ORDER BY day\"" > $W/data/yhist.tsv
ssh regru 'rm wearbase.ru/var/export_tmp.php'
# 2. Mac: GSC (синк app:gsc:sync живёт на Mac)
php scripts/linkgraph-audit/export.php "SELECT page_url,coverage_state,indexed,last_checked_at FROM gsc_index_status" > $W/data/gidx.tsv
php scripts/linkgraph-audit/export.php "SELECT page_url,day,impressions,clicks,position FROM gsc_page_stats WHERE query IS NULL" > $W/data/gpage.tsv
# 3. Краул реального графа (4 потока, ~20 мин на 2.8k страниц)
python3 scripts/linkgraph-audit/crawl.py $W
# 4. Отчёты
python3 scripts/linkgraph-audit/site_series.py $W | column -t
python3 scripts/linkgraph-audit/dose.py $W
```

## HADI-эксперимент с сиротами (docs/hadi_orphan_links.md)

```bash
# План (Mac, нужен Qdrant): + выгрузки borigin/mac_slugs
ssh regru "$R \"SELECT id,origin_status,niche_status FROM brand\"" > $W/data/borigin.tsv
php scripts/linkgraph-audit/export.php "SELECT id,slug FROM brand" > $W/data/mac_slugs.tsv
python3 scripts/linkgraph-audit/population.py $W        # баланс групп
python3 scripts/linkgraph-audit/orphan_plan.py $W http://192.168.2.43:6333 $QDRANT_API_KEY
scp $W/orphan_plan.json regru:wearbase.ru/var/orphan_plan.json

# Замер W2/W4/W6/W8: участники с прода + лог ботов + свежий GSC с Mac
ssh regru "$R \"SELECT b.slug,x.arm,x.assigned_at,x.baseline_state FROM link_experiment x JOIN brand b ON b.id=x.brand_id WHERE x.experiment='orphans-2026-09'\"" > $W/data/exp.tsv
scp regru:wearbase.ru/var/log/bot_brand_hits.log $W/data/bots.log
php scripts/linkgraph-audit/export.php "SELECT page_url,coverage_state,indexed,last_checked_at FROM gsc_index_status" > $W/data/gidx.tsv
php scripts/linkgraph-audit/export.php "SELECT page_url,day,impressions,clicks,position FROM gsc_page_stats WHERE query IS NULL" > $W/data/gpage.tsv
python3 scripts/linkgraph-audit/orphan_readout.py $W
```
