[English](UPGRADE.md) | Русский

# Обновление ITK CMS

## Как обновить сайт

Делайте шаги по порядку, сначала на копии сайта:

1. Прочитайте [CHANGELOG.ru.md](CHANGELOG.ru.md) за все версии между вашей и целевой, а также заметки к версиям в конце этого файла.
2. Сделайте резервную копию базы данных.
3. Обновите пакет: `composer update itk/cms`. В комплекте Docker выполните `docker compose exec php composer update itk/cms`.
4. Выполните миграции: `bin/console doctrine:migrations:migrate`. Миграции бандла (`Itk\Cms\Migrations`) выполняются вместе с миграциями сайта, в порядке дат в именах их классов.
5. Опубликуйте собранную админку командой `bin/console assets:install`. Symfony Flex делает это при `composer install` и `composer update`. Затем очистите кэш командой `bin/console cache:clear`.
6. Перезапустите воркеры очереди командой `bin/console messenger:stop-workers` или перезапуском сервиса. Работающий воркер держит старый код.
7. Проверьте сайт: выполните `bin/console itk:doctor` и `bin/console itk:schema:lint`, затем откройте админку и несколько страниц.

Если заметки к версии просят изменить конфигурационный файл сайта (например, `security.yaml`), сделайте это до шага 7.

## Версии

ITK CMS следует [семантическому версионированию](https://semver.org/lang/ru/spec/v2.0.0.html):

- Патч-релиз (0.1.1) содержит только исправления.
- Минорный релиз (0.2.0) добавляет возможности. Начиная с 1.0.0 он не ломает публичный API.
- Мажорный релиз (2.0.0) может ломать публичный API.

До версии 1.0.0 минорный релиз может ломать публичный API. Каждое такое изменение указано в [CHANGELOG.ru.md](CHANGELOG.ru.md) и в заметках к версиям ниже. Патч-релиз его никогда не ломает. Чтобы получать исправления без сюрпризов, закрепите в сайте минорную версию: `"itk/cms": "~0.2.0"`.

Изменения базы данных поставляются миграциями бандла. Бандл не меняет собственные таблицы сайта.

## Публичный API

Эти части бандла образуют публичный API и подчиняются правилам выше:

- API схемы: `Schema\SchemaBuilder`, `Schema\Field`, `Template`, `SectionType`, `Collection`, `SettingsGroup` и `FormDef`;
- поля `Field\*`;
- классы-ресурсы и их API: `Resource\*` (`CollectionResource`, `TemplateResource`, `SectionResource`, `SettingsResource`, `Action` и `Metric`) и атрибуты `Attribute\*`;
- события `Event\*`;
- `Extension\ItkExtensionInterface`, `Extension\Registry` и `Extension\NavItem`;
- интерфейсы, которые реализует сайт, и классы, которые они используют: `Health\HealthCheckInterface`, `Health\HealthResult`, `Notification\NotifierInterface`, `Notification\Notification` и `Notification\NotificationChannelInterface`;
- `Content\ContentReader` и сервисы содержимого `PageService`, `CollectionService`, `SettingsService` и `MenuService`;
- `Settings\Settings`, `I18n\I18n`, `I18n\ContentLocales`, `Support\Url` и `Admin\AdminPath`;
- поиск по базе знаний: `KnowledgeBase\KnowledgeSearch`, `KnowledgeBase\Model\{SearchOptions, SearchResult, Hit, Source, SourceKind, KnowledgeBaseUnavailable}` и `KnowledgeBase\Embedding\*`, кроме `OpenAiEmbedder`;
- `Rendering\PageCache::clear()`;
- Twig-функции бандла, в том числе `itk_stylesheets()`;
- имена маршрутов и опции `itk_cms`;
- имена консольных команд и их опций;
- имена транспортов Messenger (`async`, `critical_css`, `knowledge_base` и `failed`) и ограничителей в `framework.rate_limiter`;
- классы сообщений Messenger, чтобы сайт мог их маршрутизировать: `Request\SendRequestNotification`, `Media\GenerateImageVariants`, `Mail\SendMail`, `Security\RequestPasswordLink`, `IndexNow\Model\PingIndexNow`, `CriticalCss\Model\GenerateCriticalCss` и `KnowledgeBase\Model\SyncKnowledge`;
- классы, которые сайт указывает в своей конфигурации: `Entity\User`, `Security\{AdminUserProvider, UserChecker, UserManager, Viewer, PermissionVoter, Access}` и `Mcp\{ApiTokenAuthenticator, McpToolInterface, AbstractMcpTool, McpContext, McpScopes, McpToolException, McpConditionalTool}`;
- контракт плагинов админки `window.ITK`, его версия в `ITK.version`;
- вид JSON API под адресом админки в той мере, в какой на него опираются собранная админка и описанные клиенты.

Классы с пометкой `@internal` в докблоке не являются публичными и могут измениться в любом минорном релизе. Сюда входят `Repository\*`, `Controller\*`, `Generator\*`, `Rendering\*` (кроме `PageCache::clear()`), `Mcp\Tool\*`, слушатели `Security\*` и `Twig\{ItkExtension, ItkRuntime, SeoRuntime}`. Чтобы читать страницы и коллекции в шаблонах, используйте `Content\ContentReader` и Twig-функции, а не репозитории.

Схема базы данных (таблицы `itk_*`) не входит в публичный API. Читайте и записывайте через сервисы. Эти таблицы меняют только миграции бандла.

## Заметки к версиям

Здесь перечислены ручные шаги, которые нужны при обновлении на релиз, новые версии сверху.

### 0.3.0

- `Mcp\AbstractMcpTool::locale()` теперь нестатический. Внутри методов экземпляра работают `self::locale($args)` и `$this->locale($args)`. Перенесите вызовы из статического контекста в метод экземпляра.

### 0.2.0

- Удалён `Content\SettingsService::routesError()`. Если сайт его вызывает, уберите вызов. `SettingsService::save()` теперь принимает пустой чат для остальных форм, когда подключён Telegram или MAX. Если сайт рассчитывал на отказ, проверяйте это в коде сайта.
- Конструктор `SettingsService` больше не принимает `max` и `telegram`. Сервис собирает контейнер, поэтому это важно только для кода, который создаёт сервис вручную.
- Свой экран состояния или монитор, который читает `checks[].status` из API админки `health`, должен принимать новое значение `off`. Оно равно по весу `ok`.

### 0.1.0

Первый релиз. Ручных шагов нет.
