<script setup>
// The layout of a list of fields: a heading before a field that has `section`, two neighbours with `half` in one row (a column on a phone).
// The caller draws a field itself, in the slot:
//   <FieldLayout :items="rows" :field-of="(r) => r.f"><template #default="{ item }"> … </template></FieldLayout>
// Without field-of the items are the fields.
import { computed } from 'vue'
import { layoutBlocks } from '@/utils/layout.js'

const props = defineProps({
  items: { type: Array, required: true },
  fieldOf: { type: Function, default: (x) => x },
})
const blocks = computed(() => layoutBlocks(props.items, props.fieldOf))
</script>

<template>
  <template v-for="b in blocks" :key="b.key">
    <h3 v-if="b.section" class="fsec">{{ b.section }}</h3>
    <div v-if="b.items.length > 1" class="frow">
      <slot v-for="item in b.items" :key="fieldOf(item).k" :item="item" />
    </div>
    <slot v-else :item="b.items[0]" />
  </template>
</template>
