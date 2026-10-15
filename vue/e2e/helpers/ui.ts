import { expect, type Locator, type Page } from '@playwright/test'

// Selectors for markup patterns the app repeats. The forms put <label class="label">
// next to the control instead of wrapping or `for=`-linking it, so getByLabel does
// not work there; these helpers find the control that follows a label.

const escapeRe = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

/** The first input/textarea/select after the label whose text starts with `label`. */
export function field(scope: Page | Locator, label: string): Locator {
  return scope
    .locator('label', { hasText: new RegExp(`^\\s*${escapeRe(label)}`) })
    .first()
    .locator('xpath=following::*[self::input or self::textarea or self::select][1]')
}

/** Accepts the app's confirm dialog (ConfirmModal); the button reads `confirmLabel` or the title. */
export async function confirmDialog(page: Page, buttonName: string): Promise<void> {
  const button = page.getByRole('button', { name: buttonName, exact: true }).last()
  await expect(button).toBeVisible()
  await button.click()
}

/** Types at the end of the Monaco editor document. */
export async function appendInEditor(page: Page, text: string): Promise<void> {
  const editor = page.locator('.monaco-editor').first()
  await expect(editor).toBeVisible()
  await editor.locator('.view-lines').click()
  await page.keyboard.press('ControlOrMeta+End')
  await page.keyboard.type(text)
}

/** Replaces the whole Monaco editor document. */
export async function replaceInEditor(page: Page, text: string): Promise<void> {
  const editor = page.locator('.monaco-editor').first()
  await expect(editor).toBeVisible()
  await editor.locator('.view-lines').click()
  await page.keyboard.press('ControlOrMeta+A')
  await page.keyboard.press('Delete')
  await page.keyboard.type(text)
}

/** Primary "Continue →" button of the project wizards. */
export function continueButton(scope: Page | Locator): Locator {
  return scope.getByRole('button', { name: 'Continue →' })
}

/** Project tabs on the detail page (Versions, Deployments, Metrics, Domains, …). */
export async function openProjectTab(page: Page, name: string): Promise<void> {
  await page.getByRole('button', { name, exact: true }).click()
}
