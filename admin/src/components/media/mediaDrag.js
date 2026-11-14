// What is dragged inside the media library: files (onto a folder) and folders (onto another folder). Dragging a file from the desktop is a different thing (upload).
export const FILES = 'application/x-itk-media-files'
export const FOLDER = 'application/x-itk-media-folder'

export const startDrag = (e, type, payload) => {
  e.dataTransfer.setData(type, JSON.stringify(payload))
  e.dataTransfer.setData('text/plain', '') // Firefox starts a drag only with some data
  e.dataTransfer.effectAllowed = 'move'
}
export const hasDrag = (e) => !!e.dataTransfer && [...e.dataTransfer.types].some((t) => t === FILES || t === FOLDER)
export function readDrag(e) {
  for (const type of [FILES, FOLDER]) {
    const raw = e.dataTransfer && e.dataTransfer.getData(type)
    if (raw) { try { return { type, payload: JSON.parse(raw) } } catch (err) { return null } }
  }
  return null
}
