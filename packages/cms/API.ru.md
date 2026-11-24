# Публичный API

[English version](API.md)

Это единый контракт публичного API `itk/cms`. Канонические перечни типов и методов находятся в английском [API.md](API.md). `PublicApiContractTest` сверяет с ними русский перевод. [README.ru.md](README.ru.md) содержит рецепты использования, а [UPGRADE.ru.md](https://github.com/it-koval/cms/blob/main/UPGRADE.ru.md) — правила совместимости и шаги перехода между версиями.

Публичны только перечисленные ниже PHP-типы. Каждый другой именованный класс, интерфейс, трейт и enum в `src/` имеет `@internal` в докблоке типа. Член публичного типа с пометкой `@internal` тоже исключён из гарантии совместимости. Получайте сервисы через контейнер. Их конструкторы и настройка контейнера относятся к внутренней реализации.

## PHP-типы

В каждом пункте указано полное имя одного публичного типа.

### Бандл и схема

- `Itk\Cms\ItkCmsBundle`
- `Itk\Cms\Schema\SchemaBuilder`
- `Itk\Cms\Schema\Schema`
- `Itk\Cms\Schema\Field`
- `Itk\Cms\Schema\Template`
- `Itk\Cms\Schema\SectionType`
- `Itk\Cms\Schema\Collection`
- `Itk\Cms\Schema\SettingsGroup`
- `Itk\Cms\Schema\FormDef`
- `Itk\Cms\Schema\MenuAreaDef`

### Поля

- `Itk\Cms\Field\FieldInterface`
- `Itk\Cms\Field\PublicFieldInterface`
- `Itk\Cms\Field\ValueValidatingFieldInterface`
- `Itk\Cms\Field\AbstractField`
- `Itk\Cms\Field\FilterKind`
- `Itk\Cms\Field\Article`
- `Itk\Cms\Field\Date`
- `Itk\Cms\Field\DateTime`
- `Itk\Cms\Field\Description`
- `Itk\Cms\Field\Headline`
- `Itk\Cms\Field\Image`
- `Itk\Cms\Field\Link`
- `Itk\Cms\Field\Note`
- `Itk\Cms\Field\Number`
- `Itk\Cms\Field\Photos`
- `Itk\Cms\Field\Relation`
- `Itk\Cms\Field\Repeater`
- `Itk\Cms\Field\Select`
- `Itk\Cms\Field\Text`
- `Itk\Cms\Field\Textarea`
- `Itk\Cms\Field\Toggle`

`Field\FormFields` подготавливает данные редактора и является внутренним типом, а не полем для схемы сайта.

### Ресурсы, хуки, действия и атрибуты

- `Itk\Cms\Resource\ResourceInterface`
- `Itk\Cms\Resource\CollectionResource`
- `Itk\Cms\Resource\TemplateResource`
- `Itk\Cms\Resource\SectionResource`
- `Itk\Cms\Resource\SettingsResource`
- `Itk\Cms\Resource\ResourceHooks`
- `Itk\Cms\Resource\Context`
- `Itk\Cms\Resource\Record`
- `Itk\Cms\Resource\Action`
- `Itk\Cms\Resource\ActionContext`
- `Itk\Cms\Resource\ActionResult`
- `Itk\Cms\Resource\Metric`
- `Itk\Cms\Attribute\CanSee`
- `Itk\Cms\Attribute\Group`
- `Itk\Cms\Attribute\HideFromMenu`
- `Itk\Cms\Attribute\Icon`
- `Itk\Cms\Attribute\Order`

`HookDispatcher`, `ActionRunner`, `ResourceRegistry` и `ResourceApplier` выполняют или находят эти объявления внутри бандла и являются внутренними типами.

### События

- `Itk\Cms\Event\RecordEvent`
- `Itk\Cms\Event\RecordCreating`
- `Itk\Cms\Event\RecordCreated`
- `Itk\Cms\Event\RecordUpdating`
- `Itk\Cms\Event\RecordUpdated`
- `Itk\Cms\Event\RecordDeleting`
- `Itk\Cms\Event\RecordDeleted`
- `Itk\Cms\Event\RecordPublishing`
- `Itk\Cms\Event\RecordPublished`
- `Itk\Cms\Event\RecordUnpublishing`
- `Itk\Cms\Event\RecordUnpublished`
- `Itk\Cms\Event\RecordTranslationAdded`
- `Itk\Cms\Event\RecordTranslationRemoved`
- `Itk\Cms\Event\MassAction`
- `Itk\Cms\Event\PageTemplateChanged`
- `Itk\Cms\Event\SeoRulesChanged`
- `Itk\Cms\Event\SchemaOrgGraph`
- `Itk\Cms\Event\RequestDelivery`
- `Itk\Cms\Entity\Request`

`Entity\Request` передаётся в событии результата доставки. Это не делает таблицы базы и репозитории поддерживаемым API интеграции.

### Контент и сервисы сайта

- `Itk\Cms\Content\ContentReader`
- `Itk\Cms\Content\ContentException`
- `Itk\Cms\Content\PageService`
- `Itk\Cms\Content\CollectionService`
- `Itk\Cms\Content\SettingsService`
- `Itk\Cms\Content\MenuService`
- `Itk\Cms\Content\SiteAddress`
- `Itk\Cms\Content\ItemTemplate`
- `Itk\Cms\Settings\Settings`
- `Itk\Cms\I18n\I18n`
- `Itk\Cms\I18n\ContentLocales`
- `Itk\Cms\Support\Slug`
- `Itk\Cms\Support\Url`
- `Itk\Cms\Admin\AdminPath`
- `Itk\Cms\Rendering\PageCache`

Опубликованный контент для посетителей читайте через `ContentReader` и Twig-функции. `SiteAddress` разбирает и нормализует адрес сайта. `ItemTemplate::render()` и `first()` строят подписи элементов повторителя. Для ручной очистки кэша используйте `PageCache::clear(): bool`.

`Slug::slugify(string $text, int $max = 64): string` транслитерирует русский и казахский текст в строчную латиницу, заменяет разделители дефисами и ограничивает длину значением `$max`. В README есть примеры использования в хуках ресурсов. `Slug::areaKey(string $name, array $taken = []): string` строит ключ области меню с подчёркиваниями и начальной буквой. Передайте существующие ключи в `$taken`, чтобы при совпадении добавить числовой суффикс.

### Расширения, статус и уведомления

- `Itk\Cms\Extension\ItkExtensionInterface`
- `Itk\Cms\Extension\Registry`
- `Itk\Cms\Extension\NavItem`
- `Itk\Cms\Health\HealthCheckInterface`
- `Itk\Cms\Health\HealthResult`
- `Itk\Cms\Notification\NotifierInterface`
- `Itk\Cms\Notification\Notification`
- `Itk\Cms\Notification\NotificationChannelInterface`
- `Itk\Cms\Request\RequestQueue`

Чтобы поставить новую доставку заявки в очередь из доверенного PHP-кода, внедрите `RequestQueue` и вызовите `enqueue($requestId, $channel, resend: true)`. Используйте `Entity\Request::CHANNEL_TELEGRAM` или `Entity\Request::CHANNEL_MAX`. Операция и задание записываются вместе. Публикация начинается после фиксации транзакции. Если транзакцией управляет вызывающий код, задание публикует механизм восстановления. Пока доставка в очереди, повторные вызовы используют ту же операцию. После её завершения новый вызов может начать новую отправку. Если результат неизвестен, сперва проверьте получателя. Остальные методы `RequestQueue` внутренние.

### Безопасность и свои MCP-инструменты

- `Itk\Cms\Entity\User`
- `Itk\Cms\Entity\ApiToken`
- `Itk\Cms\Security\AdminUserProvider`
- `Itk\Cms\Security\UserChecker`
- `Itk\Cms\Security\UserManager`
- `Itk\Cms\Security\Viewer`
- `Itk\Cms\Security\PermissionVoter`
- `Itk\Cms\Security\Access`
- `Itk\Cms\Mcp\ApiTokenAuthenticator`
- `Itk\Cms\Mcp\McpToolInterface`
- `Itk\Cms\Mcp\AbstractMcpTool`
- `Itk\Cms\Mcp\McpContext`
- `Itk\Cms\Mcp\McpScopes`
- `Itk\Cms\Mcp\McpToolException`
- `Itk\Cms\Mcp\McpToolListError`
- `Itk\Cms\Mcp\McpConditionalTool`

`Entity\ApiToken` доступен через `McpContext::$token`. Слушатели аутентификации, реализация сервера MCP и встроенные MCP-инструменты являются внутренними.

### Поиск по базе знаний и провайдеры эмбеддингов

- `Itk\Cms\KnowledgeBase\KnowledgeSearch`
- `Itk\Cms\KnowledgeBase\Model\SearchOptions`
- `Itk\Cms\KnowledgeBase\Model\SearchResult`
- `Itk\Cms\KnowledgeBase\Model\Hit`
- `Itk\Cms\KnowledgeBase\Model\Source`
- `Itk\Cms\KnowledgeBase\Model\SourceKind`
- `Itk\Cms\KnowledgeBase\Model\SourceRef`
- `Itk\Cms\KnowledgeBase\Model\KnowledgeBaseUnavailable`
- `Itk\Cms\KnowledgeBase\Embedding\Embedder`
- `Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed`
- `Itk\Cms\KnowledgeBase\Embedding\Embeddings`
- `Itk\Cms\KnowledgeBase\Embedding\Purpose`
- `Itk\Cms\KnowledgeBase\Embedding\Space`
- `Itk\Cms\KnowledgeBase\Embedding\Vector`

`SourceRef` возвращается источниками результатов поиска и сообщениями синхронизации. `OpenAiEmbedder` и реализация индексации являются внутренними.

### Сообщения Messenger

- `Itk\Cms\Request\SendRequestNotification`
- `Itk\Cms\Media\GenerateImageVariants`
- `Itk\Cms\Mail\SendMail`
- `Itk\Cms\Security\RequestPasswordLink`
- `Itk\Cms\IndexNow\Model\PingIndexNow`
- `Itk\Cms\CriticalCss\Model\GenerateCriticalCss`
- `Itk\Cms\CriticalCss\Model\When`
- `Itk\Cms\KnowledgeBase\Model\SyncKnowledge`

Сайт может направлять эти сообщения в свои транспорты. `GenerateCriticalCss(string $translationId, When $when = When::Outdated)` задаёт перевод страницы. `When::Outdated` генерирует отсутствующий или устаревший CSS, `When::NotReady` также повторяет неудачную генерацию, а `When::Always` запускает генерацию независимо от актуальности CSS. Методы проверки политики, обработчики сообщений, реализация почты и остальные модели критического CSS относятся к внутренней реализации.

Для новой доставки через `SendRequestNotification` используйте `RequestQueue::enqueue()`. Старый конверт без ID задания может продолжить только исходную операцию.

## Операции PageService

Доверенный PHP-код, изменяющий контент, получает `PageService` через DI. Эти операции сохраняют валидацию, права полей, хуки и историю. В точке входа HTTP или MCP вызывающий код также обязан проверить разрешение операции и доступ к ресурсу. `null` в языке выбирает основной язык. Методы, возвращающие страницу, отдают плоский массив черновика, описанный в README. `getDraft()` возвращает оболочку редактора, `getPublished()` — опубликованный снимок или `null`, `list()` — строки списка. Для публичного вывода используйте `ContentReader`.

Параметр `$guard` в `addTranslation()`, `removeTranslation()`, `saveDraft()`, `update()`, `reorder()` и `tree()` служит для внутренней авторизации бандла и получает объекты `Entity\Page`. Код сайта должен опускать `$guard` или передавать `null`.

### Чтение и выбор записей

- `PageService::mainLocale()`
- `PageService::list()`
- `PageService::get()`
- `PageService::getPublished()`
- `PageService::getDraft()`
- `PageService::records()`

`records()` возвращает значения `Resource\Record` для хуков и действий и бросает исключение при неизвестном ID. `get()` требует существующего перевода. `getDraft()` может описать отсутствие перевода без его создания.

### Создание и редактирование

- `PageService::create()`
- `PageService::saveDraft()`
- `PageService::update()`
- `PageService::reorder()`
- `PageService::tree()`

`create()` принимает массив данных страницы. `saveDraft()` сохраняет секции и поля. `update()` меняет данные списка без секций. Передавайте последний полученный непрозрачный `version` в `$baseVersion` метода `saveDraft()`, чтобы устаревшее изменение завершалось ошибкой `409`. Родитель и шаблон общие для всех переводов. `reorder()` возвращает `void`, `tree()` — число перемещённых страниц.

### Переводы

- `PageService::addTranslation()`
- `PageService::removeTranslation()`

`addTranslation(string $id, string $locale, ?string $from, ?callable $guard = null): array` требует явно передать исходный язык либо `null` для значений схемы по умолчанию. Операция создаёт неопубликованный черновик и возвращает плоскую страницу выбранного языка. `removeTranslation(string $id, string $locale, ?callable $guard = null): void` удаляет перевод, если он не основной и не последний. Обе операции проверяют включённые языки и сохраняют общий ID страницы и положение в дереве.

### Публикация и согласование

- `PageService::publish()`
- `PageService::republish()`
- `PageService::unpublish()`
- `PageService::revert()`
- `PageService::submit()`
- `PageService::returnToAuthor()`
- `PageService::massPublish()`

`publish()` публикует черновик с необязательной проверкой `$baseVersion`. `republish()` вновь активирует сохранённую публикацию. `revert()` возвращает опубликованный контент в черновик. Операции согласования меняют состояние выбранного языка. `massPublish()` возвращает число опубликованных страниц.

### Удаление

- `PageService::remove()`
- `PageService::massRemove()`

`remove()` удаляет страницу целиком и возвращает `void`. `massRemove()` возвращает число удалённых страниц. Для одного языка используйте `removeTranslation()`. При ошибках сервис бросает `ContentException` с HTTP-кодами, в том числе `403`, `404`, `409` и `422`.

### Внутренние члены PageService

Конструктор, метод настройки контейнера, обёртка блокировки, загрузка и преобразование сущностей, получение опубликованного снимка, счётчики и описания изменений, проверки H1 и обязательных полей, восстановление из истории, представление (`present`, `summary`, `flat`, `versionOf`, `row`, `iso`), проверка адреса, нормализация и начальные значения секций помечены `@internal`. Они остаются вызываемыми для существующих внутренних потребителей, но публичная видимость не обещает совместимости. Сервис представления не входит в API сайта.

## Остальные публичные контракты

Правила совместимости также охватывают документированные имена Twig-функций и их аргументы (включая `itk_stylesheets()`), имена маршрутов, параметры конфигурации `itk_cms`, имена и опции консольных команд, имена транспортов Messenger (`async`, `critical_css`, `knowledge_base`, `failed`), имена настроенных ограничителей частоты, контракт плагинов админки `window.ITK` с версией `ITK.version`, а также документированный JSON API админки, используемый поставляемой панелью и поддерживаемыми клиентами.

Схема таблиц `itk_*`, запросы репозиториев, реализация собранной админки и PHP-типы вне перечня являются внутренними. Таблицами CMS управляют миграции бандла. Для интеграций используйте документированные сервисы и точки расширения.
