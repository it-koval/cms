<script setup>
// A calm line above a screen the role may look at but not change: <ReadOnlyNote text="…" /> (default text from `what`: «Только просмотр: у вашей роли нет права править страницы…»).
// The editable area is wrapped in <div :class="{ 'ro-zone': ro }" :inert="ro">: inert = nothing can be clicked or typed, .ro-zone hides the action buttons.
import Icon from './Icon.vue'
import { t } from '@/i18n/index.js'
import './readOnly.css'

defineProps({ what: { type: String, default: '' }, text: { type: String, default: '' } })
</script>

<template>
  <div class="alert ro-note" role="note">
    <Icon name="eye" size="s16" />
    <span>{{ text || t('ui.readonly_note', { what }) }}</span>
  </div>
</template>
