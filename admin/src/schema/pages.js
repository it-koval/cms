// Page-level fields (outside sections): title, address, SEO. They come from the schema (schema.pageFields), the template's own fields are in schema/templates.js.
// Keys may be dotted ('seo.title'): SchemaForm reads them with getPath. PAGE_FIELDS is ONE array that is filled in place when the schema is loaded.
import { onSchema } from './registry.js'

export const PAGE_FIELDS = []
onSchema((schema) => PAGE_FIELDS.splice(0, PAGE_FIELDS.length, ...schema.pageFields))
