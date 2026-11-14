import { clone, same } from '@/utils/objects.js'

// Apply the normalized server response, keeping fields the user changed after submitting.
export function rebaseSettings(response, submitted, current) {
  const draft = clone(response)
  for (const key of new Set([...Object.keys(submitted), ...Object.keys(current)])) {
    if (same(current[key], submitted[key])) continue
    if (Object.hasOwn(current, key)) draft[key] = clone(current[key])
    else delete draft[key]
  }
  return draft
}
