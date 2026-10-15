/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js}'],
  theme: {
    extend: {
      fontFamily: {
        mono: ['JetBrains Mono', 'Fira Code', 'monospace'],
      },
      colors: {
        // Brand palette (brand-300 … brand-900, brand-primary, brand-accent): CSS variables
        // --brand-300 … --brand-900 / --brand-accent set from the white-label branding
        // (GET /api/branding), RunR's palette as fallback
        brand: {
          300: 'rgb(var(--brand-300, 253 186 116) / <alpha-value>)',
          400: 'rgb(var(--brand-400, 251 146 60) / <alpha-value>)',
          500: 'rgb(var(--brand-500, 249 115 22) / <alpha-value>)',
          600: 'rgb(var(--brand-600, 234 88 12) / <alpha-value>)',
          700: 'rgb(var(--brand-700, 194 65 12) / <alpha-value>)',
          800: 'rgb(var(--brand-800, 154 52 18) / <alpha-value>)',
          900: 'rgb(var(--brand-900, 124 45 18) / <alpha-value>)',
          primary: 'rgb(var(--brand-500, 249 115 22) / <alpha-value>)',
          accent:  'rgb(var(--brand-accent, 230 57 70) / <alpha-value>)',
        },
        gray: {
          950: 'rgb(var(--gray-950) / <alpha-value>)',
          900: 'rgb(var(--gray-900) / <alpha-value>)',
          800: 'rgb(var(--gray-800) / <alpha-value>)',
          700: 'rgb(var(--gray-700) / <alpha-value>)',
          600: 'rgb(var(--gray-600) / <alpha-value>)',
          500: 'rgb(var(--gray-500) / <alpha-value>)',
          400: 'rgb(var(--gray-400) / <alpha-value>)',
          300: 'rgb(var(--gray-300) / <alpha-value>)',
          200: 'rgb(var(--gray-200) / <alpha-value>)',
          100: 'rgb(var(--gray-100) / <alpha-value>)',
          50:  'rgb(var(--gray-50)  / <alpha-value>)',
        },
      },
    },
  },
  plugins: [],
}
