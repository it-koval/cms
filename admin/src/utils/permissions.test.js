import { describe, expect, it } from 'vitest'
import { ACTIONS, MATRIX_COLUMNS } from './permissions.js'

describe('MATRIX_COLUMNS', () => {
  it('puts «Публиковать» first in the role matrix, then the rest in their usual order', () => {
    expect(MATRIX_COLUMNS.map((c) => c.key)).toEqual(['publish', 'view', 'edit', 'delete'])
  })

  it('shows every action with the label of ACTIONS', () => {
    expect([...MATRIX_COLUMNS].sort((a, b) => a.key.localeCompare(b.key))).toEqual([...ACTIONS].sort((a, b) => a.key.localeCompare(b.key)))
  })
})
