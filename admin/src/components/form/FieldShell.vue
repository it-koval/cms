<script setup>
// Label + length budget + magenta change mark + help + error around a control. Used by every field.
//   forId     — id of the control the <label> points to; omit for composite controls (then labelId is used by aria-labelledby)
//   budget    — { n, max } to show «n / max»
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { budgetState } from '@/utils/fields.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  label: { type: String, default: '' },
  path: { type: String, default: '' },
  forId: { type: String, default: '' },
  labelId: { type: String, default: '' },
  helpId: { type: String, default: '' },
  help: { type: String, default: '' },
  error: { type: String, default: '' },
  changed: { type: Boolean, default: false },
  top: { type: Boolean, default: false },
  budget: { type: Object, default: null },
  count: { type: Number, default: -1 }, // repeaters: number of items instead of «n / max»
  required: { type: Boolean, default: false },
  hideLabel: { type: Boolean, default: false },
})
defineEmits(['revert'])
const state = computed(() => (props.budget ? budgetState(props.budget.n, props.budget.max) : ''))
</script>

<template>
  <div :class="['field', { 'is-changed': changed, 'is-error': !!error }]" :data-field-path="path" :data-lbl="label">
    <div class="lbl">
      <slot name="label">
        <label v-if="forId" :for="forId">{{ label }}</label>
        <span v-else :id="labelId || undefined">{{ label }}</span>
      </slot>
      <span v-if="top" class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('forms.changed') }}<button type="button" class="revert" @click="$emit('revert')"><Icon name="undo" size="s14" />{{ t('forms.revert') }}</button></span>
      <span v-if="budget" :class="['budget', state]" aria-live="off">{{ budget.n }} / {{ budget.max }}</span>
      <span v-else-if="count >= 0" class="budget">{{ count }}</span>
    </div>
    <slot />
    <div v-if="help" :id="helpId || undefined" class="help">{{ help }}</div>
    <div v-if="error" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ error }}</span></div>
  </div>
</template>
