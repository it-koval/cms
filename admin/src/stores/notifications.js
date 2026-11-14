// The bell of the admin: the number of unread notifications for the rail and the list for the panel «Уведомления».
//   import { notes, startPolling, stopPolling, openPanel, loadList, markRead, markAllRead } from '@/stores/notifications.js'
//   notes.unread      number of unread ones for this person (the badge on the bell)
//   notes.items       the loaded part of the list, newest first: [{ id, title, body, link, level, read, createdAt }]; notes.total = how many there are in the current filter
//   notes.unreadOnly  the panel shows only the unread ones;  notes.open  the panel is on screen
// startPolling() asks the server for the number once, then every 60 seconds while the tab is visible (and at once when it becomes visible again); every user of it calls
// stopPolling() when it goes away. While the panel is open the poll refreshes the list too. A poll that fails is silent: the bell is never worth an error.
import { reactive } from 'vue'
import { api } from '@/api/index.js'
import { t } from '@/i18n/index.js'

const POLL_MS = 60000
const PAGE = 30

export const notes = reactive({ unread: 0, items: [], total: 0, loading: false, more: false, loaded: false, error: '', unreadOnly: false, open: false })

let timer = 0
let users = 0
let listSeq = 0

/** The cheap poll: only the number (the answer carries one item at most). */
export async function refreshUnread() {
  try {
    const r = await api.notifications.list({ unread: true, limit: 1 })
    notes.unread = r.unread
    if (notes.open) await loadList({ quiet: true })
  } catch (e) { /* the next poll tries again */ }
}

/** Load the list for the panel; `append` adds the next page, `quiet` keeps what is on screen while it loads (the poll). */
export async function loadList({ append = false, quiet = false } = {}) {
  const my = ++listSeq
  if (append) notes.more = true
  else if (!quiet) { notes.loading = true; notes.error = '' }
  try {
    // a refresh keeps as many rows as the person has already opened
    const limit = append ? PAGE : Math.max(PAGE, notes.items.length)
    const r = await api.notifications.list({ unread: notes.unreadOnly, limit: Math.min(limit, 100), offset: append ? notes.items.length : 0 })
    if (my !== listSeq) return
    notes.items = append ? [...notes.items, ...r.items] : r.items
    notes.total = r.total
    notes.unread = r.unread
    notes.loaded = true
    notes.error = ''
  } catch (e) {
    if (my === listSeq && !quiet) notes.error = (e && e.message) || t('core.notifications_load_failed')
  } finally {
    if (my === listSeq) { notes.loading = false; notes.more = false }
  }
}

export function openPanel() {
  notes.open = true
  return loadList()
}
export function closePanel() {
  notes.open = false
  listSeq++
}
export function setUnreadOnly(value) {
  if (notes.unreadOnly === value) return Promise.resolve()
  notes.unreadOnly = value
  return loadList()
}

/** One notification is read: marked at once, confirmed by the server (which also gives the true number). */
export async function markRead(item) {
  if (item.read) return
  item.read = true
  notes.unread = Math.max(0, notes.unread - 1)
  try {
    notes.unread = (await api.notifications.markRead([item.id])).unread
  } catch (e) {
    item.read = false
    notes.unread += 1
    throw e
  }
}

export async function markAllRead() {
  const r = await api.notifications.markAllRead()
  notes.items.forEach((i) => { i.read = true })
  notes.unread = r.unread
  if (notes.unreadOnly) await loadList()
}

const tick = () => { if (typeof document === 'undefined' || !document.hidden) refreshUnread() }
const onVisible = () => { if (!document.hidden) refreshUnread() }

export function startPolling() {
  users += 1
  if (users > 1) return
  refreshUnread()
  timer = window.setInterval(tick, POLL_MS)
  document.addEventListener('visibilitychange', onVisible)
}
export function stopPolling() {
  users = Math.max(0, users - 1)
  if (users > 0) return
  window.clearInterval(timer)
  timer = 0
  document.removeEventListener('visibilitychange', onVisible)
}
