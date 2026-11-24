// Work status and private note of a request. They are fields of the request itself (api.requests: `work`, `comment`); this file only
// holds the labels and the small rules the screens share. Nothing is stored here.
//
// ONE rule for «new»: work status 'new' (includes requests the messengers did not take until somebody picks them up). The rail badge,
// the bold rows, «Новые заявки» on «Сегодня» and the «Новая» filter all count by it.
import { WORK_STATUSES, workLabel } from '@/schema/requests.js'

export { WORK_STATUSES, workLabel }
export const workOf = (r) => r.work || 'new'
export const isNewRequest = (r) => workOf(r) === 'new'
export const commentOf = (r) => r.comment || ''
export const deliveryFailed = (r) => r.st === 'failed'

/** Names of the messengers a request is sent to (brand names, the same in every language). */
export const CHANNEL_NAMES = { telegram: 'Telegram', max: 'MAX' }
export const channelName = (c) => CHANNEL_NAMES[c] || c
/** The delivery state of every channel of a request: [{ channel, state: 'pending'|'sent'|'failed', error }] ([] for a server that does not send `channels`). */
export const channelsOf = (r) => (Array.isArray(r.channels) ? r.channels : [])
/** The messengers a request was never sent to and can be sent to now (the server decides: `sendable`). [] for a server that does not send it. */
export const sendableOf = (r) => (Array.isArray(r.sendable) ? r.sendable : [])
/** The chip status of a channel state: sent -> delivered. */
export const chipOf = (state) => (state === 'sent' ? 'delivered' : state === 'failed' ? 'failed' : 'pending')

/** What a resend answered, for the toast: { state: 'sent'|'failed'|'pending', error }. With a channel only that channel counts; without one the aggregate does. */
export function resendOutcome(r, channel) {
  const outcomes = Object.values(r.outcomes || {})
  const failed = channel ? r.outcomes?.[channel]?.state === 'failed' && r.outcomes[channel] : outcomes.find((outcome) => outcome.state === 'failed')
  if (failed) return { state: 'failed', error: failed.error || '' }
  const queued = Array.isArray(r.queuedChannels) ? r.queuedChannels : []
  if (channel ? queued.includes(channel) : queued.length > 0) return { state: 'queued', error: '' }
  if (channel) {
    const c = channelsOf(r).find((x) => x.channel === channel)
    if (c) return { state: c.state, error: c.error || '' }
  }
  return { state: r.delivery === 'failed' ? 'failed' : r.delivery === 'sent' ? 'sent' : r.delivery === 'pending' ? 'pending' : 'sent', error: r.deliveryError || '' }
}
