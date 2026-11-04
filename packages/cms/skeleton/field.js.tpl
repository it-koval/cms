// Field “@@title@@” (type '@@type@@') for the admin panel. The server loads this script: $registry->adminAssets(js: ['@@jsUrl@@']).
// Vue is the same instance as in the admin panel (window.ITK.vue); components use h(), so no build step is needed.
const { defineComponent, h } = window.ITK.vue
const { FieldShell } = window.ITK.ui

const @@component@@ = defineComponent({
  name: '@@component@@',
  // same props and events as the built-in fields
  props: {
    field: { type: Object, required: true },
    modelValue: { default: undefined },
    path: { type: String, required: true },
    top: { type: Boolean, default: false },
    changed: { type: Boolean, default: false },
    error: { type: String, default: '' },
  },
  emits: ['update:modelValue', 'revert'],
  // text for the column of a list: cell(value, field) { return String(value ?? '') },
  setup(props, { emit }) {
    const id = () => 'f_' + props.path.replace(/\./g, '_')
    return () =>
      h(
        FieldShell,
        { label: props.field.label, path: props.path, forId: id(), helpId: id() + '_h', help: props.field.help, error: props.error, changed: props.changed, top: props.top, onRevert: () => emit('revert') },
        () => h('input', { id: id(), type: 'text', value: props.modelValue ?? '', maxlength: props.field.max || undefined, onInput: (e) => emit('update:modelValue', e.target.value) }),
      )
  },
})

window.ITK.registerPlugin({ fields: { '@@type@@': @@component@@ } })
