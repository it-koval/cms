<script setup>
// Sidebar mode «Добавить секцию»: the block library by group, each with a silhouette. Picking inserts at the marked place.
import { computed, inject } from 'vue'
import Silhouette from '@/components/ui/Silhouette.vue'
import { t } from '@/i18n/index.js'
import { groupLabel, sectionLibrary } from '@/schema/sections.js'
import { EDITOR_KEY, EDITOR_UI_KEY } from './editorState.js'

const ed = inject(EDITOR_KEY)
const ui = inject(EDITOR_UI_KEY)
const groups = computed(() => sectionLibrary())
const shown = computed(() => groups.value.filter((g) => !ed.libGrp || g.group === ed.libGrp))
</script>

<template>
  <div v-if="!ed.canAdd" id="sbBody" class="sb-body"><p class="sb-help">{{ t('editor.lib_no_sections', { name: ed.template.name }) }}</p></div>
  <div v-if="ed.canAdd" class="lib-tabs" role="group" :aria-label="t('editor.lib_categories')">
    <button type="button" :aria-pressed="!ed.libGrp" @click="ed.libGrp = ''">{{ t('editor.lib_all') }}</button>
    <button v-for="g in groups" :key="g.group" type="button" :aria-pressed="ed.libGrp === g.group" @click="ed.libGrp = g.group">{{ groupLabel(g.group) }}</button>
  </div>
  <div v-if="ed.canAdd" id="sbBody" class="sb-body">
    <section v-for="g in shown" :key="g.group" class="lib-g">
      <h3>{{ groupLabel(g.group) }}</h3>
      <button v-for="i in g.items" :key="i.key" type="button" class="lib-i" @click="ui.pickType(i.key)">
        <span class="lib-sl"><Silhouette :type="i.key" :w="184" :h="92" fit="meet" /></span>
        <span class="lib-tx"><b>{{ i.name }}</b><span>{{ i.about }}</span></span>
      </button>
    </section>
  </div>
</template>
