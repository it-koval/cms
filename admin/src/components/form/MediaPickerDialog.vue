<script setup>
// Dialog to choose a photo from the media library (api.media), with search and upload.
//   <MediaPickerDialog v-model:open="o" @pick="({ src, alt, name }) => ..." />
import { onBeforeUnmount, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import Icon from '@/components/ui/Icon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ListPager from '@/components/collections/list/ListPager.vue'
import { useListCursor } from '@/composables/useListCursor.js'
import { useToast } from '@/components/ui/useToast.js'
import { api } from '@/api/index.js'
import { thumbUrl } from '@/utils/media.js'
import { t } from '@/i18n/index.js'

const props = defineProps({ open: { type: Boolean, default: false } })
const emit = defineEmits(['update:open', 'pick'])
const toast = useToast()
const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(25)
const { cursors, position, accept, parameters } = useListCursor()
const q = ref('')
const loading = ref(false)
const loadError = ref('')
const uploading = ref(false)
const file = ref(null)

let seq = 0
let timer = null
async function load() {
  const my = ++seq
  loading.value = true
  try {
    const paging = parameters()
    const result = await api.media.list({ q: q.value, type: 'img', page: page.value, perPage: perPage.value, ...paging })
    if (my !== seq) return
    const last = Math.max(1, Math.ceil(result.total / result.perPage))
    if (!result.items.length && (result.page > last || paging.cursor)) return go(Math.min(result.page, last), true)
    accept(result)
    items.value = result.items
    total.value = result.total
    loadError.value = ''
  } catch (e) {
    if (my === seq) loadError.value = e.message || t('forms.media_load_failed')
  } finally {
    if (my === seq) loading.value = false
  }
}
function go(next, reset = false) {
  clearTimeout(timer)
  position(next, reset)
  page.value = next
  return load()
}
function setPerPage(size) {
  perPage.value = size
  go(1, true)
}
watch(q, () => {
  if (!props.open) return
  ++seq
  position(1, true)
  page.value = 1
  loading.value = true
  clearTimeout(timer)
  timer = setTimeout(() => go(1, true), 150)
}, { flush: 'sync' })
function cancelLoad() { ++seq; clearTimeout(timer) }
watch(() => props.open, (open) => {
  if (!open) return cancelLoad()
  q.value = ''
  items.value = []
  total.value = 0
  loadError.value = ''
  go(1, true)
}, { immediate: true })
onBeforeUnmount(cancelLoad)

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
    <div v-if="loading" class="sk-wrap"><div class="sk" style="height:90px" /></div>
    <div v-else-if="loadError" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('forms.retry') }}</button></div>
    <div v-else-if="items.length" class="mgrid" style="padding:0;max-height:min(52vh,460px);overflow:auto">
      <button v-for="m in items" :key="m.id" type="button" class="mdi" @click="pick(m)">
        <span class="pic"><img :src="m.thumb || thumbUrl(m.src)" alt="" loading="lazy"></span>
        <span class="nm">{{ m.name }}<small class="num">{{ t('forms.size_kb', { size: m.kb }) }}</small></span>
      </button>
    </div>
    <EmptyState v-else icon="image" :title="t('forms.picker_empty_title')" :text="t('forms.picker_empty_text')" />
    <ListPager v-if="total && !loadError" :page="page" :per-page="perPage" :sizes="[25, 50, 100]" :total="total" :cursors="cursors" :busy="loading" @update:page="go" @update:per-page="setPerPage" />
    <template #footer>
      <button type="button" class="btn" @click="emit('update:open', false)">{{ t('forms.cancel') }}</button>
    </template>
  </Modal>
</template>
