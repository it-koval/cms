<script setup>
// Настройки → Оформление админки: the colours and corners of the panel, for everybody who works in it (the site itself is not touched).
// The person picks the main and the accent colour; the SERVER makes the palette 50–950 in OKLCH and checks that text can be read on the colour (AA); the preview below
// is drawn with the variables the server answers with, so it is exactly what a save applies. Saved at once (like Переадресации), applied to the open page without a reload;
// «Сбросить» goes back to the colours the developer set in the config. The stored value is the setting `adminTheme`; the shell page serves it in window.ITK_ADMIN.theme.
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/index.js'
import { can } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import ThemeColor from './ThemeColor.vue'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { applyTheme } from '@/utils/theme.js'
import { t } from '@/i18n/index.js'

const toast = useToast()
const confirm = useConfirm()
const canEdit = computed(() => can('set:theme', 'edit'))

const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const saved = ref(null) // the answer of the server for the look in force
const view = ref(null) // ... and for the draft (what the preview draws)
const draft = reactive({ brand: '', accent: '', radius: 6 })
const errors = reactive({ brand: '', accent: '', radius: '' })

const look = (o) => ({ brand: o.brand, accent: o.accent, radius: Number(o.radius) })
const dirty = computed(() => !!saved.value && JSON.stringify(look(draft)) !== JSON.stringify(look(saved.value.theme)))
const differsFromDefault = computed(() => !!saved.value && JSON.stringify(look(draft)) !== JSON.stringify(look(saved.value.defaults)))
const readable = computed(() => !view.value || (view.value.checks.brand.ok && view.value.checks.accent.ok))
const canSave = computed(() => canEdit.value && dirty.value && readable.value && !saving.value)

async function load() {
  loading.value = true
  try {
    const p = await api.theme.get()
    saved.value = p
    view.value = p
    Object.assign(draft, look(p.theme))
    loadError.value = ''
  } catch (e) {
    loadError.value = e.message || t('settings.theme_load_failed')
  } finally {
    loading.value = false
  }
}
onMounted(load)

// the preview follows the draft; only the latest answer is drawn
let timer = null
let seq = 0
watch(() => look(draft), (next) => {
  if (!saved.value) return
  clearTimeout(timer)
  timer = setTimeout(async () => {
    const mine = ++seq
    try {
      const p = await api.theme.preview(next)
      if (mine === seq) { view.value = p; errors.brand = errors.accent = errors.radius = '' }
    } catch (e) {
      if (mine === seq) showErrors(e)
    }
  }, 120)
}, { deep: true })
onBeforeUnmount(() => { clearTimeout(timer); seq++ })

function showErrors(e) {
  const d = (e && e.details) || {}
  errors.brand = d.brand || ''
  errors.accent = d.accent || ''
  errors.radius = d.radius || ''
  if (!errors.brand && !errors.accent && !errors.radius) toast.error((e && e.message) || t('settings.theme_apply_failed'))
}

async function save() {
  if (!canSave.value) return
  saving.value = true
  try {
    const p = await api.theme.save(look(draft))
    saved.value = p
    view.value = p
    Object.assign(draft, look(p.theme))
    errors.brand = errors.accent = errors.radius = ''
    applyTheme(p)
    toast.show(t('settings.theme_saved'))
  } catch (e) {
    showErrors(e)
  } finally {
    saving.value = false
  }
}

function undo() { Object.assign(draft, look(saved.value.theme)) }

async function reset() {
  if (!(await confirm({
    title: t('settings.theme_reset_title'),
    message: t('settings.theme_reset_message'),
    confirmLabel: t('settings.theme_reset_confirm'), cancelLabel: t('settings.theme_reset_cancel'),
  }))) return
  saving.value = true
  try {
    const p = await api.theme.reset()
    saved.value = p
    view.value = p
    Object.assign(draft, look(p.theme))
    errors.brand = errors.accent = errors.radius = ''
    applyTheme(p)
    toast.show(t('settings.theme_reset_done'))
  } catch (e) {
    toast.error(e.message || t('settings.theme_reset_failed'))
  } finally {
    saving.value = false
  }
}

const vars = computed(() => (view.value ? view.value.vars : {}))
// sentences with a piece of markup in the middle: the text is cut at the place of the piece
const radiusLabel = t('settings.theme_radius').split('{value}')
const previewText = t('settings.theme_prev_text').split('{link}')
</script>

<template>
  <div class="th">
    <ReadOnlyNote v-if="!loading && !loadError && !canEdit" :text="t('settings.theme_view_only')" />
    <div v-if="loading" class="sheet set-sec"><Skeleton :rows="5" /></div>
    <div v-else-if="loadError" class="alert err-a"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('settings.retry') }}</button></div>
    <template v-else>
      <section class="sheet set-sec" aria-labelledby="th-h">
        <h2 id="th-h">{{ t('settings.nav_theme') }}</h2>
        <p class="d">{{ t('settings.theme_intro') }}</p>
        <div :class="{ 'ro-zone': !canEdit }" :inert="!canEdit || undefined">
          <ThemeColor
            id="th-brand" v-model="draft.brand" :label="t('settings.theme_brand')" :help="t('settings.theme_brand_help')"
            :check="view && view.checks.brand" :ladder="view && view.ladder.brand" :error="errors.brand"
          />
          <ThemeColor
            id="th-accent" v-model="draft.accent" :label="t('settings.theme_accent')" :help="t('settings.theme_accent_help')"
            :check="view && view.checks.accent" :ladder="view && view.ladder.accent" :error="errors.accent"
          />
          <div :class="['field', { 'is-error': errors.radius }]">
            <label for="th-radius">{{ radiusLabel[0] }}<b class="num">{{ draft.radius }} px</b>{{ radiusLabel[1] }}</label>
            <div class="help">{{ t('settings.theme_radius_help') }}</div>
            <input id="th-radius" v-model.number="draft.radius" class="th-range" type="range" min="0" max="16" step="1" :disabled="!canEdit">
            <div v-if="errors.radius" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors.radius }}</span></div>
          </div>
        </div>
      </section>

      <section class="sheet set-sec" aria-labelledby="th-p">
        <h2 id="th-p">{{ t('settings.theme_preview_title') }}</h2>
        <p class="d">{{ t('settings.theme_preview_hint') }}</p>
        <div class="th-prev" :style="vars" aria-hidden="true" inert>
          <div class="th-rail">
            <div class="th-site">{{ t('settings.theme_prev_site') }}</div>
            <div class="th-item on"><Icon name="pages" size="s16" />{{ t('settings.theme_prev_pages') }}</div>
            <div class="th-item"><Icon name="inbox" size="s16" />{{ t('settings.theme_prev_requests') }} <span class="cnt">3</span></div>
            <div class="th-item"><Icon name="image" size="s16" />{{ t('settings.theme_prev_media') }}</div>
          </div>
          <div class="th-body">
            <div class="th-h">{{ t('settings.theme_prev_pages') }} <span class="chip new">{{ t('settings.theme_prev_new') }}</span> <span class="chip draft"><span class="dot" />{{ t('settings.theme_prev_draft') }}</span></div>
            <p>{{ previewText[0] }}<a href="#" tabindex="-1">{{ t('settings.theme_prev_link') }}</a>{{ previewText[1] }}</p>
            <div class="th-row">
              <button type="button" class="btn primary" tabindex="-1">{{ t('settings.theme_prev_publish') }}</button>
              <button type="button" class="btn" tabindex="-1">{{ t('settings.cancel') }}</button>
              <button type="button" class="btn ghost" tabindex="-1">{{ t('settings.theme_prev_more') }}</button>
              <input class="th-in" type="text" :value="t('settings.theme_prev_input')" tabindex="-1" readonly>
            </div>
            <div class="alert info-a th-al"><Icon name="info" size="s16" /><span>{{ t('settings.theme_prev_hint_box') }}</span></div>
          </div>
        </div>
      </section>

      <div v-if="canEdit" class="th-bar">
        <button type="button" :class="['btn', 'primary', { loading: saving }]" :disabled="!canSave" @click="save"><Icon name="save" size="s16" />{{ t('settings.save') }}</button>
        <button v-if="dirty" type="button" class="btn" :disabled="saving" @click="undo">{{ t('settings.theme_undo') }}</button>
        <button v-if="saved.custom || differsFromDefault" type="button" class="btn ghost" :disabled="saving" @click="reset">{{ t('settings.theme_reset') }}</button>
        <span v-if="!readable" class="muted th-warn">{{ t('settings.theme_unreadable_warn') }}</span>
        <span v-else-if="dirty" class="muted">{{ t('settings.theme_unsaved') }}</span>
        <span v-else-if="saved.custom" class="muted">{{ t('settings.theme_custom') }}</span>
      </div>
    </template>
  </div>
</template>

<style scoped>
.th-range{width:100%;max-width:320px;accent-color:var(--cy);height:24px;padding:0}
.th-bar{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:4px 0 24px}
.th-warn{color:var(--red)}
/* the preview: every variable of the theme is set on this one box, so the real classes (.btn, .cnt, .chip, a…) inside it show the draft */
.th-prev{display:grid;grid-template-columns:200px minmax(0,1fr);border:1px solid var(--hair);border-radius:var(--r-lg);overflow:hidden;margin-bottom:12px;background:var(--sheet)}
.th-rail{background:var(--cy);color:var(--rail-ink);padding:14px 10px;display:flex;flex-direction:column;gap:4px}
.th-site{font-weight:650;color:var(--brand-ink);padding:2px 8px 10px;margin-bottom:6px;border-bottom:1px solid color-mix(in srgb,var(--brand-ink) 16%,transparent)}
.th-item{display:flex;align-items:center;gap:8px;height:34px;padding:0 10px;border-radius:var(--r);font-size:var(--t-sm);font-weight:500}
.th-item .cnt{margin-left:auto}
.th-item.on{background:var(--brand-ink);color:var(--cy);font-weight:620}
.th-body{padding:16px 18px;display:flex;flex-direction:column;gap:10px;min-width:0}
.th-h{font-weight:650;font-size:var(--t-md);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.th-body p{margin:0;color:var(--ink-2)}
.th-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.th-in{width:180px}
.th-al{margin:0}
@media (max-width:767px){.th-prev{grid-template-columns:1fr}.th-rail{flex-direction:row;flex-wrap:wrap}.th-site{border:0;margin:0}.th-in{width:100%}}
</style>
