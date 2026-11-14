import { afterEach, describe, expect, it } from 'vitest'
import { initI18n, t } from '@/i18n/index.js'
import { cap, capParam, verAcc, verGen, verNom } from '@/utils/langWords.js'
import { versionNone } from '@/components/pages/langWords.js'

// The real catalogues. A phrase starts with a capital letter whatever the word order of the language puts first (a parameter in Kazakh), and has no capital in the middle.
afterEach(async () => {
  await initI18n('ru')
})

const render = {
  deleteVersion: (code) => cap(t('collections.delete_version', { version: verAcc(code) })),
  publishCount: (code) => cap(t('collections.pp_count_lang', { count: 2, version: verGen(code) })),
  noVersionChip: (code) => cap(t('editor.chip_no_version', { ver: versionNone(code) })),
  note: (code) => capParam('editor.missing_note', { ver: verNom(code), title: 'Home' }, 'ver'),
}

describe('capital letters in sentences with a language word', () => {
  it('kk: a parameter that opens the phrase is capitalised, one in the middle is not', async () => {
    await initI18n('kk')
    expect(render.deleteVersion('en')).toBe('Ағылшын нұсқасын жою')
    expect(render.publishCount('ru')).toBe('Орыс нұсқасының ішінде 2 өзгеріс. Басқа тілдер өзгермейді.')
    expect(render.noVersionChip('en')).toBe('Беттің ағылшын нұсқасы әлі жоқ')
    expect(render.note('en')).toBe('«Жариялау» түймесін баспайынша, жоба сайтта көрінбейді. «Home» бетінің ағылшын нұсқасы бұдан өзгермейді.')
  })

  it('ru: the text stays as it was written', async () => {
    await initI18n('ru')
    expect(render.deleteVersion('en')).toBe('Удалить английскую версию')
    expect(render.publishCount('en')).toBe('2 изменения в английской версии. Другие языки не изменятся.')
    expect(render.noVersionChip('en')).toBe('Английской версии у страницы ещё нет')
    expect(render.note('en')).toBe('Черновик не виден на сайте, пока вы не нажмёте «Опубликовать». Английская версия страницы «Home» от этого не меняется.')
  })

  it('en: a parameter in the middle of the phrase gets no capital letter of its own', async () => {
    await initI18n('en')
    expect(render.deleteVersion('en')).toBe('Delete the English version')
    expect(render.noVersionChip('en')).toBe('The page has no English version yet')
    expect(capParam('editor.missing_note', { ver: 'English version', title: 'Home' }, 'ver')).toContain('The English version of the page')
  })
})
