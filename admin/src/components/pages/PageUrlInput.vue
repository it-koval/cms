<script setup>
// The address input of «Настройки страницы». The main language edits the whole address; every other language has its prefix fixed in front of the input:
//   [ /en ][ /about-us          ]      v-model = the part after the prefix ('/about-us', '/' for the language's home page)
// Put it inside <FieldShell for-id="…">; `describedby` is optional.
//   <PageUrlInput id="ps-url" v-model="slug" prefix="/en" :disabled="…" :invalid="…" @blur="…" @input="…" />
import { computed } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  id: { type: String, required: true },
  prefix: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  invalid: { type: Boolean, default: false },
  placeholder: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'blur', 'input'])
const pid = computed(() => props.id + '-pre')
</script>

<template>
  <div :class="['uf', { pre: !!prefix, off: disabled, bad: invalid }]">
    <span v-if="prefix" :id="pid" class="uf-p">{{ prefix }}</span>
    <input
      :id="id" :value="modelValue" type="text" autocomplete="off" spellcheck="false" :disabled="disabled" :placeholder="placeholder"
      :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="prefix ? pid : undefined"
      @input="emit('update:modelValue', $event.target.value); emit('input', $event)" @blur="emit('blur', $event)"
    >
  </div>
</template>

<style scoped>
.uf { display: flex; align-items: stretch; width: 100%; }
.uf-p {
  display: inline-flex; align-items: center; flex: none; height: 36px; padding: 0 9px 0 11px;
  border: 1px solid var(--ctl); border-right: 0; border-radius: var(--r) 0 0 var(--r);
  background: var(--hair-2); color: var(--ink-2); font-weight: 600; font-variant-numeric: tabular-nums;
}
.uf.pre input { border-top-left-radius: 0; border-bottom-left-radius: 0; min-width: 0; }
.uf.pre input:focus { position: relative; z-index: 1; }
.uf.off .uf-p { border-color: var(--hair); color: #6E7D87; }
.uf.bad .uf-p { border-color: var(--red); }
</style>
