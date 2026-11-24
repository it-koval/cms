import { expect, it } from 'vitest'
import ru from './ru/integrations.json'
import en from './en/integrations.json'
import kk from './kk/integrations.json'

it.each([['ru', ru], ['en', en], ['kk', kk]])('uses editor language for search settings in %s', (_language, dictionary) => {
  const keys = ['seo_intro', 'seo_tag_robots', 'seo_tag_canonical', 'seo_sets_indexing', 'seo_sets_canonical', 'seo_delete_message', 'seo_check_help', 'seo_tags_aria', 'seo_col_tag']
  for (const key of keys) expect(dictionary['integrations.' + key]).not.toMatch(/тег|индексац|канонич|\btags?\b|indexing|canonical|индекстеу|канондық/i)
  expect(dictionary['integrations.seo_delete_message']).toContain('{path}')
})
