import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import BrandingForm from '@/modules/white-label/components/BrandingForm.vue'
import { flush, globalOptions, ok } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const PNG_DATA_URI = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='

const resolved = {
  product_name: 'Acme Cloud',
  company_name: 'Acme Ltd',
  logo_light_url: PNG_DATA_URI,
  logo_dark_url: null,
  logo_mark_url: null,
  favicon_url: null,
  primary_color: '#112233',
  accent_color: '#e63946',
  palette: null,
  login_headline: 'Ship functions',
  login_text: '',
  footer_links: [{ label: 'Status', url: 'https://status.acme.test' }],
  support_email: '',
  support_url: '',
  email_from_name: '',
  email_logo_url: null,
  powered_by: true,
  is_default_brand: false,
  tenant: null,
  features: { plans: false, tenant_branding: true, multi_tenant: true },
}

const adminPayload = {
  branding: resolved,
  stored: {
    product_name: 'Acme Cloud',
    company_name: 'Acme Ltd',
    primary_color: '#112233',
    login_headline: 'Ship functions',
    logo_light_url: PNG_DATA_URI,
    footer_links: [{ label: 'Status', url: 'https://status.acme.test' }],
  },
  defaults: { product_name: 'RunR', company_name: 'BRF Teknoloji', primary_color: '#f97316', accent_color: '#e63946', powered_by: true },
  tenant_overrides_enabled: true,
}

async function mountPlatform(payload = adminPayload) {
  vi.mocked(client.get).mockReturnValue(ok(payload))
  vi.mocked(client.put).mockReturnValue(ok(payload))
  const wrapper = mount(BrandingForm, { props: { scope: 'platform' }, global: globalOptions() })
  await flush()
  return wrapper
}

const lastPut = () => vi.mocked(client.put).mock.calls.at(-1)

describe('BrandingForm', () => {
  beforeEach(() => {
    localStorage.clear()
    for (const fn of Object.values(client)) vi.mocked(fn).mockReset()
  })

  it('renders the stored platform branding, data: URI logos included', async () => {
    const wrapper = await mountPlatform()

    expect(client.get).toHaveBeenCalledWith('/admin/branding')
    expect(wrapper.find('[data-test="field-product_name"]').element.value).toBe('Acme Cloud')
    expect(wrapper.find('[data-test="field-company_name"]').element.value).toBe('Acme Ltd')
    expect(wrapper.find('[data-test="field-primary_color"]').element.value).toBe('#112233')
    expect(wrapper.find('[data-test="field-tenant_overrides_enabled"]').element.checked).toBe(true)
    // the data: URI is used as the image source as it is; the field accepts it (no URL validation)
    expect(wrapper.find('[data-test="asset-preview-logo_light"]').attributes('src')).toBe(PNG_DATA_URI)
    expect(wrapper.find('[data-test="field-logo_light_url"]').attributes('type')).toBe('text')
    // no mark set: the bundled RunR icon is shown
    expect(wrapper.find('[data-test="asset-preview-logo_mark"]').attributes('src')).not.toBe('')
    // re-branded: "© <year> <company>" + Powered by RunR in the preview footer
    const preview = wrapper.find('[data-test="branding-preview"]').text()
    expect(preview).toContain(`© ${new Date().getFullYear()} Acme Ltd`)
    expect(preview).toContain('Powered by RunR')
  })

  it('sends null for a field reset to its default and trims text', async () => {
    const wrapper = await mountPlatform()

    await wrapper.find('[data-test="reset-product_name"]').trigger('click')
    await wrapper.find('[data-test="field-login_text"]').setValue('  Hello  ')
    await wrapper.find('[data-test="field-email_from_name"]').setValue('Acme Billing')
    await wrapper.find('form').trigger('submit')
    await flush()

    const [url, body] = lastPut()
    expect(url).toBe('/admin/branding')
    expect(body.product_name).toBeNull()
    expect(body.company_name).toBe('Acme Ltd')
    expect(body.login_text).toBe('Hello')
    expect(body.email_from_name).toBe('Acme Billing')
    expect(body.primary_color).toBe('#112233')
    expect(body.accent_color).toBeNull()
    expect(body.primary_scale).toBeNull()
    expect(body.logo_light_url).toBe(PNG_DATA_URI)
    expect(body.logo_mark_url).toBeNull()
    expect(body.email_logo_url).toBeNull()
    expect(body.footer_links).toEqual([{ label: 'Status', url: 'https://status.acme.test' }])
    expect(body.tenant_overrides_enabled).toBe(true)
  })

  it('the "Ocean blue" preset fills base, accent and the full scale', async () => {
    const wrapper = await mountPlatform()

    await wrapper.find('[data-test="branding-preset"]').setValue('ocean')
    expect(wrapper.find('[data-test="field-primary_color"]').element.value).toBe('#009fe3')
    expect(wrapper.find('[data-test="swatch-600"]').attributes('title')).toBe('#007bc0')

    await wrapper.find('form').trigger('submit')
    await flush()

    const body = lastPut()[1]
    expect(body.primary_color).toBe('#009fe3')
    expect(body.accent_color).toBe('#009fe3')
    expect(body.primary_scale).toEqual({
      300: '#66c5ed', 400: '#33b5e9', 500: '#009fe3', 600: '#007bc0', 700: '#0055b8', 800: '#004499', 900: '#002b80',
    })
  })

  it('the "RunR (default)" preset sends the RunR colours', async () => {
    const wrapper = await mountPlatform()
    await wrapper.find('[data-test="branding-preset"]').setValue('runr')
    await wrapper.find('form').trigger('submit')
    await flush()

    const body = lastPut()[1]
    expect(body.primary_color).toBe('#f97316')
    expect(body.accent_color).toBe('#e63946')
    expect(body.primary_scale['300']).toBe('#fdba74')
    expect(body.primary_scale['900']).toBe('#7c2d12')
  })

  it('a single shade override sends the full scale; resetting it goes back to null', async () => {
    const wrapper = await mountPlatform()

    await wrapper.find('[data-test="shade-300"]').setValue('#ffeedd')
    await wrapper.find('form').trigger('submit')
    await flush()
    const scale = lastPut()[1].primary_scale
    expect(scale['300']).toBe('#ffeedd')
    expect(Object.keys(scale)).toEqual(['300', '400', '500', '600', '700', '800', '900'])
    expect(scale['500']).toBe('#112233')

    // the save answered with the stored branding (no scale), which the form shows again:
    // override the shade once more and reset it before saving
    await wrapper.find('[data-test="shade-300"]').setValue('#ffeedd')
    await wrapper.find('[data-test="reset-shade-300"]').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flush()
    expect(lastPut()[1].primary_scale).toBeNull()
  })

  it('drops an invalid colour (null = default) instead of sending it', async () => {
    const wrapper = await mountPlatform()

    await wrapper.find('[data-test="field-accent_color"]').setValue('blue')
    expect(wrapper.text()).toContain('Use a hex colour')
    await wrapper.find('form').trigger('submit')
    await flush()

    expect(lastPut()[1].accent_color).toBeNull()
  })

  it('uploads a logo mark as multipart form data', async () => {
    vi.mocked(client.post).mockReturnValue(ok(adminPayload))
    const wrapper = await mountPlatform()

    const input = wrapper.find('[data-test="upload-logo_mark"] input[type="file"]')
    const file = new File(['<svg/>'], 'mark.svg', { type: 'image/svg+xml' })
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await flush()

    const [url, form, config] = vi.mocked(client.post).mock.calls[0]
    expect(url).toBe('/admin/branding/assets/logo_mark')
    expect(form).toBeInstanceOf(FormData)
    expect(form.get('file')).toBe(file)
    expect(config.headers['Content-Type']).toBe('multipart/form-data')
  })

  it('RunR defaults: preview shows "RunR by BRF Teknoloji"', async () => {
    const wrapper = await mountPlatform({
      branding: { ...resolved, product_name: 'RunR', company_name: 'BRF Teknoloji', logo_light_url: null, is_default_brand: true },
      stored: {},
      defaults: adminPayload.defaults,
      tenant_overrides_enabled: false,
    })
    expect(wrapper.find('[data-test="branding-preview"]').text()).toContain('RunR by BRF Teknoloji')
    // swatches of the RunR palette (base #f97316)
    expect(wrapper.find('[data-test="swatch-500"]').attributes('title')).toBe('#f97316')
  })

  it('tenant scope: no platform-only fields; disabled by the platform shows a notice', async () => {
    vi.mocked(client.get).mockReturnValue(ok({ enabled: true, branding: resolved, stored: {} }))
    vi.mocked(client.put).mockReturnValue(ok({ enabled: true, branding: resolved, stored: {} }))
    const wrapper = mount(BrandingForm, { props: { scope: 'tenant' }, global: globalOptions() })
    await flush()

    expect(client.get).toHaveBeenCalledWith('/tenant/branding')
    expect(wrapper.find('[data-test="field-powered_by"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="field-tenant_overrides_enabled"]').exists()).toBe(false)

    await wrapper.find('form').trigger('submit')
    await flush()
    const [url, body] = lastPut()
    expect(url).toBe('/tenant/branding')
    expect(body).not.toHaveProperty('powered_by')
    expect(body).not.toHaveProperty('tenant_overrides_enabled')
    expect(body).toHaveProperty('company_name')

    vi.mocked(client.get).mockReturnValue(ok({ enabled: false, branding: resolved, stored: {} }))
    const disabled = mount(BrandingForm, { props: { scope: 'tenant' }, global: globalOptions() })
    await flush()
    expect(disabled.find('[data-test="tenant-branding-disabled"]').exists()).toBe(true)
  })
})

describe('BrandingForm: the theme tones and the default theme', () => {
  const NAVY_HEX = {
    50: '#fcfdff', 100: '#f8fafe', 200: '#f2f5fc', 300: '#ebf0fa', 400: '#dee8f8', 500: '#cddaf0',
    600: '#afc3e1', 700: '#1e3b85', 800: '#162e72', 900: '#0f2460', 950: '#091b4e',
  }
  const withDefaults = (stored = {}, defaults = {}) => ({
    ...adminPayload,
    stored: { ...adminPayload.stored, ...stored },
    defaults: { ...adminPayload.defaults, dark_color: '#0a0a0a', light_color: '#fffcf8', default_theme: 'dark', ...defaults },
  })

  beforeEach(() => {
    localStorage.clear()
    for (const fn of Object.values(client)) vi.mocked(fn).mockReset()
  })

  it('shows both themes with their defaults (the Helm values) and sends null for them', async () => {
    const wrapper = await mountPlatform(withDefaults())

    for (const theme of ['dark', 'light']) {
      expect(wrapper.find(`[data-test="theme-tones-${theme}"]`).exists()).toBe(true)
      expect(wrapper.find(`[data-test="field-${theme}_color"]`).element.value).toBe('')
      expect(wrapper.findAll(`[data-test^="${theme}-swatch-"]`)).toHaveLength(11)
    }
    expect(wrapper.find('[data-test="field-dark_color"]').attributes('placeholder')).toBe('#0a0a0a')
    expect(wrapper.find('[data-test="field-light_color"]').attributes('placeholder')).toBe('#fffcf8')
    expect(wrapper.find('[data-test="dark-swatch-950"]').attributes('title')).toBe('#0a0a0a')
    expect(wrapper.find('[data-test="light-swatch-900"]').attributes('title')).toBe('#fef5ef')
    // the default theme: "Default (Dark)"
    expect(wrapper.find('[data-test="field-default_theme"]').text()).toContain('Default (Dark)')

    await wrapper.find('form').trigger('submit')
    await flush()
    const body = lastPut()[1]
    expect(body).toMatchObject({ dark_color: null, dark_scale: null, light_color: null, light_scale: null, default_theme: null })
  })

  it('the stored values fill the fields; a base colour derives its scale', async () => {
    const wrapper = await mountPlatform(withDefaults({ dark_color: '#091b4e', default_theme: 'light', light_scale: { 950: '255 255 255' } }))

    expect(wrapper.find('[data-test="field-dark_color"]').element.value).toBe('#091b4e')
    expect(wrapper.find('[data-test="dark-swatch-950"]').attributes('title')).toBe('#091b4e')
    // a navy page: navy cards
    const card = wrapper.find('[data-test="dark-swatch-900"]').attributes('title')
    expect(parseInt(card.slice(5, 7), 16)).toBeGreaterThan(parseInt(card.slice(1, 3), 16))
    expect(wrapper.find('[data-test="light-swatch-950"]').attributes('title')).toBe('#ffffff')
    expect(wrapper.find('[data-test="field-default_theme"]').element.value).toBe('light')
    expect(wrapper.find('[data-test="reset-default_theme"]').exists()).toBe(true)

    await wrapper.find('form').trigger('submit')
    await flush()
    const body = lastPut()[1]
    expect(body.dark_color).toBe('#091b4e')
    expect(body.dark_scale).toBeNull()
    expect(body.default_theme).toBe('light')
    // a stored scale is sent whole
    expect(Object.keys(body.light_scale)).toHaveLength(11)
    expect(body.light_scale['950']).toBe('#ffffff')
  })

  it('the Ocean preset fills the navy dark theme and a cool light theme', async () => {
    const wrapper = await mountPlatform(withDefaults())

    await wrapper.find('[data-test="branding-preset"]').setValue('ocean')
    expect(wrapper.find('[data-test="field-dark_color"]').element.value).toBe('#091b4e')
    expect(wrapper.find('[data-test="dark-swatch-800"]').attributes('title')).toBe('#162e72')

    await wrapper.find('form').trigger('submit')
    await flush()
    const body = lastPut()[1]
    expect(body.dark_color).toBe('#091b4e')
    expect(body.dark_scale).toEqual(NAVY_HEX)
    expect(body.light_color).toBe('#ffffff')
    expect(body.light_scale['600']).toBe('#374669')
  })

  it('one tone set by hand sends the whole scale; "Default" brings each back', async () => {
    const wrapper = await mountPlatform(withDefaults())

    await wrapper.find('[data-test="dark-shade-900"]').setValue('#202020')
    await wrapper.find('[data-test="field-default_theme"]').setValue('light')
    await wrapper.find('form').trigger('submit')
    await flush()
    let body = lastPut()[1]
    expect(body.dark_scale['900']).toBe('#202020')
    expect(body.dark_scale['950']).toBe('#0a0a0a')
    expect(Object.keys(body.dark_scale)).toHaveLength(11)
    expect(body.default_theme).toBe('light')

    await wrapper.find('[data-test="dark-shade-900"]').setValue('#202020')
    await wrapper.find('[data-test="dark-reset-shade-900"]').trigger('click')
    await wrapper.find('[data-test="field-dark_color"]').setValue('#111111')
    await wrapper.find('[data-test="reset-dark_color"]').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flush()
    body = lastPut()[1]
    expect(body.dark_scale).toBeNull()
    expect(body.dark_color).toBeNull()
  })

  it('an invalid base colour is not sent; a hard-to-read base shows its contrast warning', async () => {
    const wrapper = await mountPlatform(withDefaults())

    await wrapper.find('[data-test="field-light_color"]').setValue('cream')
    expect(wrapper.find('[data-test="theme-tones-light"]').text()).toContain('Use a hex colour')
    expect(wrapper.find('[data-test="dark-contrast"]').text()).not.toContain('hard to read')

    // a mid-gray page for the dark theme: its light text no longer reads
    await wrapper.find('[data-test="field-dark_color"]').setValue('#9a9a9a')
    expect(wrapper.find('[data-test="dark-contrast"]').text()).toContain('hard to read')

    await wrapper.find('form').trigger('submit')
    await flush()
    expect(lastPut()[1].light_color).toBeNull()
  })

  it('the preview shows the login page in the tones being edited', async () => {
    const wrapper = await mountPlatform(withDefaults())
    await wrapper.find('[data-test="field-light_color"]').setValue('#ffffff')
    const card = wrapper.find('[data-test="preview-card"]').attributes('style')
    expect(card).toMatch(/background: (rgb\(255, 255, 255\)|#ffffff)/)
    expect(wrapper.find('[data-test="light-preview"]').attributes('style')).toMatch(/background: (rgb\(255, 255, 255\)|#ffffff)/)
  })

  it('tenant scope: the tones and the default theme are an organization\'s to set too', async () => {
    const tenantPayload = { enabled: true, branding: { ...resolved, dark_color: '#0a0a0a', light_color: '#fffcf8', default_theme: 'dark' }, stored: { dark_color: '#0a0a0a' } }
    vi.mocked(client.get).mockReturnValue(ok(tenantPayload))
    vi.mocked(client.put).mockReturnValue(ok(tenantPayload))
    const wrapper = mount(BrandingForm, { props: { scope: 'tenant' }, global: globalOptions() })
    await flush()

    expect(wrapper.find('[data-test="field-dark_color"]').element.value).toBe('#0a0a0a')
    await wrapper.find('[data-test="field-default_theme"]').setValue('light')
    await wrapper.find('form').trigger('submit')
    await flush()
    const [url, body] = lastPut()
    expect(url).toBe('/tenant/branding')
    expect(body).toMatchObject({ dark_color: '#0a0a0a', default_theme: 'light', light_color: null })
  })
})
