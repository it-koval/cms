import { onScopeDispose, ref, watch } from 'vue'

// A missing or failed usage check is never an empty result. Ignore responses for an older selection, including A → B → A.
export function useMediaUsage(selectedId, fetchUsage) {
  const usage = ref(null)
  const usageError = ref(false)
  let generation = 0

  async function scan(id) {
    const request = ++generation
    usage.value = null
    usageError.value = false
    if (!id) return
    try {
      const rows = await fetchUsage(id)
      if (request === generation) usage.value = rows
    } catch {
      if (request === generation) usageError.value = true
    }
  }

  watch(selectedId, scan, { immediate: true, flush: 'sync' })
  onScopeDispose(() => { generation++ })
  return { usage, usageError, retryUsage: () => scan(selectedId.value) }
}
