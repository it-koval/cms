<script setup>
// Route /pages/:id — the full-screen page editor (no rail). The editor is re-created for every page id AND every content language, so switching pages
// from the dropdown or switching the language saves the previous one first (route guards wait for the last autosave).
// The language is app.locale (the content language of the site); an editor language switch only has to call setContentLocale(code).
import { ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute } from 'vue-router'
import PageEditor from '@/components/editor/PageEditor.vue'
import { app, setContentLocale } from '@/stores/app.js'
import { confirm } from '@/components/ui/useConfirm.js'
import { toast } from '@/components/ui/useToast.js'
import { t } from '@/i18n/index.js'

const route = useRoute()
const editor = ref(null)
// false = stay: the draft was changed by someone else and the person has not yet chosen what happens to the edits on screen (the banner in the editor asks),
// or the last save failed (code 'save_failed') and the person does not want to lose the edits that are not on the server
const flush = async () => {
  if (!editor.value) return true
  try {
    await editor.value.flushAll()
  } catch (e) {
    if (e && e.code === 'save_failed') {
      return confirm({
        title: t('settings.leave_title'), message: t('editor.leave_unsaved_text', { reason: e.reason }),
        confirmLabel: t('settings.leave_confirm'), cancelLabel: t('people.btn_stay'), danger: true,
      })
    }
    toast.error((e && e.message) || t('editor.err_save_page'))
    return false
  }
  return true
}
onBeforeRouteLeave(flush)
onBeforeRouteUpdate(flush)

// the editor on screen keeps its own language until its last autosave is done, then the new language is shown
const shown = ref(app.locale)
watch(() => app.locale, async (next) => {
  if (next === shown.value) return
  if (await flush()) shown.value = next
  else setContentLocale(shown.value) // the edits on screen are not decided yet: the editor stays in its language
})
</script>

<template>
  <PageEditor ref="editor" :key="String(route.params.id) + ':' + shown" :page-id="String(route.params.id)" :locale="shown" />
</template>
