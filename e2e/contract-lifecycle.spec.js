// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { apiCall, apiPost, uniqueTitle, archiveContract } from './helpers/api.js'

const admin = credsFromEnv('ADMIN')
const viewer = credsFromEnv('E2E')

test.describe('contract lifecycle', () => {
	test.skip(!admin, 'Requires NC_ADMIN_* credentials')

	test('create → activate → deadline chip → edit → CAS conflict', async ({ page }) => {
		await login(page, admin)
		const title = uniqueTitle()

		// --- Create via the real form ---
		await page.goto('/apps/contractcheck/contracts/new', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-contract-form')).toBeVisible()
		await page.fill('#cc-f-title', title)
		await page.fill('#cc-f-start', '2026-01-01')
		await page.fill('#cc-f-end', '2028-12-31')
		await page.selectOption('#cc-f-notice-unit', 'months')
		await page.fill('#cc-f-notice-value', '3')
		await page.click('#cc-form-save')

		// Redirected to the detail page.
		await page.waitForURL(/\/contracts\/\d+/, { timeout: 20_000 })
		const detailUrl = page.url()
		await expect(page.locator('#cc-detail-loading')).toBeHidden({ timeout: 15_000 })
		await expect(page.locator('#cc-hero-title')).toHaveText(title)

		// --- Activate (draft → active transition) ---
		await page.getByRole('button', { name: 'Activate' }).click()
		await expect(page.locator('#cc-hero-badges')).toContainText(/active/i, { timeout: 15_000 })
		// Deadline hero renders the computed cancel-by.
		await expect(page.locator('#cc-hero-deadline')).not.toBeEmpty()

		// --- Edit round-trip (CAS succeeds on fresh revision) ---
		await page.locator('#cc-detail-actions a[href$="/edit"]').click()
		await page.waitForURL(/\/edit$/, { timeout: 15_000 })
		await expect(page.locator('#cc-f-title')).toHaveValue(title, { timeout: 15_000 })
		await page.fill('#cc-f-title', `${title} v2`)
		await page.click('#cc-form-save')
		await page.waitForURL(/\/contracts\/\d+$/, { timeout: 20_000 })
		await expect(page.locator('#cc-hero-title')).toHaveText(`${title} v2`)

		// --- CAS conflict: stale revision must not silently overwrite ---
		const id = detailUrl.match(/\/contracts\/(\d+)/)?.[1]
		const stale = await apiCall(page, 'PUT', `/api/contracts/${id}`, {
			revision: 0, title: 'stale write',
		})
		expect(stale.status).toBe(409)
		expect(stale.json?.success).toBe(false)

		// --- Terminate via shared confirm dialog: open → Escape keeps state → confirm ---
		// Structural selector: the only danger-class action on an active contract.
		await page.locator('#cc-detail-actions button.cc-btn--danger').click()
		const dlg = page.locator('dialog.cc-dialog.confirm-dialog[open]')
		await expect(dlg).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(dlg).toBeHidden()
		await expect(page.locator('#cc-hero-badges')).toContainText(/active/i)
		// Re-open and confirm this time.
		await page.locator('#cc-detail-actions button.cc-btn--danger').click()
		await expect(dlg).toBeVisible()
		await dlg.locator('.cc-btn--danger').click()
		await expect(dlg).toBeHidden()
		await expect(page.locator('#cc-hero-badges')).toContainText(/terminated/i, { timeout: 15_000 })

		await archiveContract(page, id)
	})

	test('uniform 404 for out-of-scope and missing contracts', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		const missing = await apiCall(page, 'GET', '/api/contracts/999999')
		expect(missing.status).toBe(404)
		expect(missing.json).toMatchObject({ success: false, error: 'NOT_FOUND' })
	})

	test('cost visibility: manager sees cost panel, viewer does not', async ({ page, browser }) => {
		test.skip(!viewer, 'Requires NC_E2E_* credentials')
		await login(page, admin)
		const title = uniqueTitle('CostGate')
		// Seed an active contract with a cost entry via API.
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		const c = await apiPost(page, '/api/contracts', {
			title, type: 'rent', scope: 'org', status: 'draft',
			startDate: '2026-01-01', endDate: '2028-12-31',
			termKind: 'fixed', noticeValue: 3, noticeUnit: 'months',
			noticeAnchor: 'contract_end', leadDays: [30],
		})
		const cost = await apiPost(page, `/api/contracts/${c.id}/costs`, {
			label: 'Monthly rent', amountCents: 120000, currency: 'EUR',
			interval: 'monthly', validFrom: '2026-01-01',
		})

		// Manager sees the cost panel + rollup.
		await page.goto(`/apps/contractcheck/contracts/${c.id}`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-detail-loading')).toBeHidden({ timeout: 15_000 })
		await expect(page.locator('#cc-d-costs-panel')).toBeVisible()
		await expect(page.locator('#cc-d-costs-body')).toContainText('Monthly rent')

		// Viewer sees the contract but NO cost data anywhere in the payload/DOM.
		const ctx2 = await browser.newContext()
		const page2 = await ctx2.newPage()
		await login(page2, viewer)
		await page2.goto(`/apps/contractcheck/contracts/${c.id}`, { waitUntil: 'domcontentloaded' })
		await expect(page2.locator('#cc-detail-loading')).toBeHidden({ timeout: 15_000 })
		await expect(page2.locator('#cc-hero-title')).toHaveText(title)
		await expect(page2.locator('#cc-d-costs-panel')).toBeHidden()
		await expect(page2.locator('#cc-d-costs-body')).toBeEmpty()
		// And the API itself refuses to leak.
		const costs = await apiCall(page2, 'GET', `/api/contracts/${c.id}/costs`)
		expect([403, 404]).toContain(costs.status)
		await ctx2.close()

		await apiCall(page, 'DELETE', `/api/contracts/${c.id}/costs/${cost.id}`)
		await archiveContract(page, c.id)
	})
})
