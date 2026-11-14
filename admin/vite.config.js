import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import thirdPartyNotices from './build/thirdPartyNotices.js'

// BUILD: the admin goes into the Composer package (packages/cms/public/admin) and is served by Symfony from /bundles/itkcms/admin/ (assets:install).
// THIRD_PARTY_NOTICES.txt (the licenses of the npm packages in the build) is written next to it by build/thirdPartyNotices.js.
// `manifest.json` (in .vite/) maps index.html to the hashed files; the server shell (templates/admin/index.html.twig) reads it.
// DEV (`npm run dev`): the Vite server at http://127.0.0.1:5174/bundles/itkcms/admin/ proxies the API, the login page and the page preview to the site
// (ITK_SITE_URL, default http://localhost:8080; VITE_ITK_ADMIN_PATH when the site's itk_cms.admin_path is not /admin). Sign in at the site's /admin/login first: the session cookie is shared between ports of one host.
// MOCK (`npm run dev:mock`, or VITE_MOCK=1 npm run dev): no PHP and no proxy; src/mock/ answers the API in the browser with made-up data kept in memory, and the page preview is a plain placeholder.
const SITE = process.env.ITK_SITE_URL || 'http://localhost:8080'
const ADMIN = process.env.VITE_ITK_ADMIN_PATH || '/admin' // itk_cms.admin_path of the site; the built admin takes it from the shell on the server, `npm run dev` from this variable
const toSite = { target: SITE, changeOrigin: false }

/** Mock mode only: the preview frame of the page editor (the real one is drawn by the site's templates) is a placeholder page. */
const mockPreview = () => ({
  name: 'itk-mock-preview',
  configureServer(server) {
    server.middlewares.use((req, res, next) => {
      if (!req.url || !req.url.startsWith(`${ADMIN}/preview/`)) return next()
      res.setHeader('Content-Type', 'text/html; charset=utf-8')
      res.end('<!doctype html><meta charset="utf-8"><title>Предпросмотр</title><body style="font:15px/1.5 system-ui;margin:0;display:grid;place-items:center;min-height:100vh;color:#5b6770;text-align:center;padding:24px">'
        + '<p>В режиме VITE_MOCK страницу рисует не сервер, а заглушка.<br>Форму слева можно править: данные остаются в памяти этой вкладки.</p>')
    })
  },
})

export default defineConfig(({ mode }) => {
  const mock = loadEnv(mode, process.cwd(), 'VITE_').VITE_MOCK === '1'
  return {
    base: '/bundles/itkcms/admin/',
    plugins: [vue(), thirdPartyNotices(), ...(mock ? [mockPreview()] : [])],
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    build: {
      outDir: fileURLToPath(new URL('../packages/cms/public/admin', import.meta.url)),
      emptyOutDir: true,
      manifest: true,
      assetsDir: 'assets',
      chunkSizeWarningLimit: 900,
      rollupOptions: {
        output: {
          // the rich-text editor (Tiptap and ProseMirror) is the heaviest dependency: its own named chunk, loaded when a rich-text field is first drawn (components/form/RichTextAsync.js).
          // Vue gets a chunk of its own too: without it Rollup puts the runtime where the Tiptap chunk, which imports Vue, can reach it, and every screen would pull Tiptap in.
          // Each language of the interface is one chunk, whatever the number of its files.
          manualChunks: (id) => {
            const language = /\/src\/i18n\/([a-z]+)\/[^/]+\.json$/.exec(id)
            if (language) return `i18n-${language[1]}`
            if (/node_modules\/(vue|@vue)\//.test(id)) return 'vue'
            if (/node_modules\/(@tiptap|prosemirror-[^/]+|orderedmap|rope-sequence|w3c-keyname|linkifyjs)\//.test(id)) return 'tiptap'
            return undefined
          },
        },
      },
    },
    // `npm test`: node by default; RichText behavior tests opt into jsdom with @vitest-environment.
    test: {
      environment: 'node',
      include: ['src/**/*.test.js', 'build/**/*.test.js', 'scripts/**/*.test.mjs'],
      setupFiles: ['src/i18n/vitest.setup.js'],
    },
    server: {
      port: 5174,
      host: '127.0.0.1',
      proxy: mock ? {} : {
        [ADMIN]: toSite, // /admin/api, /admin/preview, /admin/login, /admin/logout
        '^/bundles/itkcms/(?!admin/).*': toSite, // preview.js / preview.css of the package
        '/build': toSite, // the site's own assets inside the preview frame
        '/uploads': toSite,
      },
    },
  }
})
