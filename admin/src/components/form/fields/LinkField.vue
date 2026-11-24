<script setup>
// Link: value { kind: 'page' | 'url' | 'modal', page: '/path', url: 'https://...' }
import { computed, inject } from 'vue'
import FieldShell from '../FieldShell.vue'
import { domId, fieldAria, fieldEmits, fieldProps } from '../fieldProps.js'
import { usePageOptions } from '../usePageOptions.js'
import { homeUrl } from '@/utils/locales.js'
import { t } from '@/i18n/index.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const formId = inject('formId', 'f')
const id = computed(() => domId(formId, props.path))
const pages = usePageOptions()
const val = computed(() => props.modelValue || { kind: 'page', page: homeUrl(), url: '' })
const set = (patch) => emit('update:modelValue', { ...val.value, ...patch })
// a stored page address that is not in the list (deleted page) stays selectable
const opts = computed(() => (pages.value.some((p) => p.value === val.value.page) || !val.value.page ? pages.value : [...pages.value, { value: val.value.page, label: val.value.page }]))
</script>

<template>
  <FieldShell :error-id="id + '_e'" :label="field.label" :path="path" :label-id="id" :help-id="id + '_h'" :help="field.help" :error="error" :changed="changed" :top="top" @revert="emit('revert')">
    <div class="linkf">
      <div class="row">
        <select v-bind="fieldAria(id, field.help, error)" :aria-label="t('forms.link_type')" :value="val.kind" @change="set({ kind: $event.target.value })">
          <option value="page">{{ t('forms.link_kind_page') }}</option>
          <option value="url">{{ t('forms.link_kind_url') }}</option>
          <option value="modal">{{ t('forms.link_kind_modal') }}</option>
        </select>
        <select v-bind="fieldAria(id, field.help, error)" v-if="val.kind === 'page'" :aria-label="t('forms.link_page')" :value="val.page" @change="set({ page: $event.target.value })">
          <option v-for="p in opts" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
        <input v-bind="fieldAria(id, field.help, error)" v-else-if="val.kind === 'url'" type="url" placeholder="https://" :aria-label="t('forms.link_address')" style="flex:1" :value="val.url" @input="set({ url: $event.target.value })">
        <span v-else class="muted" style="font-size:var(--t-sm)">{{ t('forms.link_modal_note') }}</span>
      </div>
    </div>
  </FieldShell>
</template>
