// ESLint 9 (flat config): errors only (the code style is the surrounding code's, there is no formatter). Run: npm run lint
import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

export default [
  { ignores: ['dist/**', 'node_modules/**'] },
  js.configs.recommended,
  ...pluginVue.configs['flat/essential'],
  {
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: { ...globals.browser },
    },
    rules: {
      'no-unused-vars': ['error', { args: 'none', caughtErrors: 'none', ignoreRestSiblings: true }],
      'vue/multi-word-component-names': 'off', // Icon, Modal, Drawer, Popover: one word is the house style of ui/
    },
  },
  {
    files: ['*.config.js', 'build/**/*.js', 'scripts/**/*.mjs'],
    languageOptions: { globals: { ...globals.node } },
  },
]
