// Work status and private note of a request. They are fields of the request itself (api.requests: `work`, `comment`); this file only
// holds the labels and the small rules the screens share. Nothing is stored here.
//
// ONE rule for «new»: work status 'new' (includes requests Telegram did not take until somebody picks them up). The rail badge,
// the bold rows, «Новые заявки» on «Сегодня» and the «Новая» filter all count by it.
import { WORK_STATUSES, workLabel } from '@/schema/requests.js'

export { WORK_STATUSES, workLabel }
export const workOf = (r) => r.work || 'new'
export const isNewRequest = (r) => workOf(r) === 'new'
export const commentOf = (r) => r.comment || ''
export const deliveryFailed = (r) => r.st === 'failed'
