import { compile } from 'vue'
import { compileScript, parse } from '@vue/compiler-sfc'

// Compile the actual SFC template for DOM assertions (Vitest imports SFCs in SSR mode).
export function clientComponent(component, source) {
  const { descriptor } = parse(source)
  const { bindings } = compileScript(descriptor, { id: 'client-test' })
  component.render = compile(descriptor.template.content, { bindingMetadata: bindings, prefixIdentifiers: true })
  return component
}
