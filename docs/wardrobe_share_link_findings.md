# Находка: ссылка на гардероб непередаваема (09.09.2026)

## Симптом
Пользователи копируют URL своего гардероба из адресной строки и шлют в мессенджер.
3 случая в логах прода (31.08–10.09), все с подписью iMessage-превью:
- 01.09 22:09 94.29.29.123  /account/wardrobe            -> 302 /login
- 07.09 09:10 31.173.85.108 /account/wardrobe            -> 302 /login
- 09.09 01:15 94.29.18.29   /account/wardrobe?member=67  -> 302 /login  (client 67, dusya_@inbox.ru)
Ни с одного IP не было POST /login — получатели внутрь не заходили.

## Корень (баг)
templates/account/wardrobe/index.html.twig:6-8
  {% set hasFamily = members is defined and currentMember is defined %}   # всегда true:
  {% set memberParam = hasFamily ? {member: currentMember.id} : {} %}     # контроллер всегда
                                                                          # отдаёт обе переменные
                                                                          # (WardrobeController.php:89-90)
Комментарий выше признаётся: «Гварды на переходный момент» — переходный момент прошёл,
гвард остался. Итог: ?member=<свой id> липнет ко ВСЕМ ссылкам и в адресную строку,
даже когда смотришь свой гардероб.

Контроллер делает РОВНО НАОБОРОТ (WardrobeController.php:912-921):
  memberQuery(): свой id -> [], чужой -> ['member' => id]
Плюс шаблон уже вычислил ownWardrobe (строка 7), но не использует его в memberParam.

## Почему это хуже, чем «нужен логин»
member=67 — персональный id. FamilyService::canManage() пускает только родителя к ребёнку.
=> получатель ссылки, даже залогинившись или зарегистрировавшись, получает 403,
а не гардероб (LoginSuccessHandler возвращает на сохранённый target_path).
Ссылка нерабочая для кого угодно, кроме владельца.

## Чего нет
- Публичного просмотра гардероба нет вообще: ни share_token, ни public_slug, ни isPublic
  в Wardrobe/WardrobeItem/User; нет маршрутов wardrobe_share/public_wardrobe.
- Кнопки «поделиться» на самой странице гардероба нет (grep по index/show/statistics — 0).
  Взять правильную ссылку физически неоткуда, кроме адресной строки.
- Инвайты семьи (FamilyInvite) письмом НЕ отправляются — только «скопировать» вручную.

## Что уже работает и годится как образец
/l/{token} — LookShareController: публичный шеринг ОДНОГО образа, TTL 24h/7d/30d,
статусы active/pending_parent/revoked, viewCount, бренды скрыты, имена не рендерятся,
и он единственный в коде знает про facebookexternalhit (BOT_UA_RE, :37).
Но до него надо сначала собрать образ.

## Побочная находка: приватные разделы открыты роботам
- Метрика подключена и в ЛК: templates/tailwind/app.html.twig:21
- robots.txt: нет Disallow на /account, /cart, /checkout, /brand
- Яндекс краулит приватные URL (87.250.224.x, 5.255.231.x, 95.108.213.x).
  За 10 дней: 254 хита /cart/count, 151 /login, ~50 по /account/* и /brand/*
  (включая /brand/products/9/edit, /brand/products/import, /brand/dashboard).
- Утечки нет (всё 302, meta noindex стоит), но noindex робот прочитать не может —
  его редиректит. Нужен именно Disallow.

## Оговорка про масштаб
Все 3 живых гардероба на проде — семейные аккаунты (52/56/59). У client 67 гардероба нет
— она делилась пустым. Отделить своё тестирование от чужого по логам нельзя.
