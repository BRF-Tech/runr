import { expect, type Locator, type Page } from '@playwright/test'
import { continueButton } from './ui'

/**
 * Opens the 4-step Edit Project modal from the project detail header and walks
 * forward to `step` (1 general, 2 type, 3 runtime + trigger, 4 resources + env vars).
 */
export async function openEditStep(page: Page, step: 1 | 2 | 3 | 4): Promise<Locator> {
  await page.locator('[data-tour="action-buttons"]').getByRole('button', { name: 'Edit', exact: true }).click()
  const modal = page.locator('.card').filter({ hasText: /Step \d \/ 4/ }).first()
  await expect(modal.getByText('Step 1 / 4')).toBeVisible()
  for (let s = 1; s < step; s++) {
    await continueButton(modal).click()
    await expect(modal.getByText(`Step ${s + 1} / 4`)).toBeVisible()
  }
  return modal
}

export function saveChangesButton(modal: Locator): Locator {
  return modal.getByRole('button', { name: 'Save Changes' })
}
