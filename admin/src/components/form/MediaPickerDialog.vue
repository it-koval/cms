<script setup>
// Dialog to choose a photo from the media library (api.media), with search and upload.
//   <MediaPickerDialog v-model:open="o" @pick="({ src, alt, name }) => ..." />
import { ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import Icon from '@/components/ui/Icon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useToast } from '@/components/ui/useToast.js'
import { api } from '@/api/index.js'
import { thumbUrl } from '@/utils/media.js'
import { t } from '@/i18n/index.js'

const props = defineProps({ open: { type: Boolean, default: false } })
const emit = defineEmits(['update:open', 'pick'])
const toast = useToast()
const items = ref([])
const q = ref('')
const loading = ref(false)
const uploading = ref(false)
const file = ref(null)

async function load() {
  loading.value = true
  try { items.value = await api.media.list({ q: q.value, type: 'img' }) } finally { loading.value = false }
}
watch(() => props.open, (v) => { if (v) { q.value = ''; load() } })
let timer = null
watch(q, () => { clearTimeout(timer); timer = setTimeout(load, 150) })

function pick(m) {
  emit('pick', { src: m.src, alt: m.alt || '', name: m.name })
  emit('update:open', false)
}
async function onFile(e) {
  const f = e.target.files && e.target.files[0]
  e.target.value = ''
  if (!f) return
  uploading.value = true
  try {
    const m = await api.media.upload(f)
    toast.show(t('forms.picker_uploaded'))
    pick(m)
  } catch (err) {
    toast.error(err.message || t('forms.upload_failed'))
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <Modal :open="open" :title="t('forms.picker_title')" :width="760" @update:open="emit('update:open', $event)">
    <div class="toolbar" style="padding:0 0 12px;border:0">
      <div class="search">
        <label class="sr" for="mp-q">{{ t('forms.picker_search') }}</label>
        <Icon name="search" size="s16" />
        <input id="mp-q" v-model="q" type="search" :placeholder="t('forms.picker_search_placeholder')" autocomplete="off">
      </div>
      <span class="grow" />
      <input ref="file" type="file" accept="image/*" class="sr" tabindex="-1" @change="onFile">
      <button type="button" class="btn" :class="{ loading: uploading }" @click="file.click()"><Icon name="upload" size="s16" />{{ t('forms.picker_upload') }}</button>
    </div>
    <div v-if="items.length" class="mgrid" style="padding:0;max-height:min(52vh,460px);overflow:auto">
      <button v-for="m in items" :key="m.id" type="button" class="mdi" @click="pick(m)">
        <span class="pic"><img :src="m.thumb || thumbUrl(m.src)" alt=""></span>
        <span class="nm">{{ m.name }}<small class="num">{{ t('forms.size_kb', { size: m.kb }) }}</small></span>
      </button>
    </div>
    <EmptyState v-else-if="!loading" icon="image" :title="t('forms.picker_empty_title')" :text="t('forms.picker_empty_text')" />
    <div v-else class="sk-wrap"><div class="sk" style="height:90px" /></div>
    <template #footer>
      <button type="button" class="btn" @click="emit('update:open', false)">{{ t('forms.cancel') }}</button>
    </template>
  </Modal>
</template>
