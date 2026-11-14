<script setup>
// Draws a component that a plugin of the site put in a slot of the admin («Сегодня» widgets). A plugin that fails while drawing leaves its place empty and says why in the console:
// the screen around it keeps working.   <PluginSlot :component="w.component" :bind="{ locale }" label="widget stats" />
import { onErrorCaptured, ref } from 'vue'
import { t } from '@/i18n/index.js'

const props = defineProps({
  component: { type: [Object, Function], required: true },
  bind: { type: Object, default: () => ({}) }, // props of the plugin component
  label: { type: String, default: 'plugin' }, // what to call it in the console
})
const failed = ref(false)
onErrorCaptured((e) => {
  failed.value = true
  console.error(t('ui.plugin_failed', { label: props.label }), e)
  return false
})
</script>

<template>
  <component :is="component" v-if="!failed" v-bind="bind" />
</template>
