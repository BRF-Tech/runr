import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

// Correctness rules stay errors; template formatting rules are off (formatting
// is a style choice, not a lint failure). Run: npm run lint — it fails on any warning too
// (--max-warnings 0), so warnings are fixed or, where intended, disabled inline with a reason.
export default [
  { ignores: ['dist/**', 'coverage/**', 'node_modules/**', 'public/**', 'playwright-report/**', 'test-results/**', 'blob-report/**'] },
  js.configs.recommended,
  ...pluginVue.configs['flat/recommended'],
  {
    files: ['**/*.{js,vue}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: { ...globals.browser },
    },
    rules: {
      // Silent `catch {}` is used on purpose for best-effort calls (polling, storage).
      'no-empty': ['error', { allowEmptyCatch: true }],
      // Dead locals are warnings (npm run lint allows none); `const { secret, ...rest } = obj` is allowed.
      'no-unused-vars': ['warn', { ignoreRestSiblings: true, caughtErrors: 'none' }],
      // Forms pass one reactive object down to step components that edit its fields;
      // only reassigning a prop itself (which never reaches the parent) is an error.
      'vue/no-mutating-props': ['error', { shallowOnly: true }],
      'vue/no-v-html': 'warn',
      'vue/require-default-prop': 'warn',
      'vue/require-prop-types': 'warn',

      // Template formatting
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
      'vue/multiline-html-element-content-newline': 'off',
      'vue/html-indent': 'off',
      'vue/first-attribute-linebreak': 'off',
      'vue/html-closing-bracket-newline': 'off',
      'vue/html-closing-bracket-spacing': 'off',
      'vue/html-self-closing': 'off',
      'vue/no-multi-spaces': 'off',
      'vue/attributes-order': 'off',
    },
  },
  {
    files: ['tests/**/*.js', '*.config.js'],
    languageOptions: { globals: { ...globals.node } },
  },
]
