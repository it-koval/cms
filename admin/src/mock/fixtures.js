// The data of the mock mode (`npm run dev:mock`, VITE_MOCK=1): a small made-up site, enough to see the screens of the admin without PHP. Nothing here is a real site.
// makeDb() answers a fresh copy every time: a reload of the page forgets what was written (see handler.js).
// The shapes are the ones the server sends (admin/src/api/*.js tell the endpoints; the schema is the admin shape of packages/cms/src/Schema).

const field = (k, t, label, more = {}) => ({ k, t, label, ...more })
const list = (show = null, sortable = false) => ({ show, sortable, width: null })

const SCHEMA = {
  mainLocale: 'ru',
  design: { headlineMarks: [] },
  features: { ai: false },
  sectionGroups: ['Первый экран', 'Содержимое'],
  sections: [
    {
      key: 'hero', name: 'Первый экран с кнопкой', short: 'Первый экран', group: 'Первый экран', tone: 'brand', h: 220, sil: 'hero', about: 'Большой заголовок, короткий текст и кнопка.', h1: 'headline', variants: [],
      fields: [
        field('headline', 'headline', 'Заголовок', { req: true, max: 80 }),
        field('text', 'textarea', 'Текст под заголовком', { rich: true, rows: 3, max: 300 }),
        field('btn', 'text', 'Текст кнопки', { max: 30 }),
      ],
    },
    {
      key: 'text', name: 'Заголовок и текст', short: 'Текст', group: 'Содержимое', tone: 'neutral', h: 160, sil: 'text', about: 'Заголовок и абзацы.', h1: null, variants: [],
      fields: [field('headline', 'headline', 'Заголовок', { req: true, max: 80 }), field('body', 'textarea', 'Текст', { rich: true, rows: 6, max: 3000 })],
    },
    {
      key: 'cards', name: 'Карточки', short: 'Карточки', group: 'Содержимое', tone: 'neutral', h: 200, sil: 'cards', about: 'Несколько карточек в ряд.', h1: null, variants: [],
      fields: [
        field('headline', 'headline', 'Заголовок', { max: 80 }),
        field('items', 'repeater', 'Карточки', {
          addLabel: 'Добавить карточку', noun: 'карточка',
          sub: [field('title', 'text', 'Название', { req: true, max: 60 }), field('text', 'textarea', 'Описание', { max: 200, rows: 2 })],
        }),
      ],
    },
  ],
  pageFields: [
    field('title', 'text', 'Название страницы', { max: 70, req: true, help: 'Видно в меню админки и в списке страниц.' }),
    field('url', 'text', 'Адрес страницы', { max: 80, req: true, help: 'Начинается со слэша, например /about-us.' }),
    field('seo.title', 'text', 'Заголовок в поисковике', { max: 70 }),
    field('seo.description', 'textarea', 'Описание в поисковике', { max: 160, rows: 3 }),
  ],
  templates: [
    { key: 'home', name: 'Главная', about: 'Первая страница сайта, адрес «/».', core: true, parents: null, fields: [], sections: 'all', areas: 'layout', single: true, root: true },
    { key: 'default', name: 'Обычная страница', about: 'Любая страница из секций.', core: true, parents: 'any', fields: [], sections: 'all', areas: 'layout' },
    {
      key: 'catalog', name: 'Каталог', about: 'Список товаров, который собирается сам.', core: false, parents: null, fields: [], sections: 'all', areas: 'layout', single: true,
      listing: { of: ['product'], label: 'Товары в каталоге', onlyIf: 'show' },
    },
    {
      key: 'product', name: 'Товар', about: 'Карточка товара: название, фото и описание.', core: false, parents: ['catalog'], sections: 'all', areas: 'layout', leaf: true, shortcut: 'Товары',
      fields: [
        field('name', 'text', 'Название товара', { req: true, max: 70 }),
        field('photo', 'image', 'Фото'),
        field('info', 'text', 'Коротко', { max: 80 }),
        field('show', 'toggle', 'Показывать в каталоге'),
      ],
      h1: { field: 'name' }, card: { image: 'photo', title: 'name', text: 'info' },
    },
    {
      key: 'blog', name: 'Блог', about: 'Главная страница блога: сама собирает список статей.', core: true, parents: null, fields: [], sections: 'all', areas: 'layout', single: true,
      listing: { of: ['blog_article'], label: 'Статьи блога' },
    },
    {
      key: 'blog_article', name: 'Статья блога', about: 'Одна статья: заголовок, дата, обложка и текст.', core: true, parents: ['blog'], sections: 'all', areas: 'layout', leaf: true, shortcut: 'Статьи блога',
      fields: [
        field('title', 'text', 'Заголовок статьи', { max: 90, req: true }),
        field('date', 'text', 'Дата публикации', { inputType: 'date' }),
        field('cover', 'image', 'Обложка'),
        field('excerpt', 'textarea', 'Коротко о чём статья', { rich: true, max: 200, rows: 3 }),
      ],
      h1: { field: 'title' }, card: { image: 'cover', title: 'title', text: 'excerpt', date: 'date' },
    },
  ],
  layout: { top: [], bottom: [] },
  collections: [
    {
      key: 'news', title: 'Новости', singular: 'новость', newLabel: 'Новая новость', icon: 'doc', description: 'Короткие новости компании.', view: 'table', titleField: 'title', thumbField: null, subField: 'date',
      sortable: false, toggleField: 'pinned',
      tabs: [{
        key: 'main', label: 'Новость',
        fields: [
          field('title', 'text', 'Заголовок', { req: true, max: 90, list: list(true, true) }),
          field('date', 'text', 'Дата', { inputType: 'date', list: list(true, true) }),
          field('kind', 'select', 'Рубрика', { opts: [['company', 'О компании'], ['product', 'О продукте']], list: list(true, false) }),
          field('pinned', 'toggle', 'На главной', { list: list(false, false) }),
          field('text', 'textarea', 'Текст', { rich: true, rows: 6, max: 3000, list: list(false, false) }),
        ],
      }],
      empty: { title: 'Новостей пока нет', text: 'Добавьте первую новость.' }, views: [{ type: 'table' }], filters: [], searchKeys: ['title'], metrics: [], actions: [], tree: false, defaultSort: '-date', perPage: 25,
    },
    {
      key: 'plans', title: 'Тарифы', singular: 'тариф', newLabel: 'Добавить тариф', icon: 'table', description: 'Таблица тарифов, которую посетители видят на странице цен.', view: 'matrix', titleField: 'title',
      thumbField: null, subField: null, sortable: false, singleton: 'main',
      matrix: {
        colFields: [{ k: 'name', label: 'Название', max: 40 }, { k: 'price', label: 'Цена, ₽ в месяц', max: 12 }, { k: 'note', label: 'Пометка', max: 40 }],
        rowKinds: ['bool', 'text'], rowLabel: { max: 120 },
        // the wording of this table: a collection of another site would say «пакет» and «услуга»
        words: {
          colNew: 'Новый тариф', colAdd: 'Добавить тариф', colPlural: ['тариф', 'тарифа', 'тарифов'], colName: 'Название тарифа', colNote: 'Пометка тарифа', colRemove: 'Удалить тариф', colRemoveAsk: 'Удалить тариф «{name}»?',
          colAdded: 'Новый тариф', colRemoved: 'Тариф удалён', colRenamed: 'Название тарифа', colsTitle: 'Тарифы', rowNew: 'Новая опция', rowAdd: 'Добавить опцию', rowPlural: ['опция', 'опции', 'опций'], rowName: 'Название опции',
          rowNone: 'Опций пока нет. Добавьте первую кнопкой ниже.', rowRemoved: 'Опция «{name}» удалена', rowAdded: 'Новая опция', rowGone: 'Опция удалена', rowRenamed: 'Название опции', rowsTitle: 'Опции', rowsUnit: 'строк',
          corner: 'Опция', group: 'Что входит в тариф', about: 'Таблица опций, которую посетители видят на странице цен. Строки — опции, столбцы — тарифы.', subject: 'в таблице тарифов', what: 'таблицу тарифов', ariaLabel: 'Тарифы и опции',
          colNeedName: 'Назовите каждый тариф, чтобы опубликовать', rowNeedName: 'Назовите каждую опцию, чтобы опубликовать', first: 'Таблица тарифов ещё не опубликована', priceHint: 'Пусто — на сайте «цена не задана»',
        },
      },
      tabs: [{ key: 'main', label: 'Таблица', fields: [] }], empty: { title: 'Таблицы нет', text: '' }, views: [{ type: 'matrix' }], filters: [], searchKeys: [], metrics: [], actions: [], tree: false, defaultSort: '', perPage: 25,
    },
  ],
  menuAreas: [],
  settings: { groups: [] },
  forms: [{ key: 'callback', title: 'Заказать звонок', fields: [] }, { key: 'order', title: 'Оформить заказ', fields: [
    { k: 'name', t: 'text', label: 'Имя' }, { k: 'phone', t: 'text', label: 'Телефон' }, { k: 'city', t: 'text', label: 'Город' },
    { k: 'comment', t: 'textarea', label: 'Комментарий клиента' }, { k: 'items', t: 'textarea', label: 'Состав заказа' },
  ] }],
  permissionAreas: [
    { key: 'pages', group: 'content', label: 'Страницы', actions: ['view', 'edit', 'publish', 'delete'], scope: 'templates' },
    { key: 'col:news', group: 'content', label: 'Новости', actions: ['view', 'edit', 'publish', 'delete'] },
    { key: 'col:plans', group: 'content', label: 'Тарифы', actions: ['view', 'edit', 'publish', 'delete'] },
    { key: 'requests', group: 'content', label: 'Заявки', actions: ['view', 'edit'] },
    { key: 'set:notifications', group: 'settings', label: 'Уведомления', actions: ['view', 'edit'] },
    { key: 'set:bots', group: 'settings', label: 'Bots', actions: ['view', 'edit'] },
  ],
  nav: [
    { key: 'main', group: '', icon: '', order: 0, items: [
      { key: 'today', label: 'Сегодня', to: '/', icon: 'today', order: 0 },
      { key: 'pages', label: 'Страницы', to: '/pages', icon: 'pages', order: 10 },
      { key: 'pages-product', label: 'Товары', to: '/pages?template=product', icon: 'box', order: 11, sub: true },
      { key: 'pages-blog_article', label: 'Статьи блога', to: '/pages?template=blog_article', icon: 'doc', order: 12, sub: true },
      { key: 'requests', label: 'Заявки', to: '/requests', icon: 'send', order: 20 },
    ] },
    { key: 'content', group: 'Содержимое', icon: '', order: 10, items: [
      { key: 'col-news', label: 'Новости', to: '/collections/news', icon: 'doc', order: 0 },
      { key: 'col-plans', label: 'Тарифы', to: '/collections/plans', icon: 'table', order: 1 },
    ] },
  ],
}

const USER = {
  id: 'u1', name: 'Анна Демова', email: 'demo@example.test', status: 'active', roleId: 'admin', roleName: 'Администратор', preset: 'admin', builtin: true,
  perms: { pages: ['view', 'edit', 'publish', 'delete'], 'col:news': ['view', 'edit', 'publish', 'delete'], 'col:plans': ['view', 'edit', 'publish', 'delete'], requests: ['view', 'edit'], 'set:notifications': ['view', 'edit'], 'set:bots': ['view', 'edit'] }, templates: null,
  twoFactor: { enabled: false, required: false, enrol: false }, locale: 'en', localePreference: null,
}

const T0 = '2026-10-20T09:00:00+00:00'
const section = (id, type, data, hidden = false) => ({ id, type, data, hidden })
const page = (id, parentId, template, title, url, extra = {}) => ({
  id, parentId, template, title, url, seo: { title: '', description: '' }, fields: {}, sections: [], updatedAt: T0, version: 'v1', migrated: false, awaiting: null, returned: null, ...extra,
})
const record = (id, data, status = 'published') => ({ id, status, updatedAt: T0, version: 'v1', awaiting: null, returned: null, ...data })

const PAGES = [
  page('p1', null, 'home', 'Главная', '/', {
    sections: [
      section('s1', 'hero', { headline: 'Демо-сайт для знакомства с админкой', text: '<p>Это выдуманные данные: их можно править, они живут в памяти страницы.</p>', btn: 'Узнать больше' }),
      section('s2', 'cards', { headline: 'Что внутри', items: [{ _id: 'i1', title: 'Страницы из секций', text: 'Собирайте страницу из готовых блоков.' }, { _id: 'i2', title: 'Коллекции', text: 'Списки записей: новости, товары.' }] }),
    ],
  }),
  page('p2', 'p1', 'default', 'О компании', '/about', { sections: [section('s3', 'text', { headline: 'О компании', body: '<p>Мы делаем сайты, которыми удобно управлять.</p>' })] }),
  page('p3', 'p1', 'default', 'Контакты', '/contacts', { sections: [section('s4', 'text', { headline: 'Контакты', body: '<p>Напишите нам: hello@example.test</p>' })] }),
  page('p4', null, 'catalog', 'Каталог', '/catalog', { sections: [section('s5', 'hero', { headline: 'Каталог', text: '', btn: '' })] }),
  page('p5', 'p4', 'product', 'Кольцо «Дымка»', '/catalog/dymka', { fields: { name: 'Кольцо «Дымка»', photo: { src: '', alt: '' }, info: 'Серебро, ручная работа', show: true } }),
  page('p6', 'p4', 'product', 'Кольцо «Прибой»', '/catalog/priboy', { fields: { name: 'Кольцо «Прибой»', photo: { src: '', alt: '' }, info: 'Золото 585', show: false } }),
  page('p7', null, 'blog', 'Блог', '/blog', { sections: [section('s6', 'hero', { headline: 'Блог', text: '', btn: '' })] }),
  page('p8', 'p7', 'blog_article', 'Как мы работаем', '/blog/how', { fields: { title: 'Как мы работаем', date: '2026-10-12', cover: { src: '', alt: '' }, excerpt: '<p>Коротко о нашем процессе.</p>' } }),
]

const NEWS = [
  record('n1', { title: 'Мы переехали в новый офис', date: '2026-10-28', kind: 'company', pinned: true, text: '<p>Теперь мы на другой улице.</p>' }),
  record('n2', { title: 'Вышла новая коллекция', date: '2026-10-20', kind: 'product', pinned: false, text: '<p>Подробности скоро.</p>' }),
  record('n3', { title: 'Итоги осени', date: '2026-10-05', kind: 'company', pinned: false, text: '<p>Спасибо, что были с нами.</p>' }),
  record('n4', { title: 'Анонс ноябрьской акции', date: '2026-11-03', kind: 'product', pinned: false, text: '<p>Черновик: ещё не опубликован.</p>' }, 'draft'),
  record('n5', { title: 'Мы на выставке', date: '2026-09-14', kind: 'company', pinned: false, text: '<p>Заходите на наш стенд.</p>' }),
]

const PLANS = {
  cols: [
    { id: 'c1', name: 'Старт', price: '900', note: '' },
    { id: 'c2', name: 'Про', price: '2 400', note: 'Популярный' },
    { id: 'c3', name: 'Максимум', price: '', note: 'По запросу' },
  ],
  rows: [
    { _id: 'r1', label: 'Собственный домен', kind: 'bool', vals: [false, true, true] },
    { _id: 'r2', label: 'Страниц на сайте', kind: 'text', vals: ['10', '50', 'без ограничений'] },
    { _id: 'r3', label: 'Поддержка', kind: 'text', vals: ['по почте', 'чат', 'менеджер'] },
  ],
}

const request = (id, form, place, phone, name, city, note, channels, work = 'new') => ({
  id, dt: `2026-11-0${id}T10:${10 + id}:00+00:00`, locale: 'ru', form, place, phone, name, city, note, work, comment: '', channels,
  log: [['ok', 'Заявка получена', `2026-11-0${id}T07:${10 + id}:00+00:00`]],
})
const REQUESTS = [
  request(3, 'callback', 'Заказать звонок', '+7 900 000-00-01', 'Ирина', 'Томск', 'Позвоните после 15:00', [{ channel: 'telegram', state: 'sent', error: null }, { channel: 'max', state: 'failed', error: 'chat not found' }]),
  {
    ...request(2, 'order', 'Оформить заказ', '+7 900 000-00-02', 'Павел', 'Новосибирск', '', [{ channel: 'telegram', state: 'sent', error: null }, { channel: 'max', state: 'sent', error: null }], 'progress'),
    data: { name: 'Павел', phone: '+7 900 000-00-02', city: 'Новосибирск', items: 'Кольцо «Дымка» × 2\nКольцо <Прибой> × 1' },
  },
  request(1, 'callback', 'Заказать звонок', '+7 900 000-00-03', 'Олег', '', '', [{ channel: 'telegram', state: 'sent', error: null }, { channel: 'max', state: 'sent', error: null }], 'closed'),
]
const SETTINGS = {
  tgOn: true, tgRoutes: { '*': '-1001234567890' }, tgFallback: false, tgReady: true, maxOn: true, maxRoutes: { '*': '-70000000000001', order: '-70000000000002,-70000000000003' }, maxFallback: false, maxReady: true,
  mailRoutes: {}, mailReady: false, forms: true,
}

/** A fresh copy of everything the mock knows. `pages` keeps the order of the list; a page is { draft fields…, published: snapshot|null }. */
export function makeDb() {
  const copy = (v) => JSON.parse(JSON.stringify(v))
  return {
    schema: copy(SCHEMA),
    user: copy(USER),
    prefs: {},
    seq: 100,
    requests: copy(REQUESTS),
    settings: copy(SETTINGS),
    bots: { telegram: { token: '', serverToken: '123456789:Demo_Telegram_Server_Token_0123456789' }, max: { token: '', serverToken: 'demo-max-server' } },
    pages: copy(PAGES).map((p) => ({ ...p, published: copy(p) })),
    collections: {
      news: { titleField: 'title', records: copy(NEWS).map((r) => ({ ...r, published: r.status === 'published' ? copy(r) : null })) },
      plans: { titleField: 'title', records: [{ id: 'main', status: 'published', updatedAt: T0, version: 'v1', awaiting: null, returned: null, ...copy(PLANS), published: copy({ ...PLANS }) }] },
    },
  }
}
