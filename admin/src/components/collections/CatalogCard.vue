<script setup>
// How a record looks on the site (a small card), live with the form. The core draws the same card for every collection, from what its class names:
// the title field, one more line and a picture. A site can draw its own: window.ITK.registerPlugin({ previews: { <collection key>: Component } }) (props: cfg, data).
// Client content is set in the site's face (Manrope), never in the admin's (Golos Text).
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { PREVIEW, thumbUrl } from '@/utils/media.js'
import { htmlToText } from '@/utils/html.js'
import { pluginPreview } from '@/plugins/state.js'
import { t } from '@/i18n/index.js'

const props = defineProps({ cfg: { type: Object, required: true }, data: { type: Object, required: true } })
const custom = computed(() => pluginPreview(props.cfg.key))
const d = computed(() => props.data)
// a text of the record for the card: HTML fields (descriptions) are shown as their text
const plainOf = (k) => htmlToText(String(d.value[k] ?? '')).replace(/\n+/g, ' ')
</script>

<template>
  <div class="cc">
    <component :is="custom" v-if="custom" :cfg="cfg" :data="data" />
    <div v-else class="cc-box">
      <template v-if="cfg.thumbField">
        <img v-if="d[cfg.thumbField] && d[cfg.thumbField].src" class="cc-img cover" :src="thumbUrl(d[cfg.thumbField].src, PREVIEW)" :alt="d[cfg.thumbField].alt || ''">
        <div v-else class="cc-ph"><Icon name="image" size="s20" />{{ t('collections.cc_no_photo') }}</div>
      </template>
      <div class="cc-b">
        <div class="cc-t">{{ plainOf(cfg.titleField) || t('collections.untitled') }}</div>
        <div v-if="cfg.subField && plainOf(cfg.subField)" class="cc-p">{{ plainOf(cfg.subField) }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cc { font-family: var(--font-site); display: flex; flex-direction: column; gap: 10px; }
.cc-box { border: 1px solid var(--hair); border-radius: 8px; overflow: hidden; background: #fff; color: var(--ink); }
.cc-img { width: 100%; display: block; background: #fff; aspect-ratio: 4/3; }
.cc-img.cover { object-fit: cover; background: var(--hair-2); }
.cc-ph { aspect-ratio: 4/3; background: var(--hair-2); color: var(--ink-3); display: flex; flex-direction: column; gap: 4px; align-items: center; justify-content: center; font-size: var(--t-sm); font-family: var(--font-chrome); }
.cc-b { padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 4px; }
.cc-t { font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
.cc-p { font-size: var(--t-sm); color: var(--ink-2); line-height: 1.4; overflow-wrap: anywhere; }
</style>
