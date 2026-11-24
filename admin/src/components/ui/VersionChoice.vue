<script setup>
// The state of a page / record that has no version in the content language: what it means for the site and the ways to start. ONE look on every screen
// (the page editor, a collection record, the options matrix); the caller writes the words.
//   <VersionChoice title="Английской версии этой страницы ещё нет" sub="На сайте её не видно на английском." :options="[{ id:'copy', icon:'copy', label, hint, lead:true }, { id:'blank', icon:'doc', label, hint }]"
//                  note="Черновик не виден на сайте, пока вы не нажмёте «Опубликовать»." :busy="'copy'|'blank'|''" @pick="id => ..." />
// Default slot: anything below the note (a link back, a button to the collection). Slot `before`: between the sub line and the options.
import Icon from './Icon.vue'

defineProps({
  title: { type: String, required: true },
  sub: { type: String, default: '' },
  note: { type: String, default: '' },
  options: { type: Array, default: () => [] }, // [{ id, label, hint, icon, lead }]
  busy: { type: String, default: '' }, // id of the option being carried out (all options are disabled while set)
  icon: { type: String, default: 'globe' },
})
const emit = defineEmits(['pick'])
</script>

<template>
  <div class="vc" role="region" :aria-label="title">
    <div class="vc-ill"><Icon :name="icon" size="s20" /></div>
    <h2 class="vc-t">{{ title }}</h2>
    <p v-if="sub" class="vc-s">{{ sub }}</p>
    <slot name="before" />
    <div v-if="options.length" :class="['vc-opts', { one: options.length === 1 }]">
      <button v-for="o in options" :key="o.id" type="button" :class="['vc-o', { lead: o.lead }]" :disabled="!!busy" :aria-busy="busy === o.id" @click="emit('pick', o.id)">
        <span class="ico"><Icon :name="o.icon" size="s20" /></span>
        <span class="tx">
          <b>{{ o.label }}</b>
          <small>{{ o.hint }}</small>
        </span>
      </button>
    </div>
    <p v-if="note" class="vc-n"><Icon name="info" size="s16" />{{ note }}</p>
    <slot />
  </div>
</template>

<style scoped>
.vc { width: 100%; max-width: 640px; min-width: 0; box-sizing: border-box; margin-inline: auto; padding: 16px 12px 14px; text-align: center; display: flex; flex-direction: column; align-items: center; overflow-wrap: anywhere; }
.vc-ill { width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; background: var(--cy-50); color: var(--brand-text); margin-bottom: 12px; }
.vc-t { font-size: var(--t-md); font-weight: 650; line-height: 1.25; margin: 0; }
.vc-s { margin: 6px 0 0; color: var(--ink-2); max-width: 52ch; line-height: 1.45; }
.vc-opts { display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px; width: 100%; min-width: 0; margin-top: 12px; text-align: left; }
.vc-opts.one { grid-template-columns: minmax(0, 1fr); max-width: 320px; }
.vc-o {
  display: flex; gap: 10px; align-items: flex-start; min-width: 0; min-height: 44px; padding: 10px; border: 1px solid var(--ctl); border-radius: var(--r); background: #fff;
  text-align: left; color: var(--ink); transition: border-color var(--dur), background var(--dur), box-shadow var(--dur);
}
.vc-o:hover:not([disabled]) { border-color: var(--cy); background: var(--cy-50); }
.vc-o:focus-visible { outline: 2px solid var(--cy); outline-offset: 2px; }
.vc-o[disabled] { opacity: .6; cursor: progress; }
.vc-o[aria-busy="true"] { opacity: 1; border-color: var(--cy); }
.vc-o.lead { border-color: var(--cy); box-shadow: 0 0 0 3px var(--cy-100); }
.vc-o .ico { width: 32px; height: 32px; border-radius: var(--r); display: grid; place-items: center; background: var(--hair-2); color: var(--ink-2); flex: none; }
.vc-o.lead .ico { background: var(--cy); color: var(--brand-ink); }
.vc-o .tx { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.vc-o b { font-weight: 620; line-height: 1.25; }
.vc-o small { font-size: var(--t-xs); color: var(--ink-2); line-height: 1.4; overflow-wrap: anywhere; }
.vc-n { display: flex; gap: 8px; align-items: flex-start; margin: 16px 0 0; font-size: var(--t-sm); color: var(--ink-2); line-height: 1.45; text-align: left; }
.vc-n .ic { margin-top: 2px; color: var(--ink-3); flex: none; }
@media (min-width: 768px) {
  .vc { padding: 30px 32px 22px; }
  .vc-opts { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 20px; }
  .vc-opts.one { grid-template-columns: minmax(0, 1fr); }
  .vc-o { padding: 14px; }
}
</style>
