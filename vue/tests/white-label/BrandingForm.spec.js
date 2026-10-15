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
