import { reactive } from 'vue'

// One drawer session owns one note. Saves are serialized, including edits made while a save is in flight.
export function requestNote(initial, write) {
  let flight = null
  const note = reactive({
    text: initial,
    saved: initial,
    saving: false,
    get dirty() { return this.text.trim() !== this.saved.trim() },
    async save() {
      if (flight) return flight
      if (!note.dirty) return
      note.saving = true
      flight = flush()
      try { await flight } finally { flight = null; note.saving = false }
    },
  })
  async function flush() {
    while (note.dirty) {
      const text = note.text.trim()
      await write(text)
      note.saved = text
    }
  }
  return note
}
