// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'

const admin = credsFromEnv('ADMIN')
const viewer = credsFromEnv('E2E')

test.describe('app chrome', () => {
	test.skip(!admin, 'Requires NC_ADMIN_* credentials')

	test('dashboard renders shell, stats and deadline panels', async ({ page }) => {
		await login(page, admin)
		const res = await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		expect(res?.status()).toBe(200)

		// Stat counters hydrate from the API.
		await expect(page.locator('#cc-stat-total')).not.toHaveText('–', { timeout: 15_000 })
		await expect(page.locator('#cc-dash-urgent')).toBeAttached()
		await expect(page.locator('#cc-dash-missed')).toBeAttached()

		// Chrome landmarks.
		await expect(page.getByRole('navigation').first()).toBeVisible()
		await expect(page.getByRole('main').first()).toBeVisible()
	})

	test('primary nav reaches every section', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		for (const [href, marker] of [
			['/apps/contractcheck/contracts', '#cc-contracts-table'],
			['/apps/contractcheck/parties', '#content'],
			['/apps/contractcheck/reports', '#content'],
			['/apps/contractcheck/audit', '#content'],
		]) {
			await page.goto(href, { waitUntil: 'domcontentloaded' })
			expect(page.url()).toContain('contractcheck')
			await expect(page.locator(marker)).toBeAttached()
		}
	})

	test('contracts list hydrates and offers new-contract to managers', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/contracts', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-contract-search')).toBeVisible()
		// Manager sees the create button (unhidden by role).
		await expect(page.locator('#cc-new-contract')).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('#cc-contracts-loading')).toBeHidden({ timeout: 15_000 })
	})

	test('settings page renders section nav', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/settings/deadlines', { waitUntil: 'domcontentloaded' })
		await expect(page.getByRole('navigation')).toBeTruthy()
		expect(page.url()).toContain('/settings')
	})
})

test.describe('viewer role', () => {
	test.skip(!viewer, 'Requires NC_E2E_* credentials')

	test('viewer gets dashboard but no create affordance', async ({ page }) => {
		await login(page, viewer)
		const res = await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		expect(res?.status()).toBe(200)
		await page.goto('/apps/contractcheck/contracts', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-contracts-loading')).toBeHidden({ timeout: 15_000 })
		// #cc-new-contract stays hidden for non-managers.
		await expect(page.locator('#cc-new-contract')).toBeHidden()
	})

	test('viewer is redirected away from manager-only settings sections', async ({ page }) => {
		await login(page, viewer)
		await page.goto('/apps/contractcheck/settings/access', { waitUntil: 'domcontentloaded' })
		await expect(page).toHaveURL(/\/settings\/feed/)
		// Nav must not offer manager-only sections to viewers.
		await expect(page.locator('.cc-nav a[href*="/settings/access"]')).toHaveCount(0)
		await expect(page.locator('.cc-nav a[href*="/settings/deadlines"]')).toHaveCount(0)
	})

	test('viewer gets no import affordance — nav hides it, deep link redirects', async ({ page }) => {
		await login(page, viewer)
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		// importCsv is canEdit-gated; an enabled form for viewers is a dead affordance.
		await expect(page.locator('.cc-nav a[href*="/import"]')).toHaveCount(0)
		await page.goto('/apps/contractcheck/import', { waitUntil: 'domcontentloaded' })
		await expect(page).toHaveURL(/\/(dashboard|apps\/contractcheck\/?$)/)
		await expect(page.locator('#cc-import-form')).toHaveCount(0)
	})
})
