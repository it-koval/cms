import { formatNumber, t } from '@/i18n/index.js'

/** 23 -> «23 КБ», 1536 -> «1,5 МБ» (in the language of the screen) */
export function fileSize(kb) {
  const n = Number(kb) || 0
  return n >= 1024 ? t('forms.size_mb', { size: formatNumber(n / 1024, { maximumFractionDigits: 1 }) }) : t('forms.size_kb', { size: n })
}
