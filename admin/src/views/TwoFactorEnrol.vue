<script setup>
// The only screen of a person whose role demands the second sign-in factor and who has none yet (window.ITK_ADMIN.user.twoFactor.enrol, see main.js): the rest of
// the admin does not start, the server refuses everything but /admin/api/2fa. When the factor is on and the recovery codes are saved, the page reloads into the admin.
import { boot, logoutUrl } from '@/api/http.js'
import TwoFactorPanel from '@/components/profile/TwoFactorPanel.vue'
import ToastHost from '@/components/ui/ToastHost.vue'
import { t } from '@/i18n/index.js'

const site = (boot().site && boot().site.name) || 'ITK CMS'
const email = (boot().user && boot().user.email) || ''
const done = () => window.location.reload()
</script>

<template>
  <main class="enrol">
    <div class="enrol-card">
      <div class="enrol-brand muted">{{ site }}</div>
      <h1>{{ t('core.enrol_title') }}</h1>
      <p class="muted">{{ t('core.enrol_lead', { email }) }}</p>
      <TwoFactorPanel forced @done="done" />
      <a class="enrol-out muted" :href="logoutUrl()">{{ t('core.nav_logout') }}</a>
    </div>
    <ToastHost />
  </main>
</template>

<style scoped>
.enrol{min-height:100vh;display:grid;place-items:start center;padding:8vh 16px 32px;background:var(--ground)}
.enrol-card{width:min(34rem,100%);background:var(--sheet);border:1px solid var(--hair);border-radius:var(--r-lg);padding:28px;box-shadow:var(--sh-1);display:flex;flex-direction:column;gap:14px}
.enrol-brand{font-size:var(--t-sm);font-weight:580}
h1{margin:0;font-size:var(--t-lg);font-weight:650;line-height:1.2}
p{margin:0;font-size:var(--t-sm);line-height:1.5}
.enrol-out{align-self:flex-start;font-size:var(--t-sm)}
</style>
