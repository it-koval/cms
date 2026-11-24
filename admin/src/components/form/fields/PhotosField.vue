<script setup>
// Existing URL strings stay unchanged until their alt is edited; object metadata travels with the photo.
import { computed, inject, ref } from 'vue'
import { thumbUrl } from '@/utils/media.js'
import FieldShell from '../FieldShell.vue'
import MediaPickerDialog from '../MediaPickerDialog.vue'
import Icon from '@/components/ui/Icon.vue'
import { domId, fieldAria, fieldEmits, fieldProps } from '../fieldProps.js'
import { t } from '@/i18n/index.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const formId = inject('formId', 'f')
const id = computed(() => domId(formId, props.path))
const list = computed(() => props.modelValue || [])
const picking = ref(false)
const srcOf = (p) => (p && typeof p === 'object' ? p.src : p) || ''
const add = (m) => emit('update:modelValue', [...list.value, { src: m.src, alt: m.alt || '' }])
const del = (i) => emit('update:modelValue', list.value.filter((_, k) => k !== i))
const altOf = (p) => (p && typeof p === 'object' ? p.alt : '') || ''
function setAlt(i, alt) {
  emit('update:modelValue', list.value.map((photo, k) => k === i ? { ...(photo && typeof photo === 'object' ? photo : { src: photo }), alt } : photo))
}
function move(i, step) {
  const next = [...list.value]
  const to = i + step
  if (to < 0 || to >= next.length) return
  ;[next[i], next[to]] = [next[to], next[i]]
  emit('update:modelValue', next)
}
</script>

<template>
  <FieldShell :label="field.label" :path="path" :label-id="id" :help-id="id + '_h'" :error-id="id + '_e'" :help="field.help" :error="error" :changed="changed" :top="top" @revert="emit('revert')">
    <div class="photos-list">
      <div v-for="(s, i) in list" :key="srcOf(s) + i" class="photo-row">
        <img :src="thumbUrl(srcOf(s))" :alt="altOf(s)">
        <div class="photo-meta">
          <label :for="id + '_alt_' + i">{{ t('forms.alt_label') }} · {{ i + 1 }}</label>
          <input :id="id + '_alt_' + i" type="text" :value="altOf(s)" :placeholder="t('forms.alt_placeholder')" autocomplete="off" @input="setAlt(i, $event.target.value)">
          <div class="photo-actions">
            <button type="button" class="btn" :disabled="i === 0" :aria-label="t('forms.repeater_up')" @click="move(i, -1)"><Icon name="up" size="s14" /></button>
            <button type="button" class="btn" data-photo-down :disabled="i === list.length - 1" :aria-label="t('forms.repeater_down')" @click="move(i, 1)"><Icon name="down" size="s14" /></button>
            <button type="button" class="btn" :aria-label="t('forms.photos_remove')" @click="del(i)"><Icon name="x" size="s14" /></button>
          </div>
        </div>
      </div>
      <button v-bind="fieldAria(id, field.help, error)" type="button" class="btn" data-focus-target @click="picking = true"><Icon name="plus" />{{ t('forms.photos_add') }}</button>
    </div>
    <MediaPickerDialog v-model:open="picking" @pick="add" />
  </FieldShell>
</template>

<style scoped>
.photos-list{display:grid;gap:12px;min-width:0}
.photo-row{display:grid;grid-template-columns:56px minmax(0,1fr);gap:8px;align-items:start}
.photo-row img{width:56px;height:56px;object-fit:cover;border-radius:4px}
.photo-meta{display:grid;gap:4px;min-width:0}
.photo-meta label{font-size:var(--t-sm);color:var(--ink-2);overflow-wrap:anywhere}
.photo-meta input{width:100%;min-width:0;min-height:44px}
.photo-actions{display:flex;gap:4px}
.photos-list .btn{min-height:44px;min-width:44px;justify-self:start}
</style>
