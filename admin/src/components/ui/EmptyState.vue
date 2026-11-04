<script setup>
// <EmptyState icon="search" title="Ничего не найдено" text="..."> <button class="btn">Сбросить</button> </EmptyState>
import Icon from './Icon.vue'
defineProps({ icon: { type: String, default: 'search' }, title: { type: String, default: '' }, text: { type: String, default: '' } })
</script>

<template>
  <div class="empty">
    <div class="ill"><Icon :name="icon" size="s20" /></div>
    <h3 v-if="title">{{ title }}</h3>
    <p v-if="text">{{ text }}</p>
    <slot />
  </div>
</template>
