// Plugin “@@title@@” for the admin panel. The server loads this script: Registry::adminAssets(js: ['@@jsUrl@@']).
// The screen opens from the menu item NavItem::make('@@title@@', '/x/@@key@@') at #/x/@@key@@.
// Vue is the same instance as in the admin panel (window.ITK.vue). Also available: ITK.http (API client with CSRF), ITK.toast, ITK.confirm, ITK.ui ({ FieldShell, Icon, Modal }), ITK.i18n (interface language).
const { defineComponent, h, ref, onMounted } = window.ITK.vue
const { t, add } = window.ITK.i18n

// Plugin texts per interface language. One language is enough: the others fall back to the key or the English text.
add('en', { '@@key@@.loading': 'Loading…', '@@key@@.ready': 'The plugin screen works.' })
add('ru', { '@@key@@.loading': 'Загружаем…', '@@key@@.ready': 'Экран плагина работает.' })
add('kk', { '@@key@@.loading': 'Жүктелуде…', '@@key@@.ready': 'Плагин экраны жұмыс істеп тұр.' })

const @@component@@Panel = defineComponent({
  name: '@@component@@Panel',
  props: { path: { type: String, default: '' }, query: { type: Object, default: () => ({}) } },
  setup() {
    const info = ref(t('@@key@@.loading'))
    onMounted(async () => {
      // example request to the admin panel API: window.ITK.http is a client that sends the CSRF token
      info.value = t('@@key@@.ready')
    })
    return () => h('div', { class: 'page' }, [h('div', { class: 'sheet' }, [h('h1', @@titleJs@@), h('p', info.value)])])
  },
})

window.ITK.registerPlugin({
  panels: { '@@key@@': @@component@@Panel },
  // fields:  { 'rating': RatingField },
  // actions: { 'tours:export': async (ctx) => ctx.run({ format: 'csv' }) },
})
