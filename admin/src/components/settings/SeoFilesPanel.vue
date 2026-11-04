<script setup>
// Настройки → SEO → «robots.txt и llms.txt»: the two text files the site gives to search robots and AI services (area set:seo). Saved at once with a button per file, no publish step.
// A file is a draft in a monospace field: «Вернуть стандартный» puts the standard text into the field (still a draft until «Сохранить»). A file of the same name in public/ of the site beats the text: the panel says so.
// Without the right to edit the fields are read-only (the text can still be selected) and the buttons are gone. The rules (dirty, standard, the body of the save) live in seoFiles.js.
import { computed, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { api } from '@/api/index.js'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { app, can } from '@/stores/app.js'
import { siteOrigin } from '@/utils/format.js'
import { FILES, canResetToDefault, draftsOf, fileName, isDirty, savePatch } from './seoFiles.js'
import { t } from '@/i18n/index.js'

const toast = useToast()
const confirm = useConfirm()
const canEdit = computed(() => can('set:seo', 'edit'))

const loading = ref(true)
const loadError = ref('')
const files = ref(null) // the answer of the server for the saved files
const drafts = reactive({ robots: '', llms: '' })
const errors = reactive({})
const saving = reactive({})
const justSaved = reactive({}) // «Сохранено» next to the button until the field is touched again

const TITLES = { robots: t('integrations.sf_robots_title'), llms: t('integrations.sf_llms_title') }
const INTROS = { robots: t('integrations.sf_robots_intro'), llms: t('integrations.sf_llms_intro') }
const FILE_WARNS = { robots: t('integrations.sf_robots_file'), llms: t('integrations.sf_llms_file') }

function fill(next) {
  files.value = next
  Object.assign(drafts, draftsOf(next))
}

async function load() {
  loading.value = true
  try {
    fill(await api.settings.getSeoFiles())
    loadError.value = ''
  } catch (e) {
    loadError.value = e.message || t('integrations.sf_load_failed')
  } finally {
    loading.value = false
  }
}
onMounted(load)

const dirty = (k) => isDirty(drafts[k], files.value[k])
const showReset = (k) => canEdit.value && canResetToDefault(drafts[k], files.value[k])
// leaving with a text not saved: another segment, another item, another screen
const confirmLeave = () => (files.value && FILES.some((k) => dirty(k)) ? confirm({
  title: t('settings.leave_title'), message: t('integrations.sf_leave_message'),
  confirmLabel: t('settings.leave_confirm'), cancelLabel: t('people.btn_stay'), danger: true,
}) : true)
onBeforeRouteLeave(confirmLeave)
onBeforeRouteUpdate(confirmLeave)
const siteLink = (k) => `${siteOrigin(app.siteInfo.domain)}/${fileName(k)}`

function touch(k) {
  delete errors[k]
  justSaved[k] = false
}
function resetToDefault(k) {
  drafts[k] = files.value[k].default
  touch(k)
}
function undo(k) {
  drafts[k] = files.value[k].text
  touch(k)
}

/** Saves one file (the other draft stays as it is, even if it is changed too). */
async function save(k) {
  if (saving[k] || !dirty(k) || !canEdit.value) return
  delete errors[k]
  saving[k] = true
  try {
    const body = savePatch(drafts, files.value)
    const res = await api.settings.saveSeoFiles({ [k]: body[k] })
    files.value = { ...files.value, [k]: res[k] }
    drafts[k] = res[k].text
    justSaved[k] = true
    toast.show(t('integrations.sf_saved', { name: fileName(k) }))
  } catch (e) {
    const details = e && e.details && typeof e.details === 'object' && !Array.isArray(e.details) ? e.details : {}
    if (details[k]) errors[k] = details[k]
    else toast.error((e && e.message) || t('integrations.sf_save_failed'))
  } finally {
    saving[k] = false
  }
}
</script>

<template>
  <div class="sf">
    <ReadOnlyNote v-if="!loading && !loadError && !canEdit" :text="t('integrations.sf_readonly')" />
    <div v-if="loading" class="sheet set-sec"><Skeleton :rows="6" /></div>
    <div v-else-if="loadError" class="alert err-a"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('integrations.btn_retry') }}</button></div>
    <template v-else>
      <section v-for="k in FILES" :key="k" class="sheet set-sec" :aria-labelledby="`sf-${k}-h`">
        <div class="sf-head">
          <h2 :id="`sf-${k}-h`">{{ TITLES[k] }}</h2>
          <span :class="['chip', { pub: files[k].custom }]">{{ files[k].custom ? t('integrations.sf_custom') : t('integrations.sf_default') }}</span>
          <a class="btn sm ghost sf-open" :href="siteLink(k)" target="_blank" rel="noopener">{{ t('integrations.sf_open') }}<Icon name="external" size="s14" /></a>
        </div>
        <p :id="`sf-${k}-d`" class="d">{{ INTROS[k] }}</p>
        <div v-if="files[k].file" class="alert warn-a" role="status"><Icon name="alert" size="s16" /><span>{{ FILE_WARNS[k] }}</span></div>

        <div :class="['field', 'sf-field', { 'is-error': !!errors[k], 'is-changed': dirty(k) }]">
          <div class="lbl">
            <label :for="`sf-${k}-t`">{{ t('integrations.sf_text_label', { name: fileName(k) }) }}</label>
            <span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('integrations.changed') }}</span>
          </div>
          <textarea
            :id="`sf-${k}-t`" v-model="drafts[k]" class="sf-text" rows="12" spellcheck="false" autocomplete="off" autocapitalize="off" wrap="off"
            :readonly="!canEdit" :aria-invalid="errors[k] ? 'true' : undefined" :aria-describedby="`sf-${k}-d` + (errors[k] ? ` sf-${k}-e` : '')"
            @input="touch(k)"
          />
          <div v-if="errors[k]" :id="`sf-${k}-e`" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors[k] }}</span></div>
        </div>

        <div v-if="canEdit" class="sf-bar">
          <button type="button" :class="['btn', 'primary', { loading: saving[k] }]" :disabled="!dirty(k) || saving[k]" @click="save(k)"><Icon name="save" size="s16" />{{ t('integrations.btn_save') }}</button>
          <button v-if="dirty(k)" type="button" class="btn" :disabled="saving[k]" @click="undo(k)">{{ t('integrations.ml_undo') }}</button>
          <button v-if="showReset(k)" type="button" class="btn" :disabled="saving[k]" @click="resetToDefault(k)">{{ t('integrations.sf_reset') }}</button>
          <span v-if="dirty(k)" class="muted">{{ t('integrations.ml_unsaved') }}</span>
          <span v-else-if="justSaved[k]" class="muted" role="status">{{ t('integrations.sf_saved_state') }}</span>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.sf-head{display:flex;align-items:center;flex-wrap:wrap;gap:6px 10px;margin-bottom:2px}
.sf-head h2{font-size:var(--t-md);font-weight:650;margin:0}
.sf-open{margin-left:auto}
.sf .alert{margin-bottom:8px}
.sf-field{margin-top:6px}
.sf-text{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:var(--t-sm);line-height:1.5;min-height:200px;white-space:pre;overflow:auto}
.sf-bar{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:14px 0 4px}
</style>
