<script setup>
// A strip of photos: value = array of items, each an image URL (a string, as this field adds it) or { src, alt } (as MCP writes it); other items are kept as they are.
// Add from the media library (a string is added), remove with ×.
import { computed, inject, ref } from 'vue'
import { thumbUrl } from '@/utils/media.js'
import FieldShell from '../FieldShell.vue'
import MediaPickerDialog from '../MediaPickerDialog.vue'
import Icon from '@/components/ui/Icon.vue'
import { domId, fieldEmits, fieldProps } from '../fieldProps.js'
import { t } from '@/i18n/index.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const formId = inject('formId', 'f')
const id = computed(() => domId(formId, props.path))
const list = computed(() => props.modelValue || [])
const picking = ref(false)
const srcOf = (p) => (p && typeof p === 'object' ? p.src : p) || ''
const add = (m) => emit('update:modelValue', [...list.value, m.src])
const del = (i) => emit('update:modelValue', list.value.filter((_, k) => k !== i))
</script>

<template>
  <FieldShell :label="field.label" :path="path" :label-id="id" :help-id="id + '_h'" :help="field.help" :error="error" :changed="changed" :top="top" @revert="emit('revert')">
    <div class="photo-strip">
      <span v-for="(s, i) in list" :key="srcOf(s) + i" class="ps">
        <img :src="thumbUrl(srcOf(s))" alt="">
        <button type="button" :aria-label="t('forms.photos_remove')" @click="del(i)"><Icon name="x" size="s14" /></button>
      </span>
      <button type="button" class="addp" :aria-label="t('forms.photos_add')" data-focus-target @click="picking = true"><Icon name="plus" /></button>
    </div>
    <MediaPickerDialog v-model:open="picking" @pick="add" />
  </FieldShell>
</template>
