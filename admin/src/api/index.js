// One import for the whole API client:  import { api } from '@/api/index.js'   then   await api.pages.list()
// Every module is a thin fetch wrapper over /admin/api (see http.js); the endpoint of each function is written above it.
import * as site from './site.js'
import * as pages from './pages.js'
import * as collections from './collections.js'
import * as requests from './requests.js'
import * as media from './media.js'
import * as settings from './settings.js'
import * as proposals from './proposals.js'
import * as users from './users.js'
import * as twofactor from './twofactor.js'
import * as prefs from './prefs.js'
import * as actions from './actions.js'
import * as history from './history.js'
import * as notifications from './notifications.js'
import * as health from './health.js'
import * as theme from './theme.js'
import * as mail from './mail.js'
import * as bots from './bots.js'
import * as mcp from './mcp.js'
import * as webmaster from './webmaster.js'

export const api = { site, pages, collections, requests, media, settings, proposals, users, prefs, actions, history, notifications, theme, health, twofactor, mail, bots, mcp, webmaster }
export { ApiError, isConflict, whileClosing } from './http.js'
