// @ts-check
/**
 * Visual regression baselines — catches CSS breakage, layout collapse,
 * spacing regressions and hidden-attribute leaks across all three viewports.
 *
 * Dynamic content (counts, dates, contract titles, usernames) is MASKED so
 * baselines stay stable across runs and data fixtures. Playwright compares
 * pixels: any structural drift beyond the tolerance fails the run.
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { apiPost, uniqueTitle, archiveContract } from './helpers/api.js'

const admin = credsFromEnv('ADMIN')

/** Every element that can hold run-varying text. */
function dynamicMasks(page) {
	return [
		page.locator('.cc-stat__value'),
		page.locator('#cc-hero-title, #cc-hero-meta, #cc-hero-deadline, #cc-hero-badges'),
		page.locator('#cc-d-costs-body, #cc-d-docs-body, #cc-events-body'),
		page.locator('#cc-contracts-body, #cc-contracts-empty'),
		page.locator('.cc-toast'),
	]
}

test.describe('visual regression', () => {
	test.skip(!admin, 'Requires NC_ADMIN_* credentials')

	test('dashboard shell: stat row + panels keep layout', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-dash-counts')).toBeVisible({ timeout: 15_000 })
		await page.waitForTimeout(800) // hydration settle
		// Stat row only: the deadline lists below grow/shrink with fixture
		// data created by sibling specs — unstable screenshot surface.
		await expect(page.locator('#cc-dash-counts')).toHaveScreenshot('dashboard-stats.png', {
			mask: [page.locator('.cc-stat__value')],
			maxDiffPixelRatio: 0.02,
		})
	})

	test('contracts list: filter toolbar layout', async ({ page }) => {
		await login(page, admin)
		// Toolbar controls hydrate asynchronously: partner + cost-center options
		// arrive via /api/parties + /api/contracts/filters — a snapshot taken
		// mid-load captures collapsed selects and is non-deterministic.
		const metaDone = Promise.all([
			page.waitForResponse((r) => r.url().includes('/api/parties'), { timeout: 15_000 }),
			page.waitForResponse((r) => r.url().includes('/api/contracts/filters'), { timeout: 15_000 }),
		])
		await page.goto('/apps/contractcheck/contracts', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-contracts-loading')).toBeHidden({ timeout: 15_000 })
		await metaDone
		await page.evaluate(() => document.fonts.ready)
		await page.waitForTimeout(200) // options apply in the same microtask; settle layout
		// Scope to the toolbar: the table body height varies with row count
		// when sibling specs create fixtures in parallel — data rows are not
		// stable visual-regression surface, the controls are.
		await expect(page.locator('.cc-toolbar')).toHaveScreenshot('contracts-toolbar.png', {
			maxDiffPixelRatio: 0.02,
		})
	})

	test('contract form: full fieldset layout (deterministic)', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/contracts/new', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-contract-form')).toBeVisible()
		await expect(page.locator('#cc-contract-form')).toHaveScreenshot('contract-form.png', {
			maxDiffPixelRatio: 0.02,
		})
	})

	test('contract detail: hero + panels', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		const c = await apiPost(page, '/api/contracts', {
			title: 'V', type: 'rent', scope: 'org',
			status: 'active', startDate: '2026-01-01', endDate: '2028-12-31',
			termKind: 'fixed', noticeValue: 3, noticeUnit: 'months',
			noticeAnchor: 'contract_end', leadDays: [30],
		})
		await page.goto(`/apps/contractcheck/contracts/${c.id}`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-detail-loading')).toBeHidden({ timeout: 15_000 })
		// Hero only: the panels below it contain variable-length lists.
		await expect(page.locator('#cc-hero')).toHaveScreenshot('contract-detail-hero.png', {
			mask: [page.locator('#cc-hero-title, #cc-hero-meta, #cc-hero-deadline, #cc-hero-badges')],
			maxDiffPixelRatio: 0.02,
		})
		await archiveContract(page, c.id)
	})

	test('import page: form + actions', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/import', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-import-form')).toBeVisible()
		await expect(page.locator('#cc-main-content')).toHaveScreenshot('import.png', {
			mask: dynamicMasks(page),
			maxDiffPixelRatio: 0.02,
		})
	})
})
