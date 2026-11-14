import { computed, ref, watch } from 'vue'

// A close or navigation can join a manual save already in flight. Only the submitted text and file may leave.
export function useMediaAlt(item, canEdit, write) {
  const alt = ref('')
  const saving = ref(false)
  let pending = null
  let generation = 0
  watch(() => item.value?.id, () => {
    generation++
    alt.value = item.value?.alt || ''
  }, { immediate: true, flush: 'sync' })
  const altDirty = computed(() => canEdit.value && !!item.value && item.value.type !== 'pdf' && alt.value.trim() !== (item.value.alt || '').trim())

  async function saveAlt() {
    if (pending) return pending
    if (!altDirty.value) return true
    const session = generation
    const text = alt.value.trim()
    saving.value = true
    pending = write(item.value.id, text).then((saved) => saved && generation === session && alt.value.trim() === text)
    try {
      return await pending
    } finally {
      pending = null
      saving.value = false
    }
  }
  return { alt, altDirty, saving, saveAlt }
}
