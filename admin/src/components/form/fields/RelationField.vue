<script setup>
// A link to a record of another collection (field.to = its key). One record: a select with an empty choice. Several (field.multi): a list of ticks. The value is the record id (a list of ids).
import { computed, inject } from 'vue'
import FieldShell from '../FieldShell.vue'
import { domId, fieldAria, fieldEmits, fieldProps } from '../fieldProps.js'
import { relationLoaded, relationOptions } from '../relationOptions.js'
import { t } from '@/i18n/index.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const formId = inject('formId', 'f')
const id = computed(() => domId(formId, props.path))
const picked = computed(() => (Array.isArray(props.modelValue) ? props.modelValue.map(String) : []))
const selected = computed(() => props.field.multi ? picked.value : props.modelValue ? [String(props.modelValue)] : [])
const options = computed(() => relationOptions(props.field.to, undefined, selected.value))
const loading = computed(() => !relationLoaded(props.field.to))
const toggle = (v, on) => emit('update:modelValue', on ? [...picked.value, v] : picked.value.filter((x) => x !== v))
</script>

<template>
  <FieldShell :error-id="id + '_e'" :label="field.label" :path="path" :for-id="field.multi ? undefined : id" :help-id="id + '_h'" :help="field.help" :error="error" :changed="changed" :top="top" @revert="emit('revert')">
    <div v-if="field.multi" :id="id" class="rel-m" role="group" v-bind="fieldAria(id, field.help, error)" :aria-label="field.label">
      <p v-if="loading" class="muted">{{ t('forms.loading') }}</p>
      <p v-else-if="!options.length" class="muted">{{ t('forms.relation_empty') }}</p>
      <label v-for="o in options" :key="o[0]" class="rel-o"><input type="checkbox" v-bind="fieldAria(id, field.help, error)" :disabled="field.disabled || undefined" :checked="picked.includes(o[0])" @change="toggle(o[0], $event.target.checked)"><span>{{ o[1] }}</span></label>
    </div>
    <select v-else :id="id" :value="modelValue ?? ''" :disabled="field.disabled || undefined" v-bind="fieldAria(id, field.help, error)" @change="emit('update:modelValue', $event.target.value)">
      <option value="">{{ loading ? t('forms.loading') : t('forms.relation_none') }}</option>
      <option v-for="o in options" :key="o[0]" :value="o[0]" :disabled="o[2]?.unavailable || undefined">{{ o[1] }}</option>
    </select>
  </FieldShell>
</template>

<style scoped>
.rel-m { display: flex; flex-direction: column; gap: 2px; max-height: 240px; overflow-y: auto; padding: 4px; border: 1px solid var(--ctl); border-radius: var(--r); background: #fff; }
.rel-o { display: flex; align-items: center; gap: 9px; min-height: 44px; padding: 0 6px; border-radius: 5px; cursor: pointer; }
.rel-o:hover { background: var(--cy-50); }
.rel-o input { width: 16px; height: 16px; accent-color: var(--cy); flex: none; }
</style>
