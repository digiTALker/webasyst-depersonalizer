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

## Что изменяет инструмент

- Заказы выбираются из `shop_order` по условию `create_datetime < cutoff`.
- Уже обработанные заказы пропускаются по маркерам в `shop_order_params`:
  - `_depersonalizer_ext_processed = 1`
  - `_depersonalizer_ext_processed_at = timestamp`
- Персональные данные меняются только в выбранных ключах `shop_order_params`.
- Технические поля SDEK/CDEK (`sdekint_plugin.`, `cdek.`, `cdek_`, `sdek.`, `sdek_`) сейчас исключены из автоматического поиска и не трогаются.
- При включенном geo snapshot сохраняются только грубые значения `geo_city`, `geo_region`, `geo_country`, `geo_lat`, `geo_lng`; существующие непустые `geo_*` не перезаписываются.
- Необязательное обезличивание контактов применяется только к контактам без новых заказов.
- Состояние контактов хранится в standalone-таблице `depersonalizer_state`; `wa_contact_params` необязательна.
- Инструмент не удаляет строки заказов/контактов и не меняет товары, позиции, суммы, статусы, оплаты, тарифы доставки или отчеты.

## Логи

Логи создаются автоматически:

```text
logs/depersonalizer.log
logs/batches/YYYY-MM-DD/batch-HH-MM-SS-random.json
```

Batch-логи содержат run id, timestamp, признак dry-run, options, cutoff, обработанные/пропущенные ID заказов и контактов, а также выбранные include keys. Исходные значения персональных данных не логируются.

## Старый плагин

`legacy-plugin/` только для справки. Поддерживаемый продукт - standalone-страница в корне репозитория.
