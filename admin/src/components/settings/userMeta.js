// Words about a user, shared by the list and the drawer of Настройки → Пользователи.
import { fmtUpdated } from '@/utils/format.js'
import { t } from '@/i18n/index.js'

/** Status of a user: chip class (base.css .chip), the word, an icon for the neutral one. */
export const STATUS = {
  active: { cls: 'pub', label: t('people.status_active'), dot: true },
  invited: { cls: 'warn', label: t('people.status_invited'), dot: true },
  blocked: { cls: '', label: t('people.status_blocked'), icon: 'lock' },
}
export const statusOf = (u) => STATUS[u.status] || STATUS.active
export const lastSeen = (u) => (u.lastSignIn ? fmtUpdated(u.lastSignIn) : t('people.last_seen_never'))

export const LAST_ADMIN = t('people.last_admin')
