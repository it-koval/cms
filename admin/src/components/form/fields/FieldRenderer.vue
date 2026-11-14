<script setup>
// Picks the field component by field.t: a type a plugin registered (window.ITK.registerPlugin({ fields })) first, then the built-in ones; an unknown type is a text field.
// Used by SchemaForm and, recursively, by RepeaterField.
import { computed } from 'vue'
import { fieldEmits, fieldProps } from '../fieldProps.js'
import TextField from './TextField.vue'
import TextareaField from './TextareaField.vue'
import HeadlineField from './HeadlineField.vue'
import NumberField from './NumberField.vue'
import ToggleField from './ToggleField.vue'
import SelectField from './SelectField.vue'
import ImageField from './ImageField.vue'
import LinkField from './LinkField.vue'
import RepeaterField from './RepeaterField.vue'
import PhotosField from './PhotosField.vue'
import ReadonlyField from './ReadonlyField.vue'
import RelationField from './RelationField.vue'
import { pluginField } from '@/plugins/state.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
// looked up lazily: RepeaterField imports this file and this file imports RepeaterField
const comp = computed(() => pluginField(props.field.t) || ({
  text: TextField, textarea: TextareaField, headline: HeadlineField, number: NumberField, toggle: ToggleField, select: SelectField,
  image: ImageField, link: LinkField, repeater: RepeaterField, photos: PhotosField, readonly: ReadonlyField, relation: RelationField,
}[props.field.t] || TextField))
</script>

<template>
  <component :is="comp" v-bind="props" @update:model-value="emit('update:modelValue', $event)" @revert="emit('revert')" />
</template>
