# Обезличивание Webasyst (standalone)

Этот репозиторий содержит отдельную PHP-страницу для обезличивания старых персональных данных Webasyst/Shop-Script напрямую в MySQL.

Это не Webasyst-плагин. Не устанавливайте инструмент через Webasyst Installer, hooks, регистрацию плагина или backend routing. Старый код плагина оставлен только в `legacy-plugin/` для справки.

## Развертывание

Пример пути на Beget:

```text
/home/d/dtalke/shop.incyber.ru/public_html/test/tools/depersonalizer/
```

Пример URL:

```text
https://shop.incyber.ru/test/tools/depersonalizer/index.php
```

Сначала используйте тестовую базу:

```text
dtalke_test_shop
```

## Локальная конфигурация

Создайте `config.local.php` из `config.local.php.example`. Не добавляйте `config.local.php` в git.

```php
<?php
return array(
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'dtalke_test_shop',
    'user'     => 'dtalke_test_shop',
    'password' => 'REPLACE_WITH_REAL_PASSWORD',
    'charset'  => 'utf8mb4',
    'socket'   => '',

    'access_token' => 'REPLACE_WITH_LONG_RANDOM_ACCESS_TOKEN',
    'allow_prod' => false,
);
```

Загрузчик также понимает вложенный формат `db` и Webasyst `wa-config/db.php`, но внутри нормализует настройки в плоский формат.

## Beget / PHP

Веб-сервер для этой папки может работать на PHP 8.1, а стандартная CLI-команда `php` на Beget может быть PHP 5.6. Для проверки синтаксиса используйте явный PHP 8.1:

```bash
cd /home/d/dtalke/shop.incyber.ru/public_html/test/tools/depersonalizer
/usr/local/php-cgi/8.1/bin/php -l index.php
/usr/local/php-cgi/8.1/bin/php -l src/StandaloneConfigLoader.php
/usr/local/php-cgi/8.1/bin/php -l src/StandaloneDepersonalizer.php
```

SQL остается совместимым с MySQL 5.7. Не добавляйте CTE, window functions, `JSON_TABLE`, `REGEXP_REPLACE` и MySQL 8-only collations.

## Безопасность

- Закройте папку через BasicAuth или IP allowlist до публичного открытия.
- Пример BasicAuth лежит в `.htaccess.example`; он предполагает, что `/home/d/dtalke/.htpasswd` уже создан.
- Удалите `phpver.php` из папки развертывания.
- Не оставляйте debug output включенным.
- Не коммитьте `config.local.php`, `logs/`, `storage/`, `.htpasswd`, дампы базы или реальные учетные данные.
- Ротируйте учетные данные, если они попадали в чаты, скриншоты или логи.
- Перед любым запуском без dry-run сделайте свежую резервную копию базы.
- Реальный запуск требует отметку о резервной копии и точную фразу `ANONYMIZE`.
- PROD-похожие цели заблокированы, пока в локальной конфигурации явно не указано `'allow_prod' => true`.

## Рабочий порядок

1. Откройте страницу и введите токен доступа.
2. Убедитесь, что бейдж окружения показывает `TEST`.
3. Убедитесь, что БД равна `dtalke_test_shop`.
4. Запустите `Предпросмотр` с настройкой 365 дней по умолчанию.
5. Проверьте найденные поля `shop_order_params` и снимите отметку с технических полей.
6. Оставьте включенным `Пробный запуск без записи` и нажмите `Запустить`.
7. Проверьте ход выполнения и логи.
8. Только после проверки логов отключите dry-run, отметьте резервную копию, введите `ANONYMIZE` и выполните реальный запуск.

После каждого реального запуска поле `ANONYMIZE` очищается автоматически, чтобы страница не оставалась подготовленной к повторному изменению базы. Состояние чекбокса резервной копии при этом не меняется.

У настроек с чекбоксами есть подсказки: наведите курсор или переведите фокус на настройку, и через примерно 2 секунды появится пояснение.

## Политика плейсхолдеров

Новые обезличенные значения не должны выглядеть как реальные контактные данные:

- имя контакта: `Обезличен`;
- email заказов: `obezlicheno+order_<ORDER_ID>@obezlicheno.invalid`;
- email контактов: `obezlicheno+contact_<CONTACT_ID>_<ROW_ID>@obezlicheno.invalid`;
- телефоны заказов: `obezlicheno-order-<hash>`;
- телефоны контактов: `obezlicheno-contact-<hash>`;
- user agent: `обезличено`.

Домен `.invalid` зарезервирован как недоставляемый. Инструмент не использует домен магазина и не создает доставляемые адреса.

При запуске инструмент также нормализует старые значения, которые были сгенерированы предыдущими версиями самого инструмента:

- `Deleted` превращается в `Обезличен`;
- `anon+contact_...@example.invalid` и `anon+order_...@example.invalid` переносятся на `obezlicheno.invalid`;
- `anon-contact-...` и `anon-order-...` получают префиксы `obezlicheno-contact-...` и `obezlicheno-order-...`.

Нормализация применяется только к явно распознаваемым старым плейсхолдерам. В dry-run она только считается и показывается в предпросмотре/логах, без записи в базу.

## Догоняющая обработка контактов

Используйте режим `Только контакты по уже обработанным старым заказам`, если старые заказы уже были обезличены без обработки контактов.

Этот режим:

- ищет контакты по всем старым заказам до даты отсечения, даже если сами заказы уже помечены как обработанные;
- не изменяет заказы и не пишет в `shop_order_params`;
- пропускает контакты, у которых есть заказы новее выбранного срока хранения;
- пропускает уже обработанные контакты по таблице `depersonalizer_state`;
- пропускает staff/backend/user/login контакты по существующей защите;
- соблюдает dry-run и реальные защиты с резервной копией, `ANONYMIZE` и `allow_prod`;
- при реальном запуске записывает состояние обработанных контактов в `depersonalizer_state`.

## История выполнения заказов

Даже после обезличивания параметров заказа и контактов персональные данные могут оставаться в истории выполнения заказа: в текстах действий, комментариях, именах операторов/покупателей, номерах отправлений и других свободных полях.

Для этого есть отдельный чекбокс `Обезличить историю выполнения заказов`.

Он:

- работает по старым заказам до даты отсечения;
- может запускаться после того, как сами заказы уже помечены обработанными;
- не удаляет строки истории;
- не меняет ID заказа, статус, workflow, даты, суммы, товары, позиции, оплаты, тарифы доставки и отчеты;
- обновляет только безопасно распознанные текстовые колонки таблицы `shop_order_log`, если таблица и такие колонки существуют;
- заменяет содержимое выбранных текстовых колонок на `Запись истории заказа обезличена`;
- ставит отдельные маркеры в `shop_order_params`:
  - `_depersonalizer_order_log_processed = 1`
  - `_depersonalizer_order_log_processed_at = timestamp`

Сначала запускайте этот режим с включенным `Пробный запуск без записи`: предпросмотр и пакетные логи покажут, сколько строк истории будет обработано. Если в схеме `shop_order_log` нет безопасных текстовых колонок, инструмент покажет это в интерфейсе и не будет менять историю.

## Что изменяет инструмент

- Заказы выбираются из `shop_order` по условию `create_datetime < cutoff`.
- Уже обработанные заказы пропускаются по маркерам в `shop_order_params`:
  - `_depersonalizer_ext_processed = 1`
  - `_depersonalizer_ext_processed_at = timestamp`
- Персональные данные меняются только в выбранных ключах `shop_order_params`.
- Технические поля SDEK/CDEK (`sdekint_plugin.`, `cdek.`, `cdek_`, `sdek.`, `sdek_`) сейчас исключены из автоматического поиска и не трогаются.
- При включенном geo snapshot сохраняются только грубые значения `geo_city`, `geo_region`, `geo_country`, `geo_lat`, `geo_lng`; существующие непустые `geo_*` не перезаписываются.
- Необязательное обезличивание контактов применяется только к контактам без новых заказов.
- Догоняющая обработка контактов может обработать контакты по уже помеченным старым заказам без изменения заказов.
- История выполнения заказов обрабатывается только при отдельном включении чекбокса `Обезличить историю выполнения заказов`.
- Состояние контактов хранится в standalone-таблице `depersonalizer_state`; `wa_contact_params` необязательна.
- Инструмент не удаляет строки заказов/контактов и не меняет товары, позиции, суммы, статусы, оплаты, тарифы доставки или отчеты.

## Проверки после развертывания

Проверка синтаксиса на Beget:

```bash
cd /home/d/dtalke/shop.incyber.ru/public_html/test/tools/depersonalizer
/usr/local/php-cgi/8.1/bin/php -l index.php
/usr/local/php-cgi/8.1/bin/php -l src/StandaloneConfigLoader.php
/usr/local/php-cgi/8.1/bin/php -l src/StandaloneDepersonalizer.php
```

Диагностика колонок истории заказов после подключения к тестовой БД:

```sql
SELECT COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'shop_order_log'
ORDER BY ORDINAL_POSITION;
```

Проверка оставшихся старых плейсхолдеров после реального запуска:

```sql
SELECT COUNT(*) AS old_deleted_contacts
FROM wa_contact
WHERE name = 'Deleted'
   OR firstname = 'Deleted'
   OR middlename = 'Deleted'
   OR lastname = 'Deleted'
   OR company = 'Deleted'
   OR jobtitle = 'Deleted'
   OR about = 'Deleted';

SELECT COUNT(*) AS old_example_invalid_emails
FROM wa_contact_emails
WHERE email LIKE '%@example.invalid';

SELECT COUNT(*) AS old_anon_contact_values
FROM wa_contact_data
WHERE value LIKE 'anon-contact-%'
   OR value LIKE 'anon-order-%';

SELECT COUNT(*) AS old_order_param_placeholders
FROM shop_order_params
WHERE value = 'Deleted'
   OR value LIKE '%@example.invalid'
   OR value LIKE 'anon-contact-%'
   OR value LIKE 'anon-order-%';
```

Для `shop_order_log` универсальную проверку лучше составить после просмотра реальных колонок командой выше. Не добавляйте реальные имена клиентов или операторов в код репозитория.

## Логи

Логи создаются автоматически:

```text
logs/depersonalizer.log
logs/batches/YYYY-MM-DD/batch-HH-MM-SS-random.json
```

Batch-логи содержат run id, timestamp, признак dry-run, options, cutoff, обработанные/пропущенные ID заказов и контактов, а также выбранные include keys. Исходные значения персональных данных не логируются.

## Старый плагин

`legacy-plugin/` только для справки. Поддерживаемый продукт - standalone-страница в корне репозитория.
