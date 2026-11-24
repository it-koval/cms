<script setup>
// Photo: value { src, alt, crop? }. «Заменить» opens the media picker; crop ratio is a select (as shown: «Как есть», 1:1, 4:3, 16:9, 3:4).
import { computed, inject, ref } from 'vue'
import { PREVIEW, thumbUrl } from '@/utils/media.js'
import FieldShell from '../FieldShell.vue'
import MediaPickerDialog from '../MediaPickerDialog.vue'
import Icon from '@/components/ui/Icon.vue'
import { domId, fieldAria, fieldEmits, fieldProps } from '../fieldProps.js'
import { t } from '@/i18n/index.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const formId = inject('formId', 'f')
const id = computed(() => domId(formId, props.path))
const val = computed(() => props.modelValue || { src: '', alt: '' })
const picking = ref(false)
const CROPS = ['', '1:1', '4:3', '16:9', '3:4']

function set(patch) {
  const next = { ...val.value, ...patch }
  if (!next.crop) delete next.crop
  emit('update:modelValue', next)
}
function onPick(m) {
  const next = { src: m.src, alt: val.value.alt || m.alt }
  emit('update:modelValue', next) // a new photo drops the old crop
}
</script>

<template>
  <FieldShell :error-id="id + '_e'" :label="field.label" :path="path" :label-id="id" :help-id="id + '_h'" :help="field.help" :error="error" :changed="changed" :top="top" @revert="emit('revert')">
    <div class="imgf">
      <div class="ph">
        <img v-if="val.src" :src="thumbUrl(val.src, PREVIEW)" alt="" :style="val.crop ? { aspectRatio: val.crop.replace(':', '/') } : undefined">
        <span v-else style="display:grid;place-items:center;height:100%;color:var(--ink-3);font-size:12px">{{ t('forms.image_none') }}</span>
      </div>
      <div class="meta">
        <label class="s" :for="id + '_alt'">{{ t('forms.alt_label') }}</label>
        <input v-bind="fieldAria(id, field.help, error)" :id="id + '_alt'" type="text" :value="val.alt" :placeholder="t('forms.alt_placeholder')" autocomplete="off" @input="set({ alt: $event.target.value })">
        <div class="btns">
          <button type="button" class="btn sm" data-focus-target v-bind="fieldAria(id, field.help, error)" @click="picking = true"><Icon name="swap" size="s14" />{{ t('forms.image_replace') }}</button>
          <label class="sr" :for="id + '_crop'">{{ t('forms.image_frame') }}</label>
          <select v-bind="fieldAria(id, field.help, error)" :id="id + '_crop'" class="if-crop" :value="val.crop || ''" :disabled="!val.src" @change="set({ crop: $event.target.value })">
            <option v-for="c in CROPS" :key="c" :value="c">{{ c ? t('forms.image_crop', { ratio: c }) : t('forms.image_crop_none') }}</option>
          </select>
        </div>
      </div>
    </div>
    <MediaPickerDialog v-model:open="picking" @pick="onPick" />
  </FieldShell>
</template>

<style scoped>
.if-crop{width:auto;height:28px;font-size:var(--t-sm)}
@media (max-width:767px){.if-crop{height:44px;font-size:16px}}
</style>
