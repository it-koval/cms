<script setup>
// The one-time password of another person (after «Пригласить пользователя» and «Сбросить пароль» when the site cannot send mail, or when the letter did not go: `mailError`).
// The administrator sees the password here, once, and passes it on himself. Closing the window forgets it: the server does not keep it in plain text.
import { computed } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import Icon from '@/components/ui/Icon.vue'
import { useToast } from '@/components/ui/useToast.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  name: { type: String, default: '' },
  email: { type: String, default: '' },
  password: { type: String, default: '' },
  invite: { type: Boolean, default: false }, // a new person (true) or a reset for an existing one
  mailError: { type: String, default: '' }, // why the letter with a link did not go (mail is set up, but the queue refused)
})
const emit = defineEmits(['update:open'])
const toast = useToast()

const title = computed(() => (props.invite ? t('people.otp_added') : t('people.otp_new')))

async function copy() {
  try { await navigator.clipboard.writeText(props.password); toast.show(t('people.otp_copied')) } catch (e) { toast.error(t('people.otp_copy_failed')) }
}
</script>

<template>
  <Modal :open="open" :title="title" :width="480" :close-on-scrim="false" initial-focus="#otp-copy" @update:open="emit('update:open', $event)">
    <p class="otp-who"><b>{{ name || email }}</b><template v-if="name && email"> · <span class="muted">{{ email }}</span></template></p>
    <div class="otp-box">
      <label class="sr" for="otp-pass">{{ t('people.otp_label') }}</label>
      <input id="otp-pass" class="otp-pass" type="text" readonly :value="password" autocomplete="off" spellcheck="false" @focus="$event.target.select()">
      <button id="otp-copy" type="button" class="btn sm" @click="copy"><Icon name="copy" size="s14" />{{ t('people.otp_copy') }}</button>
    </div>
    <div v-if="mailError" class="alert warn-a otp-mail" role="status"><Icon name="alert" size="s16" /><span>{{ t('people.otp_mail_failed', { error: mailError }) }}</span></div>
    <p class="otp-note">{{ t('people.otp_note') }}</p>
    <div class="alert info-a"><Icon name="info" size="s16" /><span>{{ t('people.otp_once', { reset: t('people.drawer_reset_pw') }) }}</span></div>
    <template #footer>
      <button type="button" class="btn primary" @click="emit('update:open', false)">{{ t('people.otp_done') }}</button>
    </template>
  </Modal>
</template>

<style scoped>
.otp-who { margin-bottom: 12px; }
.otp-box { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.otp-pass { flex: 1; min-width: 0; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: var(--t-md); letter-spacing: .04em; }
.otp-note { margin-bottom: 12px; line-height: 1.45; }
.otp-mail { margin-bottom: 12px; }
</style>
