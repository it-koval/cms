<script setup>
// Details of one media file: preview, size, alt text (photos), folder, focal point and frames (photos), «Где используется», download, delete (with a warning when used).
import { computed, ref, toRef, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { useMediaAlt } from './useMediaAlt.js'
import Drawer from '@/components/ui/Drawer.vue'
import Icon from '@/components/ui/Icon.vue'
import { PREVIEW, thumbUrl } from '@/utils/media.js'
import { api } from '@/api/index.js'
import { can } from '@/stores/app.js'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { fileSize } from './fileSize.js'
import ImageEditorDialog from './ImageEditorDialog.vue'
import { t } from '@/i18n/index.js'

const props = defineProps({
  item: { type: Object, default: null },
  usage: { type: Array, default: null }, // null until the scan succeeds
  usageError: { type: Boolean, default: false },
  folders: { type: Array, default: () => [] }, // [{ id, parentId, name }] of the media library
  ratios: { type: Array, default: () => ['16:9', '4:3', '1:1', '3:4'] },
})
const emit = defineEmits(['close', 'changed', 'deleted', 'retry-usage'])
const toast = useToast()
const confirm = useConfirm()

const canEdit = computed(() => can('media', 'edit'))
const canDelete = computed(() => can('media', 'delete'))
const deleting = ref(false)
const open = computed(() => !!props.item)
const isPdf = computed(() => props.item && props.item.type === 'pdf')
const { alt, altDirty, saving, saveAlt } = useMediaAlt(toRef(props, 'item'), canEdit, async (id, value) => {
  try {
    emit('changed', await api.media.update(id, { alt: value }))
    toast.show(t('forms.drawer_alt_saved'))
    return true
  } catch (e) {
    toast.error(e.message || t('forms.drawer_alt_failed'))
    return false
  }
})
const downloadHref = computed(() => (props.item && props.item.src) || '')
const used = computed(() => props.usage || [])
const editor = ref(false)
const folderId = ref('')
const framesText = computed(() => {
  const it = props.item
  if (!it || isPdf.value) return ''
  const parts = Object.keys(it.crops || {})
  if (it.focal) parts.unshift(t('forms.drawer_focal'))
  return parts.length ? parts.join(', ') : t('forms.drawer_frames_none')
})
/** Folders in tree order with the depth shown by dashes. */
const folderOptions = computed(() => {
  const kids = new Map()
  for (const f of props.folders) {
    const k = f.parentId || ''
    if (!kids.has(k)) kids.set(k, [])
    kids.get(k).push(f)
  }
  const out = []
  const walk = (parent, depth) => {
    for (const f of kids.get(parent) || []) {
      out.push({ id: f.id, label: '— '.repeat(depth) + f.name })
      walk(f.id, depth + 1)
    }
  }
  walk('', 0)
  return out
})

watch(() => props.item && props.item.id, () => { editor.value = false })
watch(() => props.item && props.item.folderId, (v) => { folderId.value = v || '' }, { immediate: true })

async function saveFolder() {
  if (!props.item || (props.item.folderId || '') === folderId.value) return
  try {
    emit('changed', await api.media.update(props.item.id, { folderId: folderId.value || null }))
    toast.show(folderId.value ? t('forms.drawer_moved') : t('forms.drawer_unfiled'))
  } catch (e) {
    folderId.value = props.item.folderId || ''
    toast.error(e.message || t('forms.drawer_move_failed'))
  }
}

async function close() {
  if (!props.item) return true
  const id = props.item.id
  if (!await saveAlt() || props.item?.id !== id) return false
  emit('close')
  return true
}
// Vue Router awaits the same decision for a usage link and Back. Modified links keep their native behaviour.
onBeforeRouteLeave(close)

async function remove() {
  const m = props.item
  if (!m || deleting.value || props.usage === null || props.usageError) return
  const n = used.value.length
  const places = used.value.slice(0, 3).map((u) => u.label).join('; ') + (n > 3 ? `; ${t('forms.drawer_more_places', { count: n - 3 })}` : '')
  const ok = await confirm({
    title: n ? t('forms.drawer_delete_used_title') : t('forms.drawer_delete_title'),
    message: n
      ? t('forms.drawer_delete_used', { name: m.name, count: n, places })
      : t('forms.drawer_delete_unused', { name: m.name }),
    confirmLabel: n ? t('forms.drawer_delete_anyway') : t('forms.delete'),
    danger: true,
  })
  if (!ok || props.item?.id !== m.id) return
  deleting.value = true
  try {
    await api.media.remove(m.id)
    toast.show(t('forms.drawer_deleted', { name: m.name }))
    emit('deleted', m.id)
  } catch (e) {
    toast.error(e.message || t('forms.drawer_delete_failed'))
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <Drawer :open="open" :title="isPdf ? t('forms.drawer_document') : t('forms.photo')" :width="460" @update:open="(v) => !v && close()">
    <template v-if="item">
      <div class="md-pre">
        <span v-if="isPdf" class="md-pdf"><Icon name="doc" size="s20" />PDF</span>
        <img v-else :src="thumbUrl(item.src, PREVIEW)" :alt="item.alt || item.name">
      </div>

      <div>
        <div class="md-name">{{ item.name }}</div>
        <div class="muted num md-meta"><template v-if="!isPdf && item.w">{{ item.w }} × {{ item.h }} · </template>{{ fileSize(item.kb) }}</div>
      </div>

      <div v-if="!isPdf" class="field md-alt">
        <div class="lbl"><label for="md-alt">{{ t('forms.alt_label') }}</label></div>
        <div class="row">
          <input id="md-alt" v-model="alt" type="text" maxlength="140" :readonly="!canEdit" :placeholder="canEdit ? t('forms.alt_placeholder') : t('forms.drawer_alt_none')" autocomplete="off" aria-describedby="md-alt-h" @keydown.enter.prevent="saveAlt">
          <button v-if="canEdit" type="button" class="btn" :disabled="!altDirty || saving" @click="saveAlt"><Icon name="save" size="s16" />{{ t('forms.save') }}</button>
        </div>
        <div id="md-alt-h" class="help">{{ t('forms.drawer_alt_hint') }}</div>
      </div>

      <div v-if="folderOptions.length || item.folderId" class="field md-alt">
        <div class="lbl"><label for="md-folder">{{ t('forms.folder') }}</label></div>
        <select id="md-folder" v-model="folderId" :disabled="!canEdit" @change="saveFolder">
          <option value="">{{ t('forms.no_folder') }}</option>
          <option v-for="f in folderOptions" :key="f.id" :value="f.id">{{ f.label }}</option>
        </select>
      </div>

      <div v-if="!isPdf" class="md-frames">
        <div class="md-h">{{ t('forms.drawer_framing') }}</div>
        <div class="muted md-hint">{{ framesText }}</div>
        <button v-if="canEdit" type="button" class="btn sm md-edit" @click="editor = true"><Icon name="crop" size="s14" />{{ t('forms.drawer_frame_and_focal') }}</button>
      </div>

      <div class="md-used">
        <div class="md-h">{{ t('forms.drawer_used') }}</div>
        <div v-if="usageError" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ t('forms.drawer_usage_failed') }}</span><button type="button" class="btn sm" @click="emit('retry-usage')">{{ t('forms.retry') }}</button></div>
        <div v-else-if="usage === null" class="muted md-hint" role="status">{{ t('forms.drawer_checking') }}</div>
        <template v-else-if="used.length">
          <router-link v-for="u in used" :key="u.label" :to="u.to"><Icon name="link" size="s14" />{{ u.label }}</router-link>
        </template>
        <span v-else class="muted md-hint">{{ t('forms.drawer_unused') }}</span>
      </div>
      <div v-if="used.length" class="alert warn-a"><Icon name="alert" size="s16" /><span>{{ t('forms.drawer_used_warning') }}</span></div>
    </template>
    <template #footer>
      <button v-if="canDelete" type="button" class="btn danger md-del" :disabled="deleting || !item || usage === null || usageError" @click="remove"><Icon name="trash" size="s14" />{{ t('forms.delete') }}</button>
      <a v-if="downloadHref" class="btn" :href="downloadHref" :download="item && item.name"><Icon name="upload" size="s14" style="transform:rotate(180deg)" />{{ t('forms.drawer_download') }}</a>
      <button type="button" class="btn" @click="close">{{ t('forms.close') }}</button>
    </template>
  </Drawer>
  <ImageEditorDialog v-model:open="editor" :item="item" :ratios="ratios" @saved="emit('changed', $event)" />
</template>

<style scoped>
.md-pre{border-radius:var(--r);overflow:hidden;background:var(--hair-2);aspect-ratio:4/3;display:grid;place-items:center;border:1px solid var(--hair);flex:none}
.md-pre img{width:100%;height:100%;object-fit:contain;display:block}
.md-pdf{display:flex;flex-direction:column;align-items:center;gap:6px;color:var(--red);font-weight:650}
.md-name{font-weight:620;overflow-wrap:anywhere;word-break:normal}
.md-meta{font-size:var(--t-sm)}
.md-alt{margin:0 -12px}
@media (max-width:767px){.md-alt{margin:0}.md-alt .row{flex-wrap:wrap}.md-alt .row input{flex:1 1 100%;min-width:0}.md-alt .row .btn{flex:1 1 auto}}
.md-used{display:flex;flex-direction:column;gap:6px}
.md-used a{display:flex;align-items:center;gap:6px;font-size:var(--t-sm)}
.md-h{font-weight:620}
.md-hint{font-size:var(--t-sm)}
.md-del{margin-right:auto}
.md-frames{display:flex;flex-direction:column;gap:6px;align-items:flex-start}
.md-edit{margin-top:2px}
</style>
