import { t } from '@/i18n/index.js'

// Work statuses of a request (set by the staff by hand). Shared by the Заявки screens.
// «new» is the only unread status: the rail badge, the bold rows and «Новые заявки» on «Сегодня» all count by it.
export const WORK_STATUSES = [['new', t('forms.status_new')], ['progress', t('forms.status_progress')], ['closed', t('forms.status_closed')], ['spam', t('forms.status_spam')]]
export const workLabel = (w) => (WORK_STATUSES.find((s) => s[0] === w) || [w, w])[1]
