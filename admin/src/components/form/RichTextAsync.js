// RichText (TipTap and ProseMirror, the heaviest dependency) is loaded when a rich-text field is first drawn, not with the admin: «Сегодня», the lists and the login steps do not need it.
// The screens that focus such a field (the page editor, a record of a collection) load it ahead, with the route (router/index.js): a field drawn after that gets the editor in its first render,
// as with a plain import, so focusing it on the next tick works the first time. That is why this is not defineAsyncComponent: its first instance waits some ticks even when the chunk is already here.
// A ref on this wrapper reaches the editor itself (focus(), focusEnd()); until it is loaded they do nothing.
import { defineComponent, h, ref, shallowRef } from 'vue'

const RichText = shallowRef(null)
let chunk = null

/** Loads the editor once; a load that failed is tried again by the next call. */
export function preloadRichText() {
  chunk ||= import('./RichText.vue').then((m) => { RichText.value = m.default }).catch((e) => { chunk = null; throw e })
  return chunk
}

export default defineComponent({
  name: 'RichTextAsync',
  inheritAttrs: false,
  setup(_, { attrs, expose }) {
    const inner = ref(null)
    preloadRichText().catch((e) => console.error(e))
    expose({ focus: () => inner.value && inner.value.focus(), focusEnd: () => inner.value && inner.value.focusEnd() })
    return () => (RichText.value ? h(RichText.value, { ...attrs, ref: inner }) : null)
  },
})
