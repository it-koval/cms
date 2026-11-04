<script setup>
// One edit the AI agent suggested: where, what changes (было -> стало) and the decision buttons.
// Accepting puts the new value into the working copy (it still needs a «Опубликовать»); rejecting forgets it.
import { computed, ref } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { htmlToText } from '@/utils/html.js'
import { isMulti } from '@/utils/locales.js'
import LangTag from '@/components/ui/LangTag.vue'
import { t } from '@/i18n/index.js'

const props = defineProps({ proposal: { type: Object, required: true }, busy: { type: Boolean, default: false } })
const emit = defineEmits(['accept', 'reject'])
const open = ref(false)

const flat = (v) => {
  if (v && typeof v === 'object') return String(v.alt || v.url || v.page || v.src || '')
  return htmlToText(String(v ?? '')).replace(/\n+/g, ' / ').trim()
}
const oldText = computed(() => flat(props.proposal.old) || t('ui.proposal_empty'))
const newText = computed(() => flat(props.proposal.nw) || t('ui.proposal_empty'))
const long = computed(() => oldText.value.length > 110 || newText.value.length > 110)
</script>

<template>
  <div class="prop">
    <div class="prop-h">
      <span class="ai-b"><Icon name="sparkle" size="s16" /></span>
      <div class="main-c">
        <router-link class="ttl" :to="proposal.to">{{ proposal.targetTitle }}</router-link><LangTag v-if="isMulti() && proposal.locale" :code="proposal.locale" />
        <div class="meta">{{ t('ui.proposal_field', { name: proposal.label }) }}<template v-if="proposal.note"> · {{ proposal.note }}</template></div>
      </div>
    </div>

    <div v-if="proposal.stale" class="alert warn-a stale"><Icon name="warn" size="s16" /><div>{{ t('ui.proposal_stale') }}</div></div>
    <div v-else class="pdiff">
      <div class="dr old"><span class="tag">{{ t('ui.proposal_was') }}</span><span :class="['txt', { clamp: !open }]">{{ oldText }}</span></div>
      <div class="dr nw"><span class="tag">{{ t('ui.proposal_becomes') }}</span><span :class="['txt', { clamp: !open }]">{{ newText }}</span></div>
      <button v-if="long" type="button" class="more" :aria-expanded="open" @click="open = !open">{{ open ? t('ui.proposal_less') : t('ui.proposal_full') }}</button>
    </div>

    <div class="prop-a">
      <button v-if="!proposal.stale" type="button" :class="['btn primary sm', { loading: busy }]" :disabled="busy" @click="emit('accept', proposal)"><Icon name="check" size="s14" />{{ t('ui.accept') }}</button>
      <button type="button" class="btn sm" :disabled="busy" @click="emit('reject', proposal)">{{ t('ui.reject') }}</button>
      <router-link v-if="!proposal.stale" class="btn ghost sm" :to="proposal.to">{{ t('ui.proposal_context') }}<Icon name="chevR" size="s14" /></router-link>
    </div>
  </div>
</template>

<style scoped>
.prop{padding:14px 16px;border-bottom:1px solid var(--hair-2);display:flex;flex-direction:column;gap:10px}
.prop:last-child{border-bottom:0}
.prop-h{display:flex;gap:12px;align-items:flex-start}
.ai-b{width:28px;height:28px;border-radius:50%;background:var(--cy-50);display:grid;place-items:center;flex:none}
.main-c{flex:1;min-width:0}
.ttl{font-weight:580;line-height:1.3;color:var(--ink);text-decoration:none}
.ttl:hover{color:var(--brand-text);text-decoration:underline}
.meta{font-size:var(--t-sm);color:var(--ink-2);margin-top:2px}
.pdiff{margin-left:40px;display:flex;flex-direction:column;gap:6px}
.dr{display:grid;grid-template-columns:54px minmax(0,1fr);gap:10px;align-items:baseline;padding:7px 10px;border-radius:var(--r);font-size:var(--t-sm);line-height:1.45}
.dr .tag{font-size:var(--t-xs);font-weight:620;color:var(--ink-2)}
.dr.old{background:var(--hair-2)}
.dr.old .txt{color:var(--ink-2);text-decoration:line-through;text-decoration-color:var(--ctl)}
.dr.nw{background:var(--cy-50)}
.dr.nw .tag{color:var(--cy-dd)}
.dr.nw .txt{color:var(--ink)}
.txt.clamp{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.more{align-self:flex-start;background:none;border:0;padding:0;color:var(--brand-text);font-size:var(--t-xs);font-weight:600;text-decoration:underline;text-underline-offset:.2em}
.more:hover{color:var(--brand-text-d)}
.stale{margin-left:40px}
.prop-a{display:flex;gap:8px;align-items:center;margin-left:40px;flex-wrap:wrap}
</style>
