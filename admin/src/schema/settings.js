// Site settings schema: the groups declared in the site schema ($s->settings('contacts', 'Контакты и соцсети')->fields(...)), plus the redirect options.
// Settings are saved at once (no publish step). Fields flagged `tr: true` are the TEXTS of one content language, every other field is shared by all languages.
import { t } from '@/i18n/index.js'
import { schema } from './registry.js'

/** Flat field list of all groups. */
export const getSettingsFields = () => schema.settings.groups.flatMap((g) => g.fields)

export const REDIRECT_CODES = [[301, t('forms.redirect_permanent')], [302, t('forms.redirect_temporary')]]
