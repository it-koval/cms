<script setup>
// Настройки → «Шаблоны» (read-only): every template of the site with its sentence, whether it is common to all sites or only this one, where it may stand, its own fields and
// how many pages use it (a link to the list of pages filtered by the template). Templates are added by the developers of the site, not in the admin.
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/index.js'
import { app } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { placementText, templateFields, templatesFor } from '@/schema/templates.js'
import { t } from '@/i18n/index.js'

const counts = ref(null) // { [templateKey]: pages }
const failed = ref('')

onMounted(async () => {
  try {
    const rows = await api.pages.list({ locale: app.locale })
    counts.value = rows.reduce((m, p) => ({ ...m, [p.template]: (m[p.template] || 0) + 1 }), {})
  } catch (e) {
    failed.value = e.message || t('settings.templates_count_failed')
    counts.value = {}
  }
})

const all = computed(() => templatesFor()) // the common templates first, then the ones of this site
const fieldsOf = (t) => templateFields(t.key).map((f) => f.label)
const listingOf = (t) => (t.listing ? t.listing.label : '')
</script>

<template>
  <div>
    <p class="tp-note"><Icon name="info" size="s16" /><span>{{ t('settings.templates_note') }}</span></p>
    <div v-if="!counts" class="sheet set-sec" aria-busy="true"><Skeleton :rows="5" /></div>
    <template v-else>
      <section>
        <article v-for="tpl in all" :key="tpl.key" class="sheet set-sec tp-card">
          <h3 class="tp-h">
            <span class="tp-n">{{ tpl.name }}</span>
            <span :class="['tp-b', tpl.core ? 'core' : 'own']">{{ tpl.core ? t('settings.templates_core') : t('settings.templates_own') }}</span>
            <router-link v-if="counts[tpl.key]" class="tp-cnt" :to="{ path: '/pages', query: { template: tpl.key } }" :aria-label="t('settings.templates_pages_aria', { name: tpl.name, pages: counts[tpl.key] })">{{ t('settings.templates_pages', { count: counts[tpl.key] }) }}<Icon name="chevR" size="s14" /></router-link>
            <span v-else class="tp-cnt none">{{ t('settings.templates_no_pages') }}</span>
          </h3>
          <p class="d">{{ tpl.about }}</p>
          <dl class="tp-dl">
            <dt>{{ t('settings.templates_where') }}</dt><dd>{{ placementText(tpl) }}</dd>
            <dt>{{ t('settings.templates_fields') }}</dt>
            <dd>
              <span v-if="fieldsOf(tpl).length" class="tp-fs"><span v-for="f in fieldsOf(tpl)" :key="f" class="tp-f">{{ f }}</span></span>
              <span v-else class="muted">{{ t('settings.templates_no_fields') }}</span>
            </dd>
            <template v-if="listingOf(tpl)"><dt>{{ t('settings.templates_auto') }}</dt><dd>{{ listingOf(tpl) }}<span class="muted"> — {{ t('settings.templates_auto_note') }}</span></dd></template>
            <template v-if="tpl.sections === false"><dt>{{ t('settings.templates_sections') }}</dt><dd>{{ t('settings.templates_no_sections') }}</dd></template>
          </dl>
        </article>
      </section>
      <p v-if="failed" class="tp-note"><Icon name="alert" size="s16" /><span>{{ failed }}</span></p>
    </template>
  </div>
</template>

<style scoped>
.tp-note{display:flex;gap:8px;align-items:flex-start;margin-bottom:16px;font-size:var(--t-sm);line-height:1.5;color:var(--ink-2);max-width:68ch}
.tp-note .ic{margin-top:2px;color:var(--ink-3);flex:none}
.tp-card{padding:16px 22px 14px;margin-bottom:10px}
.tp-h{display:flex;align-items:center;gap:10px;font-size:var(--t-md);font-weight:650;margin-bottom:2px}
.tp-b{font-size:var(--t-xs);font-weight:600;padding:2px 8px;border-radius:10px;white-space:nowrap}
.tp-b.core{background:var(--cy-50);color:var(--cy-dd)}
.tp-b.own{background:var(--hair-2);color:var(--ink-2)}
.tp-cnt{margin-left:auto;display:inline-flex;align-items:center;gap:2px;font-size:var(--t-sm);font-weight:600;color:var(--brand-text);text-decoration:none;white-space:nowrap}
.tp-cnt:hover{color:var(--brand-text-d);text-decoration:underline;text-underline-offset:.2em}
.tp-cnt.none{color:var(--ink-3);font-weight:500}
.tp-dl{display:grid;grid-template-columns:150px 1fr;gap:7px 16px;margin:6px 0 4px;font-size:var(--t-sm);line-height:1.45}
.tp-dl dt{color:var(--ink-2)}
.tp-dl dd{margin:0}
.tp-fs{display:flex;flex-wrap:wrap;gap:5px}
.tp-f{padding:1px 8px;border-radius:5px;border:1px solid var(--hair);background:var(--sheet-2)}
</style>
