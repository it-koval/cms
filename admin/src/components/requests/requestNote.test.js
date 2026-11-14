import { describe, expect, it, vi } from 'vitest'
import { requestNote } from './requestNote.js'

describe('the note of an open request', () => {
  it('waits for a newer edit before closing and sends each edit only once', async () => {
    let finish
    const write = vi.fn().mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
    const note = requestNote('', write)
    note.text = 'First'
    const click = note.save()
    note.text = 'First, then second'
    const leave = note.save()
    expect(write.mock.calls).toEqual([['First']])
    finish()
    await Promise.all([click, leave])
    expect(write.mock.calls).toEqual([['First'], ['First, then second']])
    expect(note.dirty).toBe(false)
    expect(note.text).toBe('First, then second')
  })

  it('preserves typed text after a failed save and allows a retry', async () => {
    const write = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce({})
    const note = requestNote('Original', write)
    note.text = 'Edited'
    await expect(note.save()).rejects.toThrow('offline')
    expect(note.dirty).toBe(true)
    expect(note.saving).toBe(false)
    await note.save()
    expect(note.text).toBe('Edited')
    expect(note.dirty).toBe(false)
  })

  it('persists a return to the previous text while a different value is being saved', async () => {
    let finish
    const write = vi.fn().mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
    const note = requestNote('Original', write)
    note.text = 'Change'
    const save = note.save()
    note.text = 'Original'
    const leave = note.save()
    finish()
    await Promise.all([save, leave])
    expect(write.mock.calls).toEqual([['Change'], ['Original']])
  })
})
