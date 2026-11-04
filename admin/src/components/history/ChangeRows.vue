<script setup>
// The rows of changes the server writes for a history entry: { kind, label, val, old }.  <ChangeRows :rows="entry.summary" />
defineProps({ rows: { type: Array, default: () => [] } })
</script>

<template>
  <ul class="chg">
    <li v-for="(c, i) in rows" :key="i">
      <span class="pmark" aria-hidden="true"><i /></span>
      <span>
        <b>{{ c.label }}</b>
        <template v-if="c.val || c.old"><br><span class="muted"><template v-if="c.old"><s>{{ c.old }}</s> → </template>{{ c.val }}</span></template>
      </span>
    </li>
  </ul>
</template>

<style scoped>
.chg { list-style: none; margin: 0; padding: 0; }
.chg li { display: grid; grid-template-columns: 14px 1fr; gap: 8px; padding: 6px 0; font-size: var(--t-sm); align-items: start; overflow-wrap: anywhere; }
.chg li .pmark { margin-top: 5px; }
</style>
