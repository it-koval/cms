<script setup>
// A QR code drawn as one SVG path from the text (the vetted library `qrcode-generator`, loaded only when a QR is needed). No network, no image file.
//   <TwoFactorQr :text="uri" :size="184" label="QR-код для приложения" />
import { ref, watch } from 'vue'
import { t } from '@/i18n/index.js'

const props = defineProps({ text: { type: String, default: '' }, size: { type: Number, default: 184 }, label: { type: String, default: () => t('core.qr_label') } })
const QUIET = 4 // empty modules around the code, as the standard asks
const path = ref('')
const modules = ref(0)

async function draw() {
  path.value = ''
  if (!props.text) return
  const { default: qrcode } = await import('qrcode-generator')
  const qr = qrcode(0, 'M')
  qr.addData(props.text)
  qr.make()
  const n = qr.getModuleCount()
  let d = ''
  for (let r = 0; r < n; r++) {
    for (let c = 0; c < n; c++) {
      if (qr.isDark(r, c)) d += `M${c + QUIET} ${r + QUIET}h1v1h-1z`
    }
  }
  modules.value = n + QUIET * 2
  path.value = d
}
watch(() => props.text, draw, { immediate: true })
</script>

<template>
  <svg v-if="path" class="qr" :viewBox="`0 0 ${modules} ${modules}`" :width="size" :height="size" role="img" :aria-label="label" shape-rendering="crispEdges">
    <rect :width="modules" :height="modules" fill="#fff" />
    <path :d="path" fill="#000" />
  </svg>
  <div v-else class="qr qr-empty" :style="{ width: size + 'px', height: size + 'px' }" aria-hidden="true" />
</template>

<style scoped>
.qr{display:block;border:1px solid var(--hair);border-radius:var(--r);background:#fff}
.qr-empty{background:var(--hair-2, #eef0f2)}
</style>
