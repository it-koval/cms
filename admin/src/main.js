// The entry of the admin. The catalogue of the language comes first and the app is imported after it, so t() works at the top of any module of the app (constants, schemas, the menu):
// this file imports nothing that calls it. The global styles stay here, in the entry the shell reads from the manifest (the font of the sign-in pages is found through them).
import '@fontsource-variable/golos-text' // Golos Text, served by the admin itself (no request to Google Fonts)
import './styles/tokens.css'
import './styles/base.css'
import './styles/views.css'
import './styles/editor.css'
import './styles/responsive.css'
import { initI18n } from './i18n/index.js'

// a catalogue that did not arrive must not leave a white page: the screen then shows keys, and the reason is in the console
initI18n().catch((e) => console.error(e)).then(() => import('./start.js')).then(({ start }) => start())
