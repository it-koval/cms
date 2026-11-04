// The look of the admin (colours, corners). The server makes it: AdminTheme turns the brand / accent colours and the radius into CSS variables and the shell page
// inlines them before the first paint (window.ITK_ADMIN.theme). This file only keeps them in step with a save made on this screen.
//   themeLogo()           the admin's own logo from the developer's config, or ''
//   themeName()           the product name for the header from the developer's config (itk_cms.admin.theme.name), or PRODUCT
//   applyTheme(payload)   sets the variables of a theme payload ({ theme, vars }, the answer of /admin-theme) on the page: the whole admin changes at once, no reload
import { boot } from '@/api/http.js'

export const PRODUCT = 'ITK CMS'

export const themeLogo = () => String((boot().theme || {}).logo || '')

export const themeName = () => String((boot().theme || {}).name || '').trim() || PRODUCT

/** Let `vars` ({ '--cy': '#0B5D80', … }) restyle the page; the tab colour and the tab icon follow the brand colour. */
export function applyTheme(payload) {
  if (!payload || !payload.vars || typeof document === 'undefined') return
  const root = document.documentElement
  Object.entries(payload.vars).forEach(([name, value]) => root.style.setProperty(name, value))
  const brand = payload.theme && payload.theme.brand
  if (!brand) return
  const meta = document.querySelector('meta[name="theme-color"]')
  if (meta) meta.setAttribute('content', brand)
  const icon = document.querySelector('link[rel="icon"]')
  if (icon && String(icon.getAttribute('href')).startsWith('data:image/svg+xml')) {
    icon.setAttribute('href', `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect width="20" height="20" rx="4" fill="${brand}"/><rect x="4" y="4" width="12" height="3.2" rx=".8" fill="#fff"/><rect x="4" y="8.6" width="12" height="3.2" rx=".8" fill="#fff" opacity=".6"/><rect x="4" y="13.2" width="12" height="3" rx=".8" fill="#fff" opacity=".3"/></svg>`)}`)
  }
}
