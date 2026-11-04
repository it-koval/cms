<script setup>
// A note, not data: explains where a thing is edited. field = { label, text, link: '/route', linkText }
import Icon from '@/components/ui/Icon.vue'
defineOptions({ inheritAttrs: false })
defineProps({ field: { type: Object, required: true }, path: { type: String, default: '' } })
</script>

<template>
  <div class="field" :data-field-path="path">
    <div class="lbl"><span>{{ field.label }}</span></div>
    <div class="ro">
      <Icon name="info" size="s16" />
      <span>{{ field.text }} <router-link v-if="field.link" :to="field.link">{{ field.linkText }}</router-link></span>
    </div>
  </div>
</template>
