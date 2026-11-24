// Props and events shared by every field component. A field component renders <FieldShell> around its control.
export const fieldProps = {
  field: { type: Object, required: true }, // declarative field: { k, t, label, max, req, help, ... }
  modelValue: { default: undefined },
  path: { type: String, required: true }, // dotted path inside the form object, e.g. "points.2.title"
  top: { type: Boolean, default: false }, // top-level field of the form: shows the «Изменено / Отменить изменение» mark
  changed: { type: Boolean, default: false },
  error: { type: String, default: '' },
}
export const fieldEmits = ['update:modelValue', 'revert']

/** DOM id base for a field: stable per form + path. */
export const domId = (formId, path) => `${formId}_${String(path).replace(/\./g, '_')}`

/** Validation and hint references shared by native and composite field controls. */
export const fieldAria = (id, help, error) => ({
  'aria-invalid': error ? 'true' : undefined,
  'aria-describedby': [help ? id + '_h' : '', error ? id + '_e' : ''].filter(Boolean).join(' ') || undefined,
})
