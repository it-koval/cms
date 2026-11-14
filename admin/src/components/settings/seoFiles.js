// robots.txt and llms.txt of the site (Настройки → SEO → second segment): the small rules of the panel as pure functions.
// A file from the server is { text, default, custom, file }: text = what the site serves from the settings, default = the standard text, custom = an own text is set,
// file = a file of that name lies in public/ and the site serves it instead of the text. The field is a draft; «Сохранить» sends only the files whose draft changed.

/** The files of the panel, in order; the key is the one of the API (GET / PUT seo-files) and the public address is /<key>.txt. */
export const FILES = ['robots', 'llms']

/** The public name of a file: robots -> robots.txt. */
export const fileName = (key) => `${key}.txt`

// a browser gives the value of a textarea with \n only; the server may hold \r\n
const norm = (s) => String(s ?? '').replace(/\r\n?/g, '\n')

/** Is the draft different from the saved text of the file? */
export const isDirty = (draft, file) => !!file && norm(draft) !== norm(file.text)

/** «Вернуть стандартный» shows while the field holds anything but the standard text (an own text always differs from it: the server keeps a text equal to the standard one as «standard»). */
export const canResetToDefault = (draft, file) => !!file && norm(draft) !== norm(file.default)

/** The body of PUT seo-files: only the files with a changed draft; a draft equal to the standard text goes as null, which means «standard». */
export function savePatch(drafts, files) {
  const out = {}
  FILES.forEach((k) => {
    if (!files[k] || !isDirty(drafts[k], files[k])) return
    out[k] = norm(drafts[k]) === norm(files[k].default) ? null : drafts[k]
  })
  return out
}

/** The drafts a screen starts from (and returns to after a save): the text the site serves now. */
export const draftsOf = (files) => Object.fromEntries(FILES.map((k) => [k, files[k] ? files[k].text : '']))
