// Labels of the Settings screen about content languages. The grammar («на английском») is shared: utils/langWords.js.
import { onLang } from '@/utils/langWords.js'
import { t } from '@/i18n/index.js'

/** The two kinds of settings, as the client is told about them. */
export const SHARED_LABEL = t('settings.shared_label')
export const textsLabel = (code) => t('settings.texts_in_lang', { lang: onLang(code) })
