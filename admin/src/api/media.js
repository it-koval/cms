// Media library API: a thin fetch client over /admin/api.
//   list({ q, type, folder })  GET    media?q=&type=&folder=    type: '' | 'img' | 'pdf'; folder: '' (all files) | 'none' (outside any folder) | a folder id
//   get(id)                  the item of list() with that id (404 otherwise)
//   usage(id)                GET    media/:id/usage           [{ label, to }]  where the file is used (pages, collection records, settings)
//   upload(file, folderId)   POST   media (multipart, field `file`, optional `folderId`)   a File from <input type=file>; the size limit is the server's (413 with its message)
//   update(id, patch)        PATCH  media/:id                 { alt, name, folderId, focal, crops }
//   move(ids, folderId)      POST   media/move                { moved }  folderId null: the top level; all or none
//   remove(id)               DELETE media/:id
//   folders()                GET    media-folders             { folders, total, unfiled, ratios }  folders: [{ id, parentId, name, position, files }]
//   createFolder(name, parentId)      POST    media-folders
//   updateFolder(id, patch)           PATCH   media-folders/:id   { name, parentId (null: top level), position }
//   removeFolder(id, moveTo)          DELETE  media-folders/:id?moveTo=   moveTo: a folder id or 'root'; a folder with content is refused (409) without it
// An item: { id, name, src, thumb (w320 WebP of an image; use it for previews, src is the original), w, h, kb, alt, type:'img'|'pdf', folderId,
//   focal: { x, y } | null, crops: { '16:9': { x, y, w, h } } (fractions 0..1 of the picture), used:[{ label, to }], createdAt }.
import { ApiError, http } from './http.js'
import { t } from '@/i18n/index.js'

export const list = ({ q = '', type = '', folder = '' } = {}) => http.get('media', { q: q.trim(), type, folder })
export async function get(id) {
  const m = (await list()).find((x) => x.id === id)
  if (!m) throw new ApiError(t('core.api_file_not_found'), 404)
  return m
}
export const usage = (id) => http.get(`media/${encodeURIComponent(id)}/usage`)

export async function upload(file, folderId = null) {
  if (!file) throw new ApiError(t('core.api_file_not_chosen'), 422)
  const form = new FormData()
  form.append('file', file, file.name)
  if (folderId) form.append('folderId', folderId)
  return http.upload('media', form)
}
export const update = (id, patch) => http.patch(`media/${encodeURIComponent(id)}`, patch)
export const move = (ids, folderId = null) => http.post('media/move', { ids, folderId })
export const remove = (id) => http.del(`media/${encodeURIComponent(id)}`)

export const folders = () => http.get('media-folders')
export const createFolder = (name, parentId = null) => http.post('media-folders', { name, parentId })
export const updateFolder = (id, patch) => http.patch(`media-folders/${encodeURIComponent(id)}`, patch)
export const removeFolder = (id, moveTo = '') => http.del(`media-folders/${encodeURIComponent(id)}`, { moveTo })
