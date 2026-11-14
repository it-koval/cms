<script setup>
// Route /collections/:col — the list of a collection (table or cards), or the options matrix.
// The real work is in components/collections/: CollectionList.vue, MatrixView.vue. This view only picks which one, for the current site.
import { computed, watchEffect } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getCollectionConfig, getCollectionConfigs } from '@/schema/collections.js'
import CollectionList from '@/components/collections/CollectionList.vue'
import MatrixView from '@/components/collections/MatrixView.vue'

const route = useRoute()
const router = useRouter()
const cfg = computed(() => getCollectionConfig(route.params.col))

// a collection the current site does not have (an old link, the other demo site): go to the first one
watchEffect(() => {
  if (route.name !== 'collection' || cfg.value) return
  const first = getCollectionConfigs()[0]
  router.replace(first ? '/collections/' + first.key : '/')
})
</script>

<template>
  <!-- keyed by collection: the view is not re-mounted when only :col changes, but the list state must start fresh -->
  <template v-if="cfg">
    <MatrixView v-if="cfg.view === 'matrix'" :key="cfg.key" :cfg="cfg" />
    <CollectionList v-else :key="cfg.key" :cfg="cfg" />
  </template>
</template>
