// TipTap extensions of the admin: the «gradient» mark and the headline document.
import { Extension, Mark, Node } from '@tiptap/core'

/** Gradient mark: stored/emitted as <span class="grad">, shown in the editor as ink on the brand wash (styles/richtext.css). */
export const Gradient = Mark.create({
  name: 'gradient',
  parseHTML() { return [{ tag: 'span.grad' }] },
  renderHTML() { return ['span', { class: 'grad' }, 0] },
  addCommands() {
    return {
      toggleGradient: () => ({ commands }) => commands.toggleMark(this.name),
      unsetGradient: () => ({ commands }) => commands.unsetMark(this.name),
    }
  },
})

/** Headline: the document holds exactly one paragraph (no block structure). */
export const SingleBlockDoc = Node.create({ name: 'doc', topNode: true, content: 'paragraph' })

/** Enter (and Shift+Enter) insert a line break instead of splitting the paragraph. `allowBreak:false` swallows Enter. */
export const EnterIsBreak = Extension.create({
  name: 'enterIsBreak',
  priority: 1000,
  addOptions() { return { allowBreak: true } },
  addKeyboardShortcuts() {
    const run = () => { if (this.options.allowBreak) this.editor.commands.setHardBreak(); return true }
    return { Enter: run, 'Shift-Enter': run, 'Mod-Enter': run }
  },
})
