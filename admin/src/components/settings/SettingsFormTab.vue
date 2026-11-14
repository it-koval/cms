<script setup>
// The card of one settings group of the schema («Контакты и соцсети», «Подвал», ...): title, description, fields. Edits a plain settings object through events.
// `readonly` (the role may only look at this group): a note above the card, the fields inert; it is per card, the other cards of the page stay editable.
// The card is the target of the group links of «Основное» (id `card-<group>`, the title takes focus).
// Shows the magenta «Изменено / Отменить изменение» marks (settings differ from the saved ones) and errors passed by the view.
// `marks` (sites with 2+ languages): before every run of fields of one kind a caption says whether they are the TEXTS of the content language
// (field.tr: «Тексты на английском») or shared by all languages («Одно для всех языков»).
import { provide } from 'vue'
import FieldRenderer from '@/components/form/fields/FieldRenderer.vue'
import FieldLayout from '@/components/form/FieldLayout.vue'
import Icon from '@/components/ui/Icon.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import { t } from '@/i18n/index.js'
import { tag } from '@/utils/locales.js'
import { SHARED_LABEL, textsLabel } from './langText.js'

defineProps({
  sections: { type: Array, required: true },
  data: { type: Object, required: true },
  changed: { type: Object, required: true }, // Set of changed field keys
  errors: { type: Object, default: () => ({}) },
  marks: { type: Boolean, default: false },
  locale: { type: String, default: '' },
  readonly: { type: Boolean, default: false },
})
const emit = defineEmits(['set', 'revert'])
provide('formId', 'set')

/** Neighbouring fields of the same kind go under one caption: [{ tr, fields }]. */
function runs(fields) {
  const out = []
  fields.forEach((f) => {
    const tr = !!f.tr
    const last = out[out.length - 1]
    if (last && last.tr === tr) last.fields.push(f)
    else out.push({ tr, fields: [f] })
  })
  return out
}
</script>

<template>
  <div>
    <ReadOnlyNote v-if="readonly" :text="t('settings.view_only_card', { title: sections[0] ? sections[0].title : '' })" />
    <div :class="{ 'ro-zone': readonly }" :inert="readonly || undefined">
      <section v-for="s in sections" :id="'card-' + s.key" :key="s.key" class="sheet set-sec set-card" :aria-labelledby="'sec-' + s.key">
        <h3 :id="'sec-' + s.key" tabindex="-1">{{ s.title }}</h3>
        <p v-if="s.desc" class="d">{{ s.desc }}</p>
        <template v-for="(r, i) in runs(s.fields)" :key="i">
          <div v-if="marks" class="sf-cap">
            <span v-if="r.tr" class="chip ai"><b>{{ tag(locale) }}</b>{{ textsLabel(locale) }}</span>
            <span v-else class="chip"><Icon name="globe" size="s14" />{{ SHARED_LABEL }}</span>
          </div>
          <FieldLayout :items="r.fields">
            <template #default="{ item: f }">
              <FieldRenderer
                :field="f" :path="f.k" :model-value="data[f.k]" :top="true"
                :changed="changed.has(f.k)" :error="errors[f.k] || ''"
                @update:model-value="emit('set', f.k, $event)" @revert="emit('revert', f.k)"
              />
            </template>
          </FieldLayout>
        </template>
      </section>
    </div>
  </div>
</template>

<style scoped>
.sf-cap{margin:10px 0 0}
.sf-cap .chip b{font-weight:700;letter-spacing:.02em}
/* a repeater with an error must not paint the inputs of its items red: only the item field that is itself wrong is */
.set-sec :deep(.field.is-error .rep-b .field:not(.is-error) input),
.set-sec :deep(.field.is-error .rep-b .field:not(.is-error) textarea) { border-color: var(--ctl); background: #fff; }
.set-sec :deep(.field.is-error .rep-b .field:not(.is-error) input:focus) { border-color: var(--cy); box-shadow: 0 0 0 3px var(--cy-100); }
</style>
