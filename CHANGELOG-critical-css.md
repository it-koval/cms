# Изменения, ещё не отправленные в origin / Unpushed changes

Ветка `critical-css`, диапазон `origin/critical-css..e76aff3`, 32 коммита: 21 коммит трёх возможностей и 11 исправлений по итогам независимого ревью.
Branch `critical-css`, range `origin/critical-css..e76aff3`, 32 commits: 21 for the three features and 11 fixes from an independent review.

- [Русский](#русский)
- [English](#english)

---

## Русский

### Коротко

Владелец сайта теперь может сам подключить счётчики и вебмастерские сервисы в админке, без правок макета:

- Яндекс Метрика и Google Analytics 4 подключаются в настройках;
- коды подтверждения для Яндекс Вебмастера и Google Search Console задаются в тех же настройках;
- IndexNow сразу сообщает Яндексу и Bing об изменённых страницах;
- на экране «Сегодня» есть карточка Яндекс Вебмастера с ИКС, страницами в поиске, проблемами сайта и популярными запросами.

Миграций базы данных нет.

### Добавлено

**Группа настроек «Аналитика и вебмастера».**

- **Где.** «Настройки → Основное». У встроенных ролей группа есть только у администратора, право `set:analytics`.
- **Поля:**
  - номер счётчика Яндекс Метрики (`yandexMetrikaId`);
  - переключатель Вебвизора (`yandexMetrikaWebvisor`);
  - идентификатор GA4 `G-…` (`googleAnalyticsId`);
  - код подтверждения Яндекса (`yandexVerification`);
  - код подтверждения Google (`googleVerification`).
- **Как попадает на сайт.** CMS сама вставляет на публичные HTML-страницы, в том числе на 404:
  - метатеги подтверждения и скрипты счётчиков перед `</head>`;
  - `<noscript>` Метрики после `<body>`.
- **Где не вставляет.** Админка, предпросмотр, API, MCP, `sitemap.xml`, ответы 304 и потоковые ответы. Счётчик, который уже прописан в макете, второй раз не добавляется.
- **Проверка значений.** Значение в неверном формате на сайт не выводится, о нём предупреждает строка «Аналитика и вебмастера» в «Состоянии сайта». В поле подтверждения можно вставить метатег целиком, CMS возьмёт из него только код.

**IndexNow.**

- **Включение.** Переключатель «IndexNow: сообщать поисковикам» в группе «Аналитика и вебмастера». По умолчанию выключен.
- **Когда сообщает.** Когда страница опубликована, перенесена на новый адрес, возвращена на сайт, снята с публикации или удалена. При переносе отправляются оба адреса.
- **Какие страницы.** Те же, что попадают в `sitemap.xml`: основной язык, опубликованные, без `noindex`.
- **Как отправляет.** Адреса уходят через очередь `async` пачками до 100 штук одним запросом на `https://yandex.com/indexnow`. На 429, 5xx и обрыв связи отправка повторяется по `retry_strategy` транспорта `async`, после `max_retries` сообщение уходит в очередь ошибок. Пауза из `Retry-After` не учитывается. Остальные ошибки 4xx уходят в очередь ошибок сразу.
- **Ключ.** Вычисляется из адреса сайта и `APP_SECRET`, хранить его не нужно. Файл ключа отдаётся по адресу `/{ключ}.txt`.
- **Когда ничего не отправляется.** Если адрес сайта `localhost`, IP или служебная зона (`.test`, `.local` и т. п.), а также без `symfony/http-client`.
- **Кириллические домены** уходят в punycode.
- **«Состояние сайта».** Строка «IndexNow» показывает состояние, файл ключа и результат последней отправки.

**Яндекс Вебмастер.**

- **Подключение.** Владелец один раз вставляет OAuth-токен Яндекса в карточку на экране «Сегодня». Если задан `ITK_YANDEX_CLIENT_ID`, в карточке есть кнопка «Получить токен».
- **Что CMS делает сама:**
  - находит сайт в Вебмастере или добавляет его;
  - подтверждает права метатегом `yandex-verification`, который ставит рядом с кодом владельца;
  - один раз регистрирует `sitemap.xml`.
- **Что показывает карточка:**
  - ИКС;
  - страницы в поиске и исключённые;
  - проблемы сайта по важности;
  - до 10 популярных запросов за неделю с показами и кликами;
  - кнопки «Обновить», «Открыть в Вебмастере» и «Отключить».
- **Обновление.** Данные обновляются раз в 6 часов. После ошибки следующая попытка через 15 минут.
- **Срок токена.** За 14 дней до конца срока (180 дней) карточка предупреждает о замене токена.
- **Хранение токена.** Токен хранится зашифрованным (`SecretBox` на `APP_SECRET`). Его нет в ответах API, в API настроек, в истории настроек и в логах.
- **«Состояние сайта».** Строка «Яндекс Вебмастер» читает сохранённые данные и сама к Яндексу не обращается.
- **Новые маршруты админского API:**
  - `GET` и `DELETE` `/admin/api/yandex-webmaster`;
  - `POST` `/admin/api/yandex-webmaster/connect`;
  - `POST` `/admin/api/yandex-webmaster/refresh`.

**Документация.** В README появились разделы «Аналитика и вебмастера», «IndexNow» и «Яндекс Вебмастер». Записи добавлены в `CHANGELOG.md` и `UPGRADE.md`.

### Изменено

- Метатег подтверждения, который уже есть в странице, теперь сравнивается по имени и коду. Тег `yandex-verification` или `google-site-verification` с другим кодом больше не скрывает код из настроек.
- Обновление данных Вебмастера не сбрасывает кэш страниц сайта: снимок хранится в пуле кэша, а не в таблице настроек.
- Адрес сайта разбирается в одном месте, `Content\SiteAddress`: punycode, порт по умолчанию и правило «адрес не публичный». Им пользуются IndexNow, Вебмастер и кэш страниц.
- База знаний: временные ошибки эмбеддингов (429, 5xx, сеть) больше не повторяются бесконечно. После `max_retries` транспорта сообщение попадает в очередь ошибок, как и обещал README. Пауза из `Retry-After` не учитывается.
- Встроенная группа «Сайт» создаётся, только если ей есть что показать: сайт, который объявил `siteName` и `domain` в своей группе, не получает пустую вкладку.

### Исправлено по итогам ревью

- Поле сайта с ключом встроенной настройки в другой группе больше не даёт двойное поле и ошибку схемы.
- Пока карточка Вебмастера ждёт Яндекс, сессия не заблокирована, и остальные запросы админки не ждут.
- Обновление данных Вебмастера, совпавшее с отключением или новым токеном, больше не возвращает старый токен.

### Что учесть при обновлении

- **Новые опции бандла:**
  - `itk_cms.indexnow.endpoint`;
  - `itk_cms.yandex_webmaster.client_id`, по умолчанию из `ITK_YANDEX_CLIENT_ID`;
  - `itk_cms.yandex_webmaster.api_base`.
- **Зарезервированные ключи.** Ключи `yandexMetrikaId`, `yandexMetrikaWebvisor`, `googleAnalyticsId`, `yandexVerification`, `googleVerification` и `indexNow` встроены в настройки. Если схема сайта уже объявляет поле с таким ключом в любой группе, оно остаётся за сайтом, и ядро своё поле не добавляет. То же для `siteName` и `domain` группы «Сайт».
- **Служебная строка настроек.** `yandexWebmaster` не объявляйте в схеме. Снимок данных Вебмастера лежит в пуле кэша `cache.app`: после очистки кэша он заново запрашивается у Яндекса.
- **Права у кастомных ролей.** Они не получают `set:analytics`, пока его не отметить в матрице ролей.
- **Воркер.** IndexNow требует воркер очереди `async`: `messenger:consume async …`.
- **Занятые адреса.** Пока IndexNow включён, адрес `/{32 символа 0-9a-f}.txt` занят файлом ключа.
- **Требования к серверу.** IndexNow и Вебмастеру нужны `symfony/http-client` и публичный «Адрес сайта».

### Ограничения

- Счётчики загружаются сразу, без согласия посетителя на cookies. Баннер согласия остаётся задачей сайта.
- Скрипты счётчиков идут без `nonce`. На сайте со строгой CSP они не заработают.
- IndexNow и Вебмастер проверены только с заглушками на демо-стенде. С настоящим Яндексом они не проверялись.
- Google в IndexNow не участвует. Интеграцию с API Search Console решено не делать, подтверждение сайта в Google идёт через поле кода подтверждения.
- Казахские переводы не проверял носитель языка.

### Коммиты

Аналитика и вебмастера:

- `fe05788` Add AnalyticsConfig, the parser of the counter and verification settings
- `8d02544` Add the built-in settings group Аналитика и вебмастера
- `f66e900` Put the counters and verification tags on live pages
- `ef23985` Add the Аналитика и вебмастера line to the site health screen
- `08876e9` Document the analytics and webmaster settings

IndexNow:

- `7620897` Add IndexNowSite, the toggle and the endpoint option
- `5457dc0` Serve the IndexNow key file at /{key}.txt
- `6ca9a0d` Queue and send IndexNow pings when a page goes live, moves or leaves
- `554e2b6` Add the IndexNow line to the site health screen
- `ed4de7a` Document IndexNow
- `f0ac5f0` Send a Cyrillic site address to IndexNow in punycode

Яндекс Вебмастер:

- `88e0397` Move the punycode site address and the not-public host rule into SitemapBuilder
- `9101bed` Compare name and content when a verification meta is already on the page
- `bcd11bb` Add the Yandex Webmaster connection: client, store and service
- `1272551` Add the Yandex Webmaster admin API and the health line
- `c480780` Document the Yandex Webmaster module
- `bee3335` Add the Yandex Webmaster API client
- `1fba8ae` Add the Yandex Webmaster card logic and mock answers for every state
- `9884109` Show Yandex Webmaster on the Today screen
- `b3a9736` Polish the Yandex Webmaster card: token link layout, one empty state
- `57f14be` Rebuild the admin with the Yandex Webmaster card

Исправления по итогам ревью:

- `7f684bf` Skip a built-in setting whose key the site declares in any group
- `ce74e4f` Let the transport bound IndexNow retries
- `8df9707` Let the transport bound knowledge base retries
- `5212f28` Close the session before the Webmaster calls that may reach Yandex
- `0dfa1da` Keep a Webmaster refresh from undoing a disconnect or a new token
- `f482827` Match the IndexNow route and the token lifetime docs to the code
- `a88fef3` Keep the Webmaster snapshot in the cache pool
- `93e8aa1` Parse the site address in one place: Content\SiteAddress
- `e393b34` Tidy WebmasterService: one failure mapping, a public refresh()
- `8377b0f` Read the time from ClockInterface in IndexNow and Webmaster
- `e76aff3` Name the repeated analytics patterns and share the test settings fixture

---

## English

### Summary

Site owners can now set up analytics counters and webmaster services from the admin, without editing templates:

- Yandex Metrica and Google Analytics 4 are configured in the settings.
- Verification codes for Yandex Webmaster and Google Search Console go in the same settings.
- IndexNow tells Yandex and Bing about changed pages right away.
- The Today screen has a Yandex Webmaster card with the site quality index (SQI, «ИКС»), indexed pages, site problems and popular queries.

There are no database migrations.

### Added

**Settings group "Analytics and webmasters".**

- **Where.** Settings → General. Among the built-in roles, only the administrator has it, through the `set:analytics` permission.
- **Fields:**
  - Yandex Metrica counter ID (`yandexMetrikaId`);
  - Webvisor toggle (`yandexMetrikaWebvisor`);
  - GA4 measurement ID `G-…` (`googleAnalyticsId`);
  - Yandex verification code (`yandexVerification`);
  - Google verification code (`googleVerification`).
- **How it reaches the site.** The CMS inserts the code into public HTML pages, 404 pages included:
  - the verification meta tags and counter scripts before `</head>`;
  - the Metrica `<noscript>` after `<body>`.
- **Where it inserts nothing.** The admin, preview, API, MCP, `sitemap.xml`, 304 responses and streamed responses. A counter that the template already contains is not added again.
- **Value checks.** An invalid value is not output. The "Analytics and webmasters" line on the Site health screen reports it. The verification fields accept a whole meta tag, and the CMS keeps only the code.

**IndexNow.**

- **Turning it on.** The "IndexNow: notify search engines" toggle in the "Analytics and webmasters" group. It is off by default.
- **When it notifies.** When a page is published, moved to a new URL, brought back, unpublished or deleted. A move sends both URLs.
- **Which pages.** The same pages as `sitemap.xml`: main locale, published, not `noindex`.
- **How it sends.** URLs go through the `async` queue in batches of up to 100, in one request to `https://yandex.com/indexnow`. 429, 5xx and network failures are retried by the `retry_strategy` of the `async` transport; after `max_retries` the message goes to the failure transport. `Retry-After` is not honoured. Other 4xx errors go to the failure transport at once.
- **Key.** Derived from the site address and `APP_SECRET`, so nothing is stored. The key file is served at `/{key}.txt`.
- **When nothing is sent.** When the site address is `localhost`, an IP or a reserved zone (`.test`, `.local` and so on), or when `symfony/http-client` is missing.
- **Cyrillic domains** are sent in punycode.
- **Site health.** An "IndexNow" line shows the state, the key file and the result of the last submission.

**Yandex Webmaster.**

- **Connecting.** The owner pastes a Yandex OAuth token into the card on the Today screen once. When `ITK_YANDEX_CLIENT_ID` is set, the card offers a "Get token" button.
- **What the CMS does by itself:**
  - finds the site in Webmaster or adds it;
  - verifies ownership with a `yandex-verification` meta tag, placed next to the owner's own code;
  - registers `sitemap.xml` once.
- **What the card shows:**
  - SQI;
  - pages in search and excluded pages;
  - site problems by severity;
  - up to 10 popular queries of the week, with impressions and clicks;
  - Refresh, Open in Webmaster and Disconnect buttons.
- **Refresh.** The data refreshes every 6 hours. After a failure, the next attempt comes 15 minutes later.
- **Token lifetime.** Tokens live 180 days. The card warns 14 days before the token expires.
- **Token storage.** The token is stored encrypted (`SecretBox` keyed by `APP_SECRET`). It never appears in API responses, the settings API, the settings history or logs.
- **Site health.** A "Yandex Webmaster" line reads the stored data and never calls Yandex itself.
- **New admin API routes:**
  - `GET` and `DELETE` `/admin/api/yandex-webmaster`;
  - `POST` `/admin/api/yandex-webmaster/connect`;
  - `POST` `/admin/api/yandex-webmaster/refresh`.

**Documentation.** The README has new sections "Analytics and webmasters", "IndexNow" and "Yandex Webmaster". `CHANGELOG.md` and `UPGRADE.md` have new entries.

### Changed

- A verification meta tag already on the page is now matched by name and content. A `yandex-verification` or `google-site-verification` tag with a different code no longer hides the code from the settings.
- Refreshing the Webmaster data does not invalidate the page cache: the snapshot lives in the cache pool, not in the settings table.
- The site address is parsed in one place, `Content\SiteAddress`: punycode, the default port and the "not a public host" rule. IndexNow, Webmaster and the page cache use it.
- Knowledge base: transient embedding failures (429, 5xx, network) are no longer retried forever. After the transport's `max_retries` the message goes to the failure transport, as the README promised. `Retry-After` is not honoured.
- The built-in Site group is created only when it has something to show: a site that declares `siteName` and `domain` in its own group gets no empty tab.

### Fixed after review

- A site field whose key matches a built-in setting in another group no longer causes a duplicate field and a schema error.
- While the Webmaster card waits for Yandex, the session is not locked, so the other admin requests do not wait.
- A Webmaster refresh that races a disconnect or a new token no longer brings the old token back.

### Upgrade notes

- **New bundle options:**
  - `itk_cms.indexnow.endpoint`;
  - `itk_cms.yandex_webmaster.client_id`, which defaults to `ITK_YANDEX_CLIENT_ID`;
  - `itk_cms.yandex_webmaster.api_base`.
- **Reserved keys.** `yandexMetrikaId`, `yandexMetrikaWebvisor`, `googleAnalyticsId`, `yandexVerification`, `googleVerification` and `indexNow` are built-in settings. If the site schema already declares a field with one of these keys in any group, the site keeps it and the core adds none. The same holds for `siteName` and `domain` of the Site group.
- **Internal setting row.** Do not declare `yandexWebmaster` in the schema. The Webmaster data snapshot lives in the `cache.app` pool; after a cache clear it is fetched from Yandex again.
- **Custom roles.** They do not get `set:analytics` until it is ticked in the role matrix.
- **Worker.** IndexNow needs a worker on the `async` queue: `messenger:consume async …`.
- **Reserved paths.** While IndexNow is on, the path `/{32 chars 0-9a-f}.txt` is taken by the key file.
- **Server requirements.** IndexNow and Webmaster need `symfony/http-client` and a public site address.

### Limitations

- Counters load right away, without visitor consent for cookies. A consent banner is the site's job.
- Counter scripts carry no `nonce`. On a site with a strict CSP they will not run.
- IndexNow and Webmaster were tested only against mocks on the demo stand. They have not been tested against the real Yandex.
- Google does not take part in IndexNow. An integration with the Search Console API was decided against. Google verification works through the verification code field.
- No native speaker has reviewed the Kazakh translations.

### Commits

See the list in the Russian section above. The commit messages are in English.
