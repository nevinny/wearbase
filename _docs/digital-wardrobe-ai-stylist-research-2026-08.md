# Исследование цифровых гардеробов и AI-стилистов

**Дата исследования:** 19 августа 2026 года  
**Продукт:** WEARBASE / DigitalWardrobe  
**Фокус:** семейный гардероб и персональный fashion-assistant для состоятельных клиентов (HNW/UHNW)

## 1. Executive summary

Рынок цифровых гардеробов уже стандартизировал базовый набор: быстрое добавление вещей, автоматическое удаление фона и тегирование, конструктор образов, календарь, packing list, wishlist, cost per wear, погодные рекомендации и социальный доступ. AI постепенно смещается от разовой генерации картинок к постоянному контекстному помощнику, который знает реальные вещи, историю носки, планы и реакцию пользователя.

WEARBASE уже имеет необычно сильную основу именно для семьи: родительские и детские профили, управление чужим гардеробом с правами, передачу вещей внутри семьи, семейную статистику и AI-образы с обучением на реакциях. Это реальное продуктовое отличие: ни один из исследованных массовых конкурентов не строит продукт вокруг домохозяйства, роста детей, наследования вещей и совместной подготовки семьи.

Однако до продукта уровня «личный ассистент богатого человека» не хватает не ещё одного генератора образов, а **операционной системы гардероба**: календаря людей и событий, планирования поездок, состояния доступности вещей, химчистки/ремонта/ателье, ролей ассистента и стилиста, согласований, бюджета и procurement, страхового реестра дорогих вещей, истории происхождения и безопасного concierge-взаимодействия.

Главная стратегическая рекомендация: позиционировать WEARBASE как **Private Wardrobe OS for Families** — приватный семейный wardrobe concierge, который координирует людей, вещи, события, поездки, покупки и обслуживание. Массовые конкуренты оптимизируют «что надеть мне сегодня»; WEARBASE должен решать «как безошибочно подготовить всю семью к её жизни».

## 2. Методология и ограничения

Исследование основано на:

- указанной статье Beauty AI, официальных сайтах продуктов, App Store и help/FAQ-разделах;
- проверке актуального кода WEARBASE: сущностей, маршрутов, сервисов и пользовательских шаблонов;
- публично заявленных функциях на 19.08.2026; наличие функции не означает одинаковое качество её реализации;
- ценах американского App Store/официальных страниц — региональные тарифы могут отличаться;
- маркетинговые утверждения производителей считаются заявлениями, а не независимым доказательством качества AI.

Важно: статья «Лучшие AI-приложения…» опубликована самой Beauty AI, ставит собственный продукт на первое место и содержит заметные редакционные ошибки — в нескольких секциях перепутаны подписи скриншотов конкурентов. Поэтому её рейтинг нельзя использовать как независимый benchmark; полезен прежде всего список продуктов и заявленных сценариев.

Отдельная ссылка App Store `id6447482321` ведёт на **Fits – Outfit Planner & Closet**. Fits не входит в основной топ-7 статьи, но исследован отдельно как восьмой продукт.

## 3. Что уже реализовано в WEARBASE

Выводы ниже основаны на фактическом коде, а не на планах.

### Каталог и жизненный цикл вещей

- карточка вещи: категория, название, бренд, цвет, материал, страна, сезон, стили, уход, размер, цена, дата и причина покупки, URL товара, заметки, плюсы/минусы и итоговый verdict;
- несколько фотографий, обложка, загрузка JPEG/PNG/WebP;
- AI-разбор вещи по фотографии и URL;
- импорт ссылок Wildberries и получение фото;
- пакетный фото-импорт через черновики с review, accept/reject и accept all;
- поиск, фильтры, completeness/draft-статусы;
- состояния active, repair, archived, sold, donated, transferred, lost;
- отдельный детский wear-status: носится, в запасе, мало/вырос, отдано;
- soft delete, архив и восстановление;
- экспорт JSON/CSV, включая семейный архив.

### Семья

- семья с владельцем;
- роли parent/child;
- создание управляемого детского профиля без обычного email;
- дата рождения ребёнка, claim-механизм для последующей передачи аккаунта;
- приглашение взрослого или ребёнка по ссылке;
- переключение между гардеробами членов семьи;
- управление гардеробом ребёнка родителем и ограничения видимости данных взрослых;
- передача вещи между членами семьи, original owner и журнал transfer с actor/note;
- семейный dashboard и сравнение доступной статистики.

### AI и аналитика

- генерация до нескольких образов по свободному запросу из реальных вещей пользователя;
- генерация родителем образа для ребёнка/члена семьи;
- объяснение к образу;
- реакции like/dislike/worn и накопление preference context;
- статистика количества, стоимости, заполненности, категорий и сезонов;
- Telegram-сценарий добавления вещей;
- дневные лимиты и учёт AI-использования.

### Архитектурный долг, важный для продукта

- `Wardrobe` уже имеет поле `type`, но фактически определён только `personal`: это удобная точка расширения для capsule, travel, seasonal, shared, archive/vault;
- `WardrobeOutfit.items` хранится JSON snapshot: удобно для истории, но календарь, packing, wear-log и аналитика потребуют нормализованных связей outfit ↔ item;
- реакция на образ — одно значение, а для обучения полезны независимые события (сохранён, надет, отклонён, причина отказа, оценка отдельных элементов);
- family roles слишком грубые для concierge-модели: нужны granular permissions и scope по гардеробам/типам данных.

## 4. Исследование каждого продукта

## 4.1 Beauty AI

**Позиционирование.** Универсальный AI-стилист «второе мнение»: пользователь показывает готовый образ, получает оценку цвета, посадки, силуэта, формальности, обуви и аксессуаров, затем исправляет одну слабую деталь. Дополнительно заявлены цифровой гардероб, недельное планирование, поиск похожей одежды по фото, цветовой анализ, капсулы и проверка покупки.

**Сильные сценарии:**

- photo-first feedback по уже надетому образу;
- проверка соответствия событию и уровня формальности;
- вопрос «улучшает ли новая вещь мой существующий гардероб»;
- переход от вдохновения/скриншота к поиску похожей вещи;
- полный consumer-flow в одном приложении: идея → оценка → сохранение → покупка.

**Слабости и риски:**

- мало публичных доказательств глубины wardrobe management и долгосрочного обучения;
- собственная статья — конфликт интересов, а заявленные 39 оценок слишком малая выборка для лидерского вывода;
- продукт сам признаёт, что AI не заменяет глубокого human styling;
- чувствительные фотографии и внешняя AI-обработка требуют особенно ясной privacy-модели.

**Что взять WEARBASE:** отдельный режим «Оцени образ» по зеркальному фото; структурированный feedback (цвет, пропорции, dress code, погода, обувь, аксессуары); before/after версии; visual search; compatibility-check перед покупкой.

**Что не копировать буквально:** универсальный consumer-пайплайн без ролей и ответственности. Для VIP клиенту нужен не только совет, но и исполнение ассистентом.

Источник: [официальная страница AI-стилиста](https://beautyai.app/ru/ai-stylist-app), [цифровой гардероб](https://beautyai.app/ru/digital-wardrobe-app), [исходная статья](https://beautyai.app/ru/blog/best-ai-stylist-apps-2026).

## 4.2 Acloset

**Позиционирование.** AI-first smart closet: минимизировать ручную каталогизацию и ежедневно выдавать персональные рекомендации.

**Сильные сценарии:**

- фото, поиск, импорт истории покупок и Chrome extension;
- auto-split нескольких вещей на одном фото;
- автоопределение категории, цвета, сезона, паттерна и иногда бренда;
- AI Style Chat, единая история AI-анализов;
- color analysis, fit analysis и style evaluation из чата;
- ежедневные образы, включая обувь, сумки, головные уборы и украшения;
- дата/стоимость покупки, wishlist и улучшение фото.

**Модель:** free до 100 вещей; в App Store указаны Basic $3.99/мес., Premium $9.99/мес., Expert $24.99/мес. и годовые планы; также credits/beans.

**Сильная сторона.** Лучший benchmark автоматизации ingest: пользователь быстрее получает структурированный гардероб.

**Слабости.** Полезность резко зависит от полноты данных; сложная тарифная сетка и множество AI-функций создают когнитивную нагрузку; social/UGC и реклама повышают privacy-риск для VIP.

**Что взять WEARBASE:** auto-split bulk photo; browser extension; импорт чеков/истории заказов; единый AI-chat с историей; персональный профиль цвета/фигуры/посадки; аксессуары как полноценные категории.

Источник: [официальный сайт Acloset](https://www.acloset.app/), [App Store](https://apps.apple.com/us/app/acloset-ai-fashion-assistant/id1542311809).

## 4.3 Whering

**Позиционирование.** Бесплатный social wardrobe с сильным визуальным планированием и идеей «носить больше, покупать меньше».

**Сильные сценарии:**

- добавление фото, из огромной товарной базы, retailer/web import и browser extension;
- Dress Me/shuffle и ежедневный W Pick с учётом стиля и погоды;
- planner, packing, wishlist, moodboards и lookbooks;
- wear tracking, cost per wear и wardrobe insights;
- публичный/приватный гардероб, styling друзей и community inspiration;
- низкий порог входа и сильный бесплатный слой.

**Сильная сторона.** Retention строится не только на AI, но на игре, визуальном remix, социальной взаимности и повторном использовании вещей.

**Слабости.** Меньше объяснений, почему образ хорош; community-модель не подходит состоятельным клиентам по умолчанию; детальная ручная каталогизация может утомлять.

**Что взять WEARBASE:** календарь, packing, moodboard/wishlist, shuffle, weather-aware daily picks, расширенную аналитику носки; social заменить закрытым trusted circle семьи/стилиста.

Источник: [официальный сайт Whering](https://www.whering.co/), [как это работает](https://whering.co.uk/how-it-works), [weather recommendations](https://whering.co.uk/faq/outfit-suggestions-on-Whering?language=en-GB).

## 4.4 OpenWardrobe

**Позиционирование.** Полноценный wardrobe lifecycle: styling + analytics + shopping copilot + repair/resale.

**Сильные сценарии:**

- AI-распознавание атрибутов и удаление фона;
- LolaAI предлагает образы и учится на save/discard;
- Ask before you buy через Chrome extension более чем для 200 магазинов;
- календарь для отдельных вещей, outfits и зеркальных looks;
- несколько wardrobes, move/add вещи сразу в несколько гардеробов — удобно для капсул и поездок;
- batch edit образов;
- статистика использования и cost per wear;
- Style Blueprint, color и body-shape guidance;
- repair/alterations и streamlined resale;
- детальные permissions: wardrobe private by default; Style Buddy (read-only), Style Guru (read/write); отдельно на items, outfits, looks, statistics и calendar.

**Модель:** FAQ сейчас указывает free до 500 вещей, Circle — unlimited; число outfits/looks не ограничено. На части маркетинговых страниц встречается более старое обещание unlimited free — тарифы надо проверять в момент запуска сравнения.

**Сильная сторона.** Самый близкий массовый аналог «Wardrobe OS»: не только идеи, но и жизненный цикл вещи, сервисы, scoped access.

**Слабости.** Сложнее onboarding; ценность требует полного каталога; fulfillment ремонта ограничен географией партнёров.

**Что взять WEARBASE:** granular permissions; несколько логических гардеробов без дублирования вещей; shopping copilot; calendar; repair/alteration orders; resale workflow; item-level lifecycle.

Источник: [официальный сайт OpenWardrobe](https://www.openwardrobe.co/), [официальный FAQ](https://www.openwardrobe.co/faq).

## 4.5 Cladwell

**Позиционирование.** Спокойный daily assistant вокруг capsule wardrobe, погоды и intentional shopping.

**Сильные сценарии:**

- старт из 35+ шаблонов капсул и базы 15 000+ типовых вещей без обязательной фотосъёмки;
- добавление по фото, screenshot или URL и удаление фона;
- ежедневные рекомендации по погоде и активности;
- собственные mini-capsules для сезона, деятельности и поездки;
- планирование и wear tracking;
- shopping list с предварительным просмотром новых комбинаций;
- cost per wear и declutter analytics;
- Ask Cladwell на базе ChatGPT;
- один дополнительный friend account и публикация капсул.

**Модель:** управление closet бесплатно; платный слой, по официальной странице, менее $5/мес. при выбранном плане. Исходная статья указывает около $7.99/мес. или $59.99/год — данные расходятся, поэтому ориентироваться следует на checkout конкретного региона.

**Сильная сторона.** Очень ясный recurring job: утром получить пригодный образ, а не изучать сложный fashion tool.

**Слабости.** Меньше фото-feedback и продвинутой визуализации; шаблонные вещи могут давать менее точную картину реального гардероба.

**Что взять WEARBASE:** быстрый onboarding через capsule templates; режимы «школа», «офис», «дача», «спорт», «вечер»; ежедневный brief с погодой; gap-analysis капсулы.

Источник: [официальный сайт Cladwell](https://cladwell.com/app), [официальные тарифы и функции](https://cladwell.com/pricing).

## 4.6 Style DNA

**Позиционирование.** AI image consultant и personal shopper: сначала определить, что подходит человеку, затем фильтровать гардероб и покупки.

**Сильные сценарии:**

- selfie → сезонный цветотип из 12 типов и индивидуальная палитра;
- body type и рекомендации по силуэтам;
- style archetype/Kibbe-inspired profile;
- chatbot по стилю;
- пять ежедневных образов из собственного closet с учётом цвета, фигуры и стиля;
- фото вещи в магазине → match check до покупки;
- персональный shopping по 26 000 брендов и 231 retailer;
- рекомендации по принтам, тканям, цвету и посадке;
- поддержка женщин и мужчин.

**Модель:** App Store показывает $7.99/мес., несколько 3-месячных/годовых вариантов и отдельные покупки palette/body guide.

**Сильная сторона.** Структурированный personal style profile делает рекомендации объяснимыми и пригодными для shopping-фильтров.

**Слабости.** Типологии тела/стиля могут быть упрощающими и субъективными; commerce incentives способны смещать советы в сторону покупки; onboarding требует чувствительных фото и корректной работы с body image.

**Что взять WEARBASE:** Style Passport для каждого члена семьи: мерки, посадка, палитра, dress codes, preferred/forbidden бренды и ткани, сенсорные ограничения; объяснимый match score перед покупкой.

Источник: [официальный сайт Style DNA](https://styledna.ai/), [App Store](https://apps.apple.com/us/app/style-dna-ai-color-analysis/id1358319821).

## 4.7 Indyx

**Позиционирование.** Digital wardrobe с опциональным живым стилистом; сильнее остальных валидирует willingness-to-pay за human-in-the-loop.

**Сильные сценарии:**

- unlimited items/outfits/packing lists/wishlists/capsules в базовом продукте;
- auto background removal, calendar, wear/cost-per-wear sorting;
- enhanced flatlays и HD images;
- inspiration boards, virtual selfies и mirror-selfie history;
- share closet, styling друзьями и community;
- 1:1 stylist: разовые Lookbook и ongoing Feed/weekly support;
- стилист работает с реальным цифровым гардеробом клиента, а не с абстрактной анкетой.

**Модель:** digital wardrobe бесплатен; Insider открывает advanced analytics/social/selfies. Официальный FAQ указывает 1:1 styling от $60; публичная страница конкретного стилиста показывала Feed $50/мес. и Lookbook $150, но цена зависит от услуги/стилиста и может меняться.

**Сильная сторона.** Human service превращает данные closet в premium outcome и создаёт доверие там, где AI недостаточно.

**Слабости.** Ответ не мгновенный; качество зависит от конкретного стилиста; массовый marketplace не обеспечивает приватность, continuity и execution уровня family office.

**Что взять WEARBASE:** professional stylist workspace; задания и deliverables; lookbook approval; постоянный Feed/brief; комментарии по образу; оплата услуг. Для VIP — не открытый marketplace, а vetted concierge team и NDA.

Источник: [официальный сайт Indyx](https://www.myindyx.com/home), [официальный FAQ](https://www.myindyx.com/contact-us), [описание styling services](https://www.myindyx.com/blog/how-digital-styling-works-frequently-asked-questions).

## 4.8 Fits — приложение из отдельной ссылки App Store

**Позиционирование.** Визуально сильный mobile outfit planner + AI stylist. На 19.08.2026 App Store показывает версию 2.80.0, рейтинг около 4.6 при ~5 тыс. оценок; iOS/iPadOS и Android, web-версии нет.

**Сильные сценарии:**

- digital closet, фильтры, сортировка и custom tags;
- фото, camera roll, web clip, community/brand search и импорт истории retailer;
- удаление фона, AI-определение category/color/season/brand, AI-enhance;
- bulk extraction отдельных вещей из outfit photos и сканирование фототеки;
- конструктор: swipe через вещи или свободный canvas/collage;
- planner/calendar, packing и wear history;
- AI stylist/chat с историей, погода и повод;
- virtual try-on по selfie;
- moodboards, wishlist, insights;
- публичный/приватный профиль; друзья могут видеть гардероб и создавать образы в рамках permissions;
- sync между устройствами.

**Модель:** бесплатны unlimited items и background removal; App Store указывает Fits Pro $9.99/$59.99 и отдельные пакеты AI credits.

**Сильная сторона.** Лучший из рассмотренных benchmark визуального outfit creation и низкофрикционного bulk ingest. Быстрый темп релизов в июне–августе 2026 показывает активное продуктовое развитие.

**Слабости.** Нет полноценного web/desktop workspace, что критично для ассистента с большим каталогом; нет family/household model; AI credits ухудшают ощущение предсказуемого premium-service.

**Что взять WEARBASE:** извлечение вещей из старых семейных фотографий; canvas образа; virtual try-on; planner; поиск образа natural language; chat history; photo-library scan. Web-first WEARBASE может выиграть у Fits в профессиональной работе ассистента.

Источник: [App Store](https://apps.apple.com/us/app/fits-outfit-planner-closet/id6447482321), [официальный сайт Fits](https://www.fits-app.com/), [официальный help center](https://www.fits-app.com/knowledge/what-does-fits-do), [platform availability](https://www.fits-app.com/knowledge/where-can-i-use-fits).

## 5. Сравнительная матрица

Обозначения: **●** сильная/явно заявленная функция, **◐** частичная/вторичная, **—** не обнаружена в изученных официальных материалах.

| Возможность | WEARBASE сейчас | Beauty AI | Acloset | Whering | OpenWardrobe | Cladwell | Style DNA | Indyx | Fits |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Каталог реальных вещей | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| AI auto-tagging | ● | ◐ | ● | ◐ | ● | — | ◐ | ● | ● |
| Bulk ingest | ● | ◐ | ● | ◐ | ◐ | — | — | ◐ | ● |
| Генерация из своих вещей | ● | ● | ● | ● | ● | ● | ● | ◐ | ● |
| AI-chat с историей | — | ◐ | ● | — | ● | ● | ● | — | ● |
| Фото-feedback готового образа | — | ● | ● | — | ◐ | — | ◐ | ◐ | ● |
| Погода | — | ◐ | ◐ | ● | ◐ | ● | ◐ | — | ● |
| Календарь/wear log | — | ● | ◐ | ● | ● | ● | ◐ | ● | ● |
| Packing/travel | — | ● | ◐ | ● | ● | ● | ◐ | ● | ● |
| Wishlist/moodboard | — | ◐ | ● | ● | ◐ | ● | ◐ | ● | ● |
| Cost per wear | — | — | ◐ | ● | ● | ● | — | ● | ◐ |
| Проверка до покупки | — | ● | ◐ | ◐ | ● | ● | ● | — | ◐ |
| Virtual try-on | — | ◐ | ◐ | — | — | — | ◐ | ● | ● |
| Repair/alterations/resale | ◐ статусами | — | ◐ | ◐ | ● | ◐ | — | ● resale | ◐ |
| Живой стилист | — | — | — | social | role-based | friend | — | ● | friends |
| Семейные профили | **●** | — | — | — | — | ◐ 1 friend | — | — | — |
| Детский lifecycle/рост | **●** | — | — | — | — | — | — | — | — |
| Передача вещи внутри семьи | **●** | — | — | — | ◐ между closets | — | — | — | — |
| Экспорт JSON/CSV | **●** | — | — | — | ◐ | — | — | — | — |
| Granular delegated access | ◐ parent/child | — | — | ◐ | ● | ◐ | — | ◐ | ◐ |

## 6. Отдельный список функций, которых сейчас нет в WEARBASE и которые нужно добавить

Ниже «нет» означает: функция не обнаружена как законченный пользовательский сценарий в текущем коде. Поле или статус сами по себе не считаются готовой функцией.

### P0 — основа ежедневной ценности и premium delegation

1. **Wardrobe Calendar + Wear Log.** План/факт образов по дням, повторяемость, событие, место, фото результата. Это основа cost per wear, обучения и работы ассистента.
2. **События и dress-code brief.** Тип события, участники семьи, география, venue, хозяева/гости, формальность, культурные ограничения, фото-протокол, риск повторить образ перед той же аудиторией.
3. **Погода и логистика дня.** Температура, осадки, ветер, indoor/outdoor, перемещения; предупреждение, если верхняя одежда/обувь не подходит.
4. **Availability state вещи.** Доступна, надета, в стирке, химчистке, ремонте, ателье, в багаже, в другом доме, одолжена; дата возврата. Без этого AI предлагает физически недоступные вещи.
5. **Packing/Trip workspace.** Поездка, участники семьи, дни и события, лимит багажа, капсула, packing checklist, laundry/re-wear logic, прогноз, документы и last-minute gaps.
6. **Granular roles и delegation.** Owner, spouse, parent, nanny, personal assistant, stylist, tailor, house manager, viewer; scopes на конкретного человека/wardrobe и отдельно inventory, price, calendar, photos, purchases, analytics.
7. **Task/approval workflow.** Ассистент предлагает образ/покупку/отправку в ремонт → клиент approve/reject/comment → исполнитель получает задачу и deadline; immutable audit trail.
8. **Persistent concierge chat.** Не разовая форма prompt, а диалог с памятью о семье, календаре, реакциях, ограничениях и предыдущих решениях; ссылки на вещи, образы, события и задачи.
9. **Outfit editor и версии.** Canvas/drag-and-drop, заменить один элемент, несколько вариантов, before/after, сохранить, назначить человеку/событию, комментировать.
10. **Нормализованные outfits.** Связь с реальными `WardrobeItem`, версия snapshot, доступность и wear events — вместо одного JSON как единственного источника.

### P1 — дифференциация Family Wardrobe

11. **Семейный календарь подготовки.** На одном экране: кто, куда и в чём идёт; конфликты времени, недостающие элементы, готовность каждого образа.
12. **Growth & size prediction детей.** История мерок, темп роста, expected outgrow date, предупреждение до сезона/поездки/школы.
13. **Seasonal readiness ребёнка.** Чеклист по размеру и климату: куртка, обувь, форма, спорт; gap list без раскрытия лишних финансовых данных.
14. **Hand-me-down matching.** Автоподбор «кому и когда подойдёт» по размеру, сезону, возрасту, полу/предпочтениям; очередь наследования и storage location.
15. **School/sport/uniform kits.** Повторяемые наборы, расписание, количество комплектов, запас, стирка и экстренная замена.
16. **Coordinated family looks.** Гармония палитры и уровня формальности без одинаковой одежды; семейные фотосессии, свадьбы, праздники.
17. **Household locations.** Основной дом, загородный дом, яхта, самолёт, сезонное хранение, сейф; QR/NFC label и инвентаризация по месту.
18. **Family shopping cart и бюджет.** Потребность → shortlist → сравнение → approval → order → delivery → fitting → return/keep; бюджеты по человеку/категории/сезону.
19. **Privacy для подростков.** Возрастная эволюция прав: личные фото/заметки, запрос помощи родителя, approval покупок, перенос владения аккаунтом.
20. **Emergency outfit.** Готовые резервные комплекты по члену семьи и локации для внезапного события, плохой погоды или потери багажа.

### P1 — функции private-client / HNW concierge

21. **Style Passport.** Мерки с историей, preferred fit, палитра, силуэты, ткани, аллергии/сенсорные ограничения, dress codes, любимые/запрещённые бренды, heel tolerance, grooming/jewelry preferences.
22. **High-value asset registry.** Серийный номер, чек, сертификат подлинности, provenance, оценка, страховая стоимость, гарантия, состояние, secure/private photos для couture, сумок, часов и украшений.
23. **Care & service orchestration.** Регламент ухода, напоминания, заказ химчистки/ремонта/ателье, courier pickup, vendor, SLA, стоимость, статус и фото до/после.
24. **Tailoring and fitting records.** Мерки, alterations каждой вещи, любимое ателье, appointment, fitting notes, pin photos, срок готовности.
25. **Procurement copilot.** Gap-analysis, price/availability monitoring, boutique outreach, reserve item, home fitting, approval, возврат; compatibility score с текущим гардеробом.
26. **Event memory / no-repeat intelligence.** Кто видел образ, где он публиковался, embargo, запрет повторения, acceptable repeat window; особенно важно публичным персонам.
27. **Travel concierge integration.** Itinerary/import календаря, климат нескольких городов, baggage allowances, hotel delivery, customs/carnet для дорогих украшений, lost-luggage fallback.
28. **Team workspace.** Комментарии, mentions, task ownership, смены, handover notes, read receipts и activity log для стилиста/ассистента/домоуправляющего.
29. **White-glove onboarding.** Импорт ассистентом, batch station, barcode/QR, контроль дублей, confidence score AI, QA и отчёт полноты.
30. **Privacy & security premium-класса.** Private by default, field-level permissions, 2FA/passkeys, device/session management, encrypted sensitive assets, access log, watermark expiring shares, emergency revoke, retention policy.

### P2 — конкурентный parity и рост

31. **Outfit photo analysis.** Оценка цвета, пропорций, посадки, формальности, grooming и аксессуаров с конкретной заменой из собственного гардероба.
32. **Photo library scan / item extraction.** Находить старые OOTD и извлекать отдельные вещи из одного семейного фото.
33. **Auto-split bulk photos и background removal.** Несколько вещей на фото → отдельные карточки; повысит скорость white-glove onboarding.
34. **Browser extension / share-to-WEARBASE.** Сохранить вещь из любого магазина, извлечь атрибуты, проверить дубликаты и совместимость до покупки.
35. **Receipt/email/order import.** Цена, дата, retailer, артикул, фото, return window и гарантия автоматически.
36. **Wishlist и inspiration boards.** Pinterest/скриншоты, desired aesthetic, привязка идеи к реальным вещам и gap list.
37. **Capsules и несколько виртуальных wardrobes.** Сезон, поездка, офис, спорт, дом — одна вещь может входить в несколько коллекций без копирования.
38. **Cost per wear / utilization / ROI.** Стоимость владения с учётом ухода и resale; never worn, dormant, wardrobe coverage, outfit leverage.
39. **Resale/donation workflow.** Оценка, листинг, consignment, продажа, комиссия, shipping, donation receipt; текущего статуса `sold/donated` недостаточно.
40. **Virtual try-on.** Полезен как preview, но после календаря, availability и delegation: визуальная новизна не заменяет надёжную операционную пользу.
41. **Visual search.** Фото → похожая вещь у российских брендов/в гардеробе семьи; пометка already own similar.
42. **Notifications and daily brief.** Утренний family brief, готовность вещей, погода, дедлайны возврата/ателье, event preparation status.

## 7. Рекомендуемая продуктовая модель

### Не «ещё один AI-стилист», а три соединённых продукта

1. **Family Inventory Graph** — люди, вещи, размеры, места, владение, доступность, передача и обслуживание.
2. **Wardrobe Operations** — события, поездки, календарь, packing, задачи, согласования, покупки и подрядчики.
3. **Style Intelligence** — Style Passport, AI/human recommendations, feedback loop, аналитика и объяснимые решения.

Если начать только с Style Intelligence, получится эффектное демо, которое предлагает вещь из химчистки ребёнку, уже выросшему из размера. Конкурентное преимущество возникает, когда AI опирается на корректный operational context.

### North-star job

> «К нужному моменту у каждого члена семьи готов уместный, доступный и согласованный образ — без ручной координации владельца».

### Ключевой пользовательский цикл

`Календарь/поездка → brief → AI или стилист создаёт варианты → проверка доступности → клиент согласует → ассистент исполняет → факт/фото носки → обучение, cost per wear и сервисные задачи`.

### Интерфейсы по ролям

- **Клиент:** Today/This Week, 2–3 решения, approve/reject, приватный чат, минимум операционного шума.
- **Родитель:** готовность семьи, размеры/сезоны детей, покупки и передачи.
- **Ассистент:** kanban задач, календарь, локации, поставщики, дедлайны, approvals.
- **Стилист:** визуальный desktop canvas, Style Passport, каталог, события, комментарии и lookbook.
- **Service vendor:** только назначенная вещь, инструкция, pickup/SLA; никаких лишних данных клиента.

## 8. Предлагаемый roadmap

### Этап 1 — 6–8 недель: сделать AI практически надёжным

- Outfit ↔ Item relations и wear log;
- calendar/events, weather и availability;
- capsules/packing list;
- Style Passport lite;
- outfit editor с replace-one-item;
- daily brief;
- базовые роли assistant/stylist + approvals.

**Метрики:** доля запланированных образов, которые реально надеты; time-to-approved-look; недельная активность семей; процент рекомендаций без unavailable items.

### Этап 2 — 8–12 недель: family moat

- size measurements и growth prediction;
- seasonal readiness;
- hand-me-down matcher;
- uniform kits и laundry availability;
- coordinated family events;
- household locations и QR inventory.

**Метрики:** предотвращённые срочные покупки, доля вещей, переданных внутри семьи, готовность к сезону/поездке, экономия времени родителя.

### Этап 3 — 12–16 недель: private wardrobe concierge

- granular permissions, audit log, secure sharing;
- service orders: dry cleaning/repair/tailoring;
- high-value registry, insurance/provenance;
- procurement workflow и boutique/vendors;
- itinerary/travel concierge;
- professional desktop workspace и client SLA.

**Метрики:** число делегированных и завершённых задач, approval latency, service turnaround, retained wardrobe value, concierge NPS и платёж за household.

### Этап 4 — parity features

- mirror-photo feedback;
- browser extension и receipt/email import;
- photo-library extraction;
- visual search и virtual try-on;
- resale/consignment integrations.

## 9. Монетизация и упаковка

Массовая подписка конкурентов в основном лежит в диапазоне примерно $4–13/месяц; это плохой ориентир для private-client сервиса. Цена должна отражать число людей, объём активов, роли команды и SLA.

- **Family:** до 5 человек, calendar/packing/growth/hand-me-down.
- **Family Plus:** несколько домов, расширенная аналитика, assistant access.
- **Private Client:** household + stylist/assistant workspace, approvals, vendors, enhanced security.
- **Concierge Managed:** white-glove digitization, закреплённый wardrobe manager, event/travel preparation и SLA.

Отдельно монетизируются onboarding/inventory audit, human stylist, tailoring/cleaning logistics, resale/consignment и enterprise seats для personal-assistant agencies. Не следует тарифицировать VIP-диалог мелкими AI credits: для премиального продукта ценность — предсказуемость и отсутствие трения.

## 10. Главные продуктовые риски

1. **Холодный старт.** Даже лучший AI бесполезен без достаточного каталога. Нужны bulk ingest и white-glove onboarding.
2. **Ошибочная доступность.** Неверный статус стирки/локации разрушает доверие быстрее, чем посредственный стиль.
3. **Privacy.** Гардероб, цена, адреса, расписание и фото семьи вместе образуют очень чувствительный профиль.
4. **Body-image и дети.** Нельзя оценивать тело или формировать стыд; советы должны описывать посадку вещи и комфорт, а не «исправлять фигуру».
5. **AI hallucination.** Рекомендация должна ссылаться только на реальные item IDs и явно разделять факт, предположение и стилевое мнение.
6. **Скрытый commerce bias.** Gap-analysis не должен превращаться в генератор ненужных покупок; показывать «не покупать» и вещи-замены из семьи.
7. **Сложность.** Клиенту нужен calm UI; сложность должна жить в workspace ассистента.
8. **Сервисная ответственность.** Для дорогих вещей нужны chain of custody, фото состояния и audit trail.

## 11. Итог

Копирование общего списка функций Beauty AI, Acloset или Fits даст конкурентный, но недифференцированный consumer closet. Самая защищаемая территория WEARBASE уже видна в текущем коде: **семья, дети и движение вещи между людьми**. Её следует расширить до семейной операционной системы гардероба, а сверху построить human-in-the-loop concierge.

Первый приоритет — не virtual try-on. Первый приоритет: календарь + availability + поездки + роли/согласования + нормализованные образы. После этого AI начинает принимать решения, которым может доверять семья и которые способен исполнить персональный ассистент.

## 12. Реестр источников

- [Beauty AI: рейтинг 2026](https://beautyai.app/ru/blog/best-ai-stylist-apps-2026)
- [Beauty AI: AI stylist](https://beautyai.app/ru/ai-stylist-app)
- [Beauty AI: digital wardrobe](https://beautyai.app/ru/digital-wardrobe-app)
- [Acloset: официальный сайт](https://www.acloset.app/)
- [Acloset: App Store](https://apps.apple.com/us/app/acloset-ai-fashion-assistant/id1542311809)
- [Whering: официальный сайт](https://www.whering.co/)
- [Whering: how it works](https://whering.co.uk/how-it-works)
- [OpenWardrobe: официальный сайт](https://www.openwardrobe.co/)
- [OpenWardrobe: FAQ](https://www.openwardrobe.co/faq)
- [Cladwell: app](https://cladwell.com/app)
- [Cladwell: pricing](https://cladwell.com/pricing)
- [Style DNA: официальный сайт](https://styledna.ai/)
- [Style DNA: App Store](https://apps.apple.com/us/app/style-dna-ai-color-analysis/id1358319821)
- [Indyx: официальный сайт и сравнение планов](https://www.myindyx.com/home)
- [Indyx: FAQ](https://www.myindyx.com/contact-us)
- [Indyx: styling services](https://www.myindyx.com/blog/how-digital-styling-works-frequently-asked-questions)
- [Fits: App Store, id6447482321](https://apps.apple.com/us/app/fits-outfit-planner-closet/id6447482321)
- [Fits: официальный сайт](https://www.fits-app.com/)
- [Fits: официальный help center](https://www.fits-app.com/knowledge/what-does-fits-do)

