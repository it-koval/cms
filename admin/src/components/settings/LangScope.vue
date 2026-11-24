<script setup>
// The line above a settings tab that says which language the tab is about (only on a site with 2+ languages; a one-language site never shows it).
//   mode 'shared' — nothing here depends on the language («Одно для всех языков»);
//   mode 'lang'   — the texts / menus of the content language app.locale; the select is the same switch as the one in the rail: the Settings screen
//                   asks before it drops unsaved edits (useLocaleGuard in SettingsView), because the switch re-mounts the screen (see App.vue).
import { computed } from 'vue'
import { app, switchContentLocale } from '@/stores/app.js'
import { tag } from '@/utils/locales.js'
import Icon from '@/components/ui/Icon.vue'
import LanguageSelect from '@/components/ui/LanguageSelect.vue'
import { t } from '@/i18n/index.js'
import { SHARED_LABEL } from './langText.js'

const props = defineProps({
  mode: { type: String, default: 'shared' },
  title: { type: String, default: '' },
  text: { type: String, default: '' },
})
const heading = computed(() => props.title || SHARED_LABEL)
</script>

<template>
  <div :class="['sc', mode]">
    <span class="sc-ic" aria-hidden="true"><template v-if="mode === 'lang'">{{ tag(app.locale) }}</template><Icon v-else name="globe" size="s16" /></span>
    <div class="sc-t">
      <b>{{ heading }}</b>
      <span v-if="text">{{ text }}</span>
    </div>
    <LanguageSelect v-if="mode === 'lang'" class="sc-sw" :model-value="app.locale" :languages="app.languages.list" :label="t('settings.content_language')" @update:model-value="switchContentLocale" />
  </div>
</template>

<style scoped>
.sc{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:8px 12px;border-radius:var(--r-lg);margin-bottom:12px;background:var(--hair-2);color:var(--ink-2)}
.sc.lang{background:var(--cy-50);color:var(--cy-dd)}
.sc-ic{flex:none;display:grid;place-items:center;min-width:32px;height:32px;border-radius:var(--r);background:#fff;color:var(--ink-3);font-size:var(--t-xs);font-weight:650;letter-spacing:.02em;box-shadow:var(--sh-1)}
.sc.lang .sc-ic{display:none;color:var(--brand-text)}
.sc-t{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px;font-size:var(--t-sm);line-height:1.4}
.sc-t b{font-weight:620;color:var(--ink)}
.sc.lang .sc-t b{color:var(--cy-dd)}
.sc-sw{flex:none;max-width:100%}
@media (min-width:768px){
  .sc{flex-wrap:nowrap;gap:12px;padding:10px 14px;margin-bottom:16px}
  .sc.lang .sc-ic{display:grid}
}
</style>
