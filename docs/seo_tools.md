# SEO Tools — WEARBASE

**Источник:** SEO Guide 2026
**Обновлено:** 2026-05-14

---

## Content Generation Pipeline

| Script | Path | Purpose |
|--------|------|---------|
| `content_pipeline.py` | `scripts/content_pipeline.py` | Полный pipeline: Keyword→RAG→Outline→Draft→35-check→Self-heal→Publish |
| `pseo_generator.py` | `scripts/pseo_generator.py` | Programmatic SEO: генерация лендингов по шаблонам с уникальными фактами |
| `rag_knowledge_builder.py` | `scripts/rag_knowledge_builder.py` | RAG knowledge base builder для LLM context |

---

## Quality Assurance

| Script | Path | Purpose |
|--------|------|---------|
| `checklist.py` | `scripts/checklist.py` | **35-check gate** для AI контента. Pass если SB≥7, RV≥7, HL≥8, thin<4 |
| `ai_detector.py` | `scripts/ai_detector.py` | Быстрый AI сканер: TIER-1/TIER-2 слова, transitions, entropy |
| `full_audit.py` | `scripts/full_audit.py` | Полный аудит: checklist + ai_detector + schema + information_gain |
| `information_gain.py` | `scripts/information_gain.py` | Проверка originalitа: proprietary data, quotes, cross-page similarity |

---

## Technical SEO

| Script | Path | Purpose |
|--------|------|---------|
| `technical_audit.py` | `scripts/technical_audit.py` | robots.txt, sitemap, canonical, meta robots, JSON-LD, hreflang, CWV |
| `schema_validator.py` | `scripts/schema_validator.py` | JSON-LD validation: required fields, absolute URLs, dates, @id |
| `hreflang_audit.py` | `scripts/hreflang_audit.py` | Проверка hreflang: bidirectional, self-reference, absolute URLs |
| `trailing_slash_audit.py` | `scripts/trailing_slash_audit.py` | Canonical/sitemap/served URL mismatches |
| `indexing_api_client.py` | `scripts/indexing_api_client.py` | Google Indexing API: 200/day limit, URL_UPDATED/URL_DELETED |

---

## Monitoring & Analytics

| Script | Path | Purpose |
|--------|------|---------|
| `gsc_coverage.py` | `scripts/gsc_coverage.py` | GSC coverage мониторинг: crawled-not-indexed vs soft-404 |
| `manual_action_scanner.py` | `scripts/manual_action_scanner.py` | Detection Manual Actions из GSC |
| `penalty_triage.py` | `scripts/penalty_triage.py` | Penalty recovery triage decision tree |
| `ai_visibility_check.py` | `scripts/ai_visibility_check.py` | AI Overviews tracking для target queries |
| `update_tracker.py` | `scripts/update_tracker.py` | Algorithm update log |
| `ga4_engagement.py` | `scripts/ga4_engagement.py` | GA4: engagement rate >50%, scroll depth >75% |

---

## Content Quality

| Script | Path | Purpose |
|--------|------|---------|
| `eeat_audit.py` | `scripts/eeat_audit.py` | E-E-A-T scoring: Author Bio, sameAs, citations, Trust pages |
| `geo_extractability.py` | `scripts/geo_extractability.py` | GEO extractability score: paragraph structure для AI Overviews |
| `cross_page_similarity.py` | `scripts/cross_page_similarity.py` | Scaled content detection: >0.85 similarity = doorway |
| `translation_validator.py` | `scripts/translation_validator.py` | Translation quality: title/body sim <70%, lang detection |
| `fake_translation_detector.py` | `scripts/fake_translation_detector.py` | Fake translation detection via isReal field |

---

## Keyword & Link Building

| Script | Path | Purpose |
|--------|------|---------|
| `keyword_clusterer.py` | `scripts/keyword_clusterer.py` | Keyword clustering via embeddings, K-means/HDBSCAN, intent |
| `backlink_quality.py` | `scripts/backlink_quality.py` | Backlink analysis: spammy TLDs для disavow |
| `reference_validator.py` | `scripts/reference_validator.py` | Source sanity check перед генерацией |

---

## Self-Learning & Optimization

| Script | Path | Purpose |
|--------|------|---------|
| `self_learning.py` | `scripts/self_learning.py` | L1 (RAG), L2 (prompt evolution), L3 (LoRA dataset) |
| `prompt_evolver.py` | `scripts/prompt_evolver.py` | Prompt optimization: hash→opt_score→human_pct |
| `queue_keeper.py` | `scripts/queue_keeper.py` | Queue maintenance 24/7 |
| `avi_calculator.py` | `scripts/avi_calculator.py` | Article Value Index: semantic, E-E-A-T, engagement, readability |

---

## Media & Assets

| Script | Path | Purpose |
|--------|------|---------|
| `photo_verify.py` | `scripts/photo_verify.py` | 7-level photo verification: HTTP→Size→Magic→Dimensions→Face |
| `yt_seo.py` | `scripts/yt_seo.py` | YouTube SEO: video optimization, sitemap |
| `news_sitemap.py` | `scripts/news_sitemap.py` | Google News sitemap: 48h max age |

---

## Utilities

| Script | Path | Purpose |
|--------|------|---------|
| `site_init.py` | `scripts/site_init.py` | New site setup: SEO structure initialization |
| `llms_txt_gen.py` | `scripts/llms_txt_gen.py` | llms.txt generator для LLM-friendly content |
| `nap_consistency.py` | `scripts/nap_consistency.py` | Local SEO: NAP distribution verification |
| `product_feed_audit.py` | `scripts/product_feed_audit.py` | E-commerce: Product+Offer+AggregateRating schema |
| `dpo_collector.py` | `scripts/dpo_collector.py` | DPO dataset builder для fine-tuning |

---

## Commercial Tools

| Tool | Purpose | Monthly Cost |
|------|---------|--------------|
| Google Search Console | SEO signals | $0 |
| Google Analytics 4 | User behavior | $0 |
| Ahrefs | Backlinks/keywords | $99-$199 |
| SEMrush | Alt for Ahrefs + PPC | $139-$249 |
| Screaming Frog | Technical crawler | $22 |
| ChatGPT Plus / Claude Pro | AI assistance | $40 |
| OpenRouter | LLM routing | $200-$500 |
| OriginalityAI | AI detection | $15 |
| Winston AI | AI detection | $12 |

### Baseline Stack
~**$280/mo** (GSC, GA4, Ahrefs $99, Screaming Frog $22, ChatGPT $40, OpenRouter $100)

### Production Stack
~**$2600/mo** (full stack + additional services)

---

## WEARBASE Implementation

### Implemented
- `LlmService.php` — OpenRouter API (Claude Haiku)
- `ContentValidator.php` — AI-phrase detection, word count
- `GenerateBrandContentCommand.php` — batch generation с retry
- `CheckBrandContentCommand.php` — quality check с JSON export
- `SitemapController.php` — sitemap.xml
- Schema.org: Organization, BreadcrumbList, WebSite

### Planned
- [ ] `ai_detector.py` интеграция в PHP validator
- [ ] `technical_audit.py` интеграция в CI/CD
- [ ] GSC API integration для coverage monitoring
- [ ] `rag_knowledge_builder.py` для brand data
- [ ] VK API parsing для RAG source