# Структурированный контекст AI-стилиста

## Eligibility и rotation

В подбор попадают только вещи одновременно в состояниях `itemStatus=active` и
`wearStatus=active`. Поэтому исключены архив, передача, резерв/«на вырост», чистка и ремонт.
Проверка выполняется внутри stylist service и не зависит от фильтра вызывающего контроллера.

Подтверждённые события `type=worn` за последние семь дней дают вещи признак
`rotation=recent`; остальные получают `rotation=fresh` и располагаются раньше в каталоге.
Модель получает указание предпочитать fresh, но recent не запрещены — это мягкая ротация.
Fitting и planned не влияют на rotation.

## Structured input

Необязательное событие выбирается только из allowlist:

`everyday`, `work`, `school`, `walk`, `celebration`, `sport`, `travel`.

Неизвестное значение преобразуется в `null`, а не попадает в prompt. Свободный пользовательский
запрос сохраняется отдельным полем с ранее установленным лимитом 300 символов.

Объяснение образа нормализуется в одну строку и ограничивается 240 символами.

## Weather boundary

`WardrobeWeatherContextProviderInterface::current()` не принимает координаты, адрес или User.
Дефолтный `NullWardrobeWeatherContextProvider` возвращает `null`; внешнего API в этом PR нет.
Даже будущая реализация сможет вернуть только одно из значений `cold|mild|hot|rain|snow|wind`.
Любая другая строка заменяется на `null`, поэтому location не отправляется модели.

Consent boundary из предыдущего среза сохраняется: structured context не разрешает remote-вызов
сам по себе, а learned context по-прежнему требует personalization consent владельца профиля.
