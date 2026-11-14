<script setup>
// «Добавить область меню» / «Переименовать»: the name, whether items may have nested ones and (read-only) the code the developers put into the site template.
// An area is structure, not content: it is saved at once (api.settings.createMenuArea / updateMenuArea), not with the «Сохранить» bar of the items.
import { computed, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import FieldShell from '@/components/form/FieldShell.vue'
import Icon from '@/components/ui/Icon.vue'
import { api } from '@/api/index.js'
import { useToast } from '@/components/ui/useToast.js'
import { makeAreaKey, menuSnippet } from '@/schema/menuAreas.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  area: { type: Object, default: null }, // null = a new area
  taken: { type: Array, default: () => [] }, // keys of the existing areas (for the preview of a new key)
})
const emit = defineEmits(['update:open', 'saved'])
const toast = useToast()

const MAX = 40
const name = ref('')
const depth = ref(1)
const submitted = ref(false)
const busy = ref(false)
const serverError = ref('')

watch(() => props.open, (o) => {
  if (!o) return
  name.value = props.area ? props.area.name : ''
  depth.value = props.area ? props.area.depth : 1
  submitted.value = false; busy.value = false; serverError.value = ''
})

const budget = computed(() => ({ n: [...name.value].length, max: MAX }))
const nameError = computed(() => (submitted.value && !name.value.trim() ? t('settings.area_name_required') : '') || serverError.value)
const key = computed(() => (props.area ? props.area.key : makeAreaKey(name.value.trim() || 'menu', props.taken)))
const flattens = computed(() => !!props.area && props.area.depth === 2 && depth.value === 1)
const dirty = computed(() => (props.area ? name.value.trim() !== props.area.name || depth.value !== props.area.depth : !!name.value.trim()))
const close = () => emit('update:open', false)

async function save() {
  submitted.value = true
  serverError.value = ''
  if (!name.value.trim() || budget.value.n > MAX) return
  busy.value = true
  try {
    const body = { name: name.value.trim(), depth: depth.value }
    const row = props.area ? await api.settings.updateMenuArea(props.area.key, body) : await api.settings.createMenuArea(body)
    emit('saved', { area: row, created: !props.area, flattened: flattens.value })
    toast.show(props.area ? t('settings.area_saved', { name: row.name }) : t('settings.area_added', { name: row.name }))
    close()
  } catch (e) {
    if (e.status === 422) serverError.value = e.message
    else toast.error(e.message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :open="open" :title="area ? t('settings.area_settings_title') : t('settings.menus_add_area')" :width="520" initial-focus="#ma-name" :close-on-scrim="!dirty" @update:open="emit('update:open', $event)">
    <form class="ma-dialog" novalidate @submit.prevent="save">
      <FieldShell :label="t('settings.area_name')" for-id="ma-name" :budget="budget" :error="nameError" required :help="area ? '' : t('settings.area_name_help')">
        <input id="ma-name" v-model="name" type="text" autocomplete="off" :placeholder="t('settings.area_name_placeholder')" :aria-invalid="nameError ? 'true' : undefined">
      </FieldShell>

      <FieldShell :label="t('settings.area_kind_label')" label-id="ma-depth-l">
        <div class="ma-opts" role="radiogroup" aria-labelledby="ma-depth-l">
          <label :class="['ma-o', { on: depth === 1 }]">
            <input v-model="depth" type="radio" name="ma-depth" :value="1">
            <b>{{ t('settings.area_simple') }}</b><small>{{ t('settings.area_simple_desc') }}</small>
          </label>
          <label :class="['ma-o', { on: depth === 2 }]">
            <input v-model="depth" type="radio" name="ma-depth" :value="2">
            <b>{{ t('settings.area_nested') }}</b><small>{{ t('settings.area_nested_desc') }}</small>
          </label>
        </div>
      </FieldShell>
      <p v-if="flattens" class="ma-note"><Icon name="info" size="s16" />{{ t('settings.area_flatten_note') }}</p>

      <div class="ma-key">
        <span class="ma-k-l">{{ area ? t('settings.area_code_fixed') : t('settings.area_code_dev') }}</span>
        <code>{{ menuSnippet(key) }}</code>
      </div>
      <p v-if="!area" class="ma-note"><Icon name="info" size="s16" />{{ t('settings.area_new_note') }}</p>
      <button type="submit" class="sr" tabindex="-1">{{ t('settings.save') }}</button>
    </form>

    <template #footer>
      <button class="btn" type="button" @click="close">{{ t('settings.cancel') }}</button>
      <button :class="['btn primary', { loading: busy }]" type="button" :disabled="!dirty" @click="save">{{ area ? t('settings.save') : t('settings.area_add_confirm') }}</button>
    </template>
  </Modal>
</template>

<style scoped>
.ma-dialog{display:flex;flex-direction:column;gap:2px}
.ma-opts{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.ma-o{position:relative;display:flex;flex-direction:column;gap:2px;padding:10px 12px;border:1px solid var(--ctl);border-radius:var(--r);background:#fff;cursor:pointer;transition:border-color var(--dur),background var(--dur),box-shadow var(--dur)}
.ma-o:hover{border-color:var(--ink-3)}
.ma-o input{position:absolute;opacity:0;width:1px;height:1px}
.ma-o.on{border-color:var(--cy);background:var(--cy-50);box-shadow:0 0 0 3px var(--cy-100)}
.ma-o:has(input:focus-visible){outline:2px solid var(--cy);outline-offset:2px}
.ma-o b{font-weight:620;line-height:1.25}
.ma-o small{font-size:var(--t-xs);color:var(--ink-2);line-height:1.4}
.ma-key{display:flex;flex-direction:column;gap:4px;margin-top:6px}
.ma-k-l{font-size:var(--t-sm);font-weight:600;color:var(--ink-2)}
.ma-key code{align-self:flex-start;padding:5px 10px;border-radius:var(--r);background:var(--sheet-2);border:1px solid var(--hair);font:13px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--ink)}
.ma-note{display:flex;gap:8px;align-items:flex-start;margin-top:8px;font-size:var(--t-sm);color:var(--ink-2);line-height:1.45}
.ma-note .ic{margin-top:2px;color:var(--ink-3)}
</style>
