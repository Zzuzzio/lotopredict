# LotoPredict

Веб-приложение для анализа лотерей Столото (Гослото 6 из 45, 7 из 49): парсинг тиражей, статистика и прогнозы.

## Требования

- PHP 7.2+ (с расширениями `pdo_sqlite`, `json`, `curl`)
- Composer опционален (используется `bootstrap.php`)

## Установка

```bash
cd lotopredict
# Опционально: composer install (Guzzle не обязателен, используется curl)
```

### Headless-браузер (рекомендуется для полного архива)

Для загрузки нескольких страниц архива (как при прокрутке на stoloto.ru) нужен Node.js и Playwright:

```bash
cd lotopredict
chmod +x bin/install_browser.sh
./bin/install_browser.sh
```

В `config/app.php` параметр `use_browser_parser` должен быть `true` (по умолчанию включён).
При недоступности браузера используется fallback на curl.

Требования: Node.js 18+, ~300 МБ для Chromium, системные библиотеки (`playwright install-deps`).

Убедитесь, что установлено расширение SQLite:

```bash
apt install php7.2-sqlite   # Ubuntu/Debian
```

## Загрузка тиражей

```bash
# Инкрементальная загрузка (последние тиражи)
php bin/fetch_draws.php

# Первичная загрузка ~500 тиражей
php bin/fetch_draws.php --backfill

# Одна лотерея
php bin/fetch_draws.php --lottery=gosloto-6x45 --backfill
```

## Cron

```bash
0 */6 * * * php /var/www/site_user/data/www/1358489-cm54842.tw1.ru/lotopredict/bin/fetch_draws.php >> /var/www/site_user/data/www/1358489-cm54842.tw1.ru/lotopredict/storage/logs/cron.log 2>&1
```

## URL

Приложение доступно по адресу `/lotopredict/` (через rewrite в корневом `.htaccess`).

- `/lotopredict/` — главная
- `/lotopredict/stats` — статистика
- `/lotopredict/predict` — прогноз
- `/lotopredict/api/stats?lottery=gosloto-6x45&period=100` — JSON API

**Лотереи:** Гослото 6 из 45, 7 из 49, 5 из 36 (`5x36plus`).

## 5 из 36 — полный парсинг (мощный сервер)

```bash
# Первичная настройка сервера (PHP, SQLite, Playwright)
bash bin/setup_server.sh

# Статус базы и JSONL-архива
php bin/fetch_5x36plus.php --status

# Последние тиражи (cron каждые 6 ч)
php bin/fetch_5x36plus.php --recent

# Полный архив с 1-го тиража (browser + parallel backfill, ~160k тиражей)
php bin/fetch_5x36plus.php --full

# Только curl (если browser недоступен)
php bin/fetch_5x36plus.php --full --curl-only
```

На мощном сервере (4+ ядра, 8 GB RAM) используется профиль: `parallel=50`, `page_size=100`, browser backfill `parallel=40`.

## Источник данных

Тиражи загружаются с API Столото (тот же endpoint, что использует бесконечная прокрутка архива):

```
/p/api/mobile/api/v35/service/draws/archive?game={slug}&count=50&page={n}
```

Парсер имитирует браузер: прогрев сессии, cookies, постраничная загрузка (`page=1,2,3…`) пока `hasMore=true`.

## Дисклеймер

Прогнозы основаны на статистическом анализе и не являются гарантией выигрыша.
