<script setup>
// The modal of an action that asks for something first (Action::modal('Заголовок', ...fields)): the fields are drawn by the same form engine as everywhere (SchemaForm),
// the buttons are «Отмена» and the action's own name. A component given by a plugin ({ modal: Component }) is drawn instead of the fields and brings its own buttons.
//   <ActionDialog :actions="useActions(...)" />
import { computed } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import Icon from '@/components/ui/Icon.vue'
import SchemaForm from '@/components/form/SchemaForm.vue'
import { t } from '@/i18n/index.js'

const props = defineProps({ actions: { type: Object, required: true } })
const d = computed(() => props.actions.dialog)
const fields = computed(() => (d.value.action && d.value.action.modal && d.value.action.modal.fields) || [])
const title = computed(() => (d.value.action && d.value.action.modal && d.value.action.modal.title) || (d.value.action ? d.value.action.label : ''))
const forText = computed(() => {
  const n = d.value.ids.length
  return n ? t('collections.ad_selected', { count: n }) : ''
})
const setOpen = (v) => { if (!v) props.actions.close() }
</script>

<template>
  <Modal :open="d.open" :title="title" :width="520" :close-on-scrim="!d.busy" @update:open="setOpen">
    <component :is="d.component" v-if="d.component" :resource="actions.resource()" :action="d.action" :ids="d.ids" :close="actions.close" :run="actions.runFromDialog" />
    <form v-else class="ad" novalidate @submit.prevent="actions.submit()">
      <p v-if="forText" class="muted ad-for">{{ forText }}</p>
      <p v-if="d.action && d.action.confirm" class="ad-conf">{{ d.action.confirm }}</p>
      <SchemaForm plain-required :schema="fields" :model-value="d.input" :quiet="d.quiet" id-prefix="act" @update:model-value="d.input = $event" />
      <div v-if="d.error" class="err ad-err" role="alert"><Icon name="alert" size="s16" /><span>{{ d.error }}</span></div>
      <button type="submit" class="sr" tabindex="-1">{{ t('collections.ad_run') }}</button>
    </form>
    <template v-if="!d.component" #footer>
      <button type="button" class="btn" :disabled="d.busy" @click="actions.close()">{{ t('collections.ad_cancel') }}</button>
      <button type="button" :class="['btn', d.action && d.action.danger ? 'danger' : 'primary', { loading: d.busy }]" @click="actions.submit()">{{ d.action ? d.action.label : t('collections.ad_run') }}</button>
    </template>
  </Modal>
</template>

<style scoped>
.ad { display: flex; flex-direction: column; gap: 6px; }
.ad-for { font-size: var(--t-sm); }
.ad-conf { font-size: var(--t-sm); background: var(--amber-50); color: #5C3A00; padding: 8px 10px; border-radius: var(--r); }
.ad-err { margin-top: 4px; }
.ad :deep(.field) { margin: 0 -12px; }
</style>
