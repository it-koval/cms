<script setup>
// «Предпросмотр»: the draft as visitors would see it, full screen, without the editing overlay (the same server render as the editor canvas, PreviewFrame `clean`).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import PreviewFrame from './PreviewFrame.vue'
import { t } from '@/i18n/index.js'
import { isMulti, langName } from '@/utils/locales.js'

const props = defineProps({ page: { type: Object, required: true } })
const multi = computed(() => isMulti())
const emit = defineEmits(['close'])
const dev = ref('desktop')
const closeBtn = ref(null)
const vis = computed(() => props.page.sections.filter((s) => !s.hidden))

let prev = null
const onKey = (e) => {
  if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); emit('close') }
}
onMounted(() => {
  prev = document.activeElement
  document.addEventListener('keydown', onKey, true)
  if (closeBtn.value) closeBtn.value.focus({ preventScroll: true })
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey, true)
  if (prev && document.contains(prev)) prev.focus({ preventScroll: true })
})
</script>

<template>
  <Teleport to="body">
    <div class="fp" role="dialog" aria-modal="true" :aria-label="t('editor.preview_dialog')">
      <div class="fp-bar">
        <b>{{ t('editor.preview_heading', { title: page.title }) }}</b>
        <span v-if="multi && page.locale" class="muted">{{ langName(page.locale) }}</span>
        <span class="muted">{{ t('editor.preview_draft_sections', { count: vis.length }) }}</span>
        <div class="seg2 fp-dev" role="group" :aria-label="t('editor.screen_width')">
          <button type="button" :aria-pressed="dev === 'desktop'" :aria-label="t('editor.device_desktop')" @click="dev = 'desktop'"><Icon name="monitor" size="s16" /></button>
          <button type="button" :aria-pressed="dev === 'phone'" :aria-label="t('editor.device_phone')" @click="dev = 'phone'"><Icon name="phone2" size="s16" /></button>
        </div>
        <button ref="closeBtn" class="btn" type="button" @click="emit('close')"><Icon name="x" size="s16" />{{ t('editor.close') }}</button>
      </div>
      <div class="fp-st">
        <PreviewFrame :page-id="page.id" :locale="page.locale" clean :phone="dev === 'phone'" />
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.fp-dev { margin-left: auto; }
.fp-st :deep(.pv-wrap) { width: 100%; max-width: 1280px; }
.fp-st :deep(.pv-wrap:has(.pv-iframe.phone)) { max-width: 390px; }
</style>
