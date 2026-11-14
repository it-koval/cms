<script setup>
// The permission matrix of a role: one row per area of the site (generated: utils/permissions.js permissionAreas), columns Публиковать / Смотреть / Править / Удалять (MATRIX_COLUMNS).
// Dependencies: «Править» needs «Смотреть», «Публиковать» and «Удалять» need «Править» (ticking pulls the needed boxes in, clearing one takes the dependent ones away).
// Pages have a second line: «Какие страницы» = every template or only some (a role of authors who write only blog articles).
// readonly = a built-in role: ticks are shown as marks, nothing can be changed.
// A box the person at the screen does not have himself cannot be ticked (nobody can give a right beyond his own; «Администратор» has them all): it is disabled with the reason;
// a right the role already holds (`keep` = its saved rights) can still be cleared and ticked again.
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { ACTIONS, GROUPS, MATRIX_COLUMNS, toggleAction } from '@/utils/permissions.js'
import { templatesFor } from '@/schema/templates.js'
import { app } from '@/stores/app.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  areas: { type: Array, required: true },
  perms: { type: Object, required: true },
  templates: { type: Array, default: null }, // null = every template
  readonly: { type: Boolean, default: false },
  articleTemplates: { type: Array, default: () => [] }, // what «Только некоторые» starts with
  templateError: { type: String, default: '' },
  keep: { type: Object, default: () => ({}) }, // the saved rights of the role: they stay available whatever the person at the screen has
})
const emit = defineEmits(['update:perms', 'update:templates'])

const options = computed(() => templatesFor().map((tpl) => ({ key: tpl.key, name: tpl.name })))
const nameOf = (k) => (options.value.find((o) => o.key === k) || {}).name || k
const groups = computed(() => GROUPS.map((g) => ({ ...g, areas: props.areas.filter((a) => a.group === g.key) })).filter((g) => g.areas.length))
const has = (area, action) => (props.perms[area.key] || []).includes(action)
const columns = MATRIX_COLUMNS
const limit = computed(() => (app.user ? app.user.perms || {} : null))
const locked = (area, action) => !!limit.value && !(limit.value[area.key] || []).includes(action) && !(props.keep[area.key] || []).includes(action)

function toggle(area, action, on) {
  const next = { ...props.perms }
  const acts = toggleAction(next[area.key] || [], action, on, area.actions)
  if (acts.length) next[area.key] = acts
  else delete next[area.key]
  emit('update:perms', next)
}

const scoped = computed(() => Array.isArray(props.templates))
function setScope(only) { emit('update:templates', only ? (props.articleTemplates.length ? [...props.articleTemplates] : [options.value[0]?.key].filter(Boolean)) : null) }
function toggleTemplate(key, on) {
  const cur = props.templates || []
  emit('update:templates', on ? [...cur, key] : cur.filter((k) => k !== key))
}
const pagesOn = computed(() => (props.perms.pages || []).length > 0)
const scopeText = computed(() => (scoped.value ? t('people.matrix_scope_only', { list: props.templates.map(nameOf).join(', ') || t('people.matrix_scope_none') }) : t('people.matrix_all_templates')))
const actionLabel = (key) => (ACTIONS.find((a) => a.key === key) || {}).label || key
const empty = computed(() => !Object.keys(props.perms).length)
</script>

<template>
  <div class="pm">
    <div v-if="empty && !readonly" class="alert info-a pm-empty"><Icon name="info" size="s16" /><span>{{ t('people.matrix_empty') }}</span></div>
    <table class="t pm-t" :aria-label="t('people.matrix_aria')">
      <thead>
        <tr>
          <th class="pm-area">{{ t('people.matrix_area') }}</th>
          <th v-for="c in columns" :key="c.key" class="pm-c">{{ c.label }}</th>
        </tr>
      </thead>
      <tbody v-for="g in groups" :key="g.key">
        <tr class="pm-g"><th colspan="5" scope="colgroup">{{ g.label }}</th></tr>
        <template v-for="a in g.areas" :key="a.key">
          <tr class="pm-row">
            <th scope="row" class="pm-area">
              <div class="pm-nm">{{ a.label }}</div>
              <div v-if="a.hint" class="pm-hint">{{ a.hint }}</div>
            </th>
            <td v-for="c in columns" :key="c.key" class="pm-c" :data-label="c.label">
              <template v-if="a.actions.includes(c.key)">
                <span v-if="readonly" :class="['pm-mark', { on: has(a, c.key) }]"><Icon v-if="has(a, c.key)" name="check" size="s16" /><span class="sr">{{ t(has(a, c.key) ? 'people.matrix_mark_yes' : 'people.matrix_mark_no', { action: c.label, area: a.label }) }}</span></span>
                <label v-else :class="['pm-box', { locked: locked(a, c.key) }]" :title="locked(a, c.key) ? t('people.matrix_locked_title') : undefined"><input type="checkbox" :checked="has(a, c.key)" :disabled="locked(a, c.key)" @change="toggle(a, c.key, $event.target.checked)"><span class="sr">{{ t(locked(a, c.key) ? 'people.matrix_box_locked' : 'people.matrix_box', { action: c.label, area: a.label }) }}</span></label>
              </template>
              <span v-else class="pm-na" :title="t('people.matrix_na_title')"><span aria-hidden="true">–</span><span class="sr">{{ t('people.matrix_na', { action: c.label, area: a.label }) }}</span></span>
            </td>
          </tr>
          <tr v-if="a.scope === 'templates' && (pagesOn || readonly)" class="pm-scope">
            <td colspan="5">
              <div v-if="readonly" class="pm-sc-ro"><b>{{ t('people.matrix_scope_label') }}</b> {{ pagesOn ? scopeText : t('people.matrix_pages_off') }}</div>
              <fieldset v-else :class="['pm-sc', { bad: !!templateError }]">
                <legend>{{ t('people.matrix_scope') }}</legend>
                <div class="pm-radios">
                  <label><input type="radio" name="pm-scope" :checked="!scoped" @change="setScope(false)"> {{ t('people.matrix_all_templates') }}</label>
                  <label><input type="radio" name="pm-scope" :checked="scoped" @change="setScope(true)"> {{ t('people.matrix_some_templates') }}</label>
                </div>
                <div v-if="scoped" class="pm-tpls">
                  <label v-for="o in options" :key="o.key" :class="['pm-tpl', { on: templates.includes(o.key) }]"><input type="checkbox" :checked="templates.includes(o.key)" @change="toggleTemplate(o.key, $event.target.checked)">{{ o.name }}</label>
                </div>
                <div v-if="scoped" class="pm-sch">{{ t('people.matrix_scope_help') }}</div>
                <div v-if="templateError" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ templateError }}</span></div>
              </fieldset>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
    <p v-if="!readonly" class="pm-note">{{ t('people.matrix_note', { view: actionLabel('view'), edit: actionLabel('edit'), publish: actionLabel('publish'), delete: actionLabel('delete') }) }}</p>
  </div>
</template>

<style scoped>
.pm-empty{margin:0 0 12px}
.pm-t{table-layout:fixed}
.pm-t th.pm-area{width:auto;text-align:left}
.pm-t th.pm-c,.pm-t td.pm-c{width:104px;text-align:center}
.pm-t tbody tr.pm-row:hover{background:var(--sheet-2)}
.pm-t tbody tr:last-child td{border-bottom:1px solid var(--hair-2)}
.pm-t th[scope=row]{background:none;position:static;padding:10px 14px;border-bottom:1px solid var(--hair-2);font-weight:inherit;font-size:inherit;color:inherit;letter-spacing:0}
.pm-t tbody tr.pm-g:hover{background:var(--sheet)}
.pm-g th{background:var(--sheet);position:static;padding:16px 14px 6px;font-size:var(--t-sm);font-weight:650;color:var(--ink);border-bottom:1px solid var(--hair)}
.pm-nm{font-weight:580}
.pm-hint{font-size:var(--t-xs);color:var(--ink-2);line-height:1.35;margin-top:1px;max-width:52ch}
.pm-box{display:inline-grid;place-items:center;width:34px;height:34px;cursor:pointer;border-radius:var(--r)}
.pm-box:hover{background:var(--cy-100)}
.pm-box input{width:18px;height:18px;accent-color:var(--cy);margin:0;cursor:pointer}
.pm-box.locked{cursor:not-allowed}
.pm-box.locked:hover{background:none}
.pm-box.locked input{cursor:not-allowed;opacity:.45}
.pm-mark{display:inline-grid;place-items:center;width:34px;height:34px;color:var(--brand-text)}
.pm-na{color:var(--ctl)}
.pm-scope td{padding:0 14px 12px;border-bottom:1px solid var(--hair-2);background:var(--sheet-2)}
.pm-scope:hover{background:var(--sheet-2)!important}
.pm-sc{border:0;margin:0;padding:10px 0 0;min-width:0;display:flex;flex-direction:column;gap:8px}
.pm-sc legend{font-size:var(--t-sm);font-weight:600;padding:0;margin-bottom:6px;float:left;width:100%}
.pm-sc legend+*{clear:both}
.pm-radios{display:flex;gap:22px;flex-wrap:wrap}
.pm-radios label{display:inline-flex;align-items:center;gap:7px;font-size:var(--t-sm);cursor:pointer}
.pm-radios input{accent-color:var(--cy);margin:0;width:16px;height:16px}
.pm-tpls{display:flex;flex-wrap:wrap;gap:6px}
.pm-tpl{display:inline-flex;align-items:center;gap:7px;height:30px;padding:0 12px 0 9px;border:1px solid var(--hair);border-radius:15px;background:#fff;font-size:var(--t-sm);cursor:pointer;transition:background var(--dur),border-color var(--dur)}
.pm-tpl:hover{border-color:var(--ctl)}
.pm-tpl.on{background:var(--cy-50);border-color:var(--cy-200);color:var(--cy-dd);font-weight:560}
.pm-tpl input{accent-color:var(--cy);margin:0;width:15px;height:15px}
.pm-sch{font-size:var(--t-xs);color:var(--ink-2)}
.pm-sc-ro{padding-top:10px;font-size:var(--t-sm)}
.pm-note{font-size:var(--t-xs);color:var(--ink-2);margin:10px 14px 0}
/* phone: every section is a card; the four rights stand in a row under its name, each with its own caption */
@media (max-width:767px){
  .pm-t,.pm-t tbody,.pm-t tr,.pm-t th,.pm-t td{display:block}
  .pm-t thead{display:none}
  .pm-t tr.pm-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0 2px;padding:10px 12px 8px;border-bottom:1px solid var(--hair-2)}
  .pm-t th[scope=row]{grid-column:1/-1;padding:0 0 4px;border:0}
  .pm-t td.pm-c{width:auto;display:flex;flex-direction:column;align-items:center;gap:0;padding:0;border:0;font-size:var(--t-xs);color:var(--ink-2)}
  .pm-t td.pm-c::before{content:attr(data-label);font-size:var(--t-xs);color:var(--ink-2)}
  .pm-t th.pm-c{display:none}
  .pm-g th{padding:16px 12px 6px}
  .pm-box,.pm-mark{width:44px;height:44px}
  .pm-box input{width:22px;height:22px}
  .pm-scope td{padding:0 12px 12px}
  .pm-radios{flex-direction:column;gap:2px}
  .pm-radios label{min-height:44px}
  .pm-radios input{width:20px;height:20px}
  .pm-tpl{height:44px;padding:0 14px 0 12px;border-radius:22px}
  .pm-tpl input{width:20px;height:20px}
  .pm-note{margin:10px 12px 0}
}
</style>
