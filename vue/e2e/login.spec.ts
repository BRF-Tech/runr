import { test, expect } from './fixtures/test'

test.describe('Login', () => {
  test('signs in with e-mail and password and opens the project list', async ({ page, auth }) => {
    await auth.loginViaUi()
    await expect(page).toHaveURL(/\/projects$/)
    await expect(page.getByRole('heading', { name: 'Projects' })).toBeVisible()
  })

  test('keeps the visitor on the login page after a wrong password', async ({ page, auth }) => {
    await auth.loginViaUi({ password: 'definitely-not-the-password' })
    await expect(page.getByText(/do not match|failed|invalid/i)).toBeVisible()
    await expect(page).toHaveURL(/\/login/)
  })

  test('sends anonymous visitors of protected pages to the login page', async ({ page }) => {
    await page.goto('/settings')
    await expect(page).toHaveURL(/\/login/)
    await expect(page.getByRole('heading', { name: 'Log In to your Account' })).toBeVisible()
  })

  test('signs out from the user menu', async ({ page, auth, isStack }) => {
    // Signing out revokes the token: on the stack, sign in on its own instead of with the
    // session the other tests share
    if (isStack) {
      await auth.loginViaUi()
      await expect(page).toHaveURL(/\/projects$/)
    } else {
      await auth.signIn()
      await page.goto('/projects')
    }
    await page.locator('[data-tour="settings-btn"]').click()
    await page.getByRole('button', { name: 'Logout' }).click()
    await expect(page).toHaveURL(/\/login/)
    expect(await page.evaluate(() => localStorage.getItem('token'))).toBeNull()
  })

  test.describe('two-factor authentication', () => {
    test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs an account with 2FA; the mocked API provides one')

    test('asks for the code after the password and signs in with it', async ({ page, auth, mockApi }) => {
      mockApi.db.twoFactorCode = '123456'
      await auth.loginViaUi({ code: '123456' })

      await expect(page).toHaveURL(/\/projects$/)
      const logins = mockApi.calls('POST', '/auth/login')
      expect(logins).toHaveLength(2)
      expect(logins[0].body.two_factor_code).toBeUndefined()
      expect(logins[1].body).toMatchObject({ login: auth.credentials.email, two_factor_code: '123456' })
    })

    test('rejects a wrong code and lets the user go back', async ({ page, auth, mockApi }) => {
      mockApi.db.twoFactorCode = '123456'
      await auth.loginViaUi({ code: '000000' })

      await expect(page.getByText('The two-factor code is invalid.')).toBeVisible()
      await page.getByRole('button', { name: '← Use a different account' }).click()
      await expect(page.getByPlaceholder('Email')).toBeVisible()
    })
  })
})
