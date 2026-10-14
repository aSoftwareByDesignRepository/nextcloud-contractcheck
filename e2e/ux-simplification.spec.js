// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { apiGet, apiPost, uniqueTitle, archiveContract } from './helpers/api.js'

const admin = credsFromEnv('ADMIN')

test.describe('ux simplification', () => {
	test.skip(!admin, 'Requires NC_ADMIN_* credentials')

	test('cost entry takes a decimal amount, not raw cents', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		const c = await apiPost(page, '/api/contracts', {
			title: uniqueTitle('CostUX'), type: 'rent', scope: 'org',
			status: 'active', startDate: '2026-01-01', endDate: '2028-12-31',
			termKind: 'fixed', noticeValue: 3, noticeUnit: 'months',
			noticeAnchor: 'contract_end', leadDays: [30],
		})

		await page.goto(`/apps/contractcheck/contracts/${c.id}`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-detail-loading')).toBeHidden({ timeout: 15_000 })

		// The amount field must NOT be named amountCents — users think in €, not cents.
		const amount = page.locator('#cc-cost-form [name="amount"]')
		await expect(amount).toBeVisible()
		await expect(page.locator('#cc-cost-form [name="amountCents"]')).toHaveCount(0)

		await page.fill('#cc-cost-form [name="label"]', 'Monthly rent')
		await amount.fill('49,90') // comma decimal — must parse
		await page.click('#cc-cost-form button[type="submit"]')
		await expect(page.locator('#cc-d-costs-body tr')).toHaveCount(1, { timeout: 15_000 })
		// Rendered as a formatted currency, not "4990".
		await expect(page.locator('#cc-d-costs-body')).toContainText(/49[.,]90/)
		await expect(page.locator('#cc-d-costs-body')).not.toContainText('4990')
		// Backend received integer cents.
		const costs = await apiGet(page, `/api/contracts/${c.id}/costs`)
		expect(costs[0].amountCents).toBe(4990)

		// Garbage input → inline field error, no request burned.
		await page.fill('#cc-cost-form [name="label"]', 'Bad')
		await amount.fill('not-a-number')
		await page.click('#cc-cost-form button[type="submit"]')
		await expect(page.locator('#cc-cost-form [name="amount"]')).toHaveAttribute('aria-invalid', 'true')
		const costs2 = await apiGet(page, `/api/contracts/${c.id}/costs`)
		expect(costs2).toHaveLength(1)

		await archiveContract(page, c.id)
	})

	test('reminders hide when cancellation is effective any time', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/contracts/new', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-contract-form')).toBeVisible()

		const anchor = page.locator('#cc-f-anchor')
		const reminders = page.locator('#cc-lead-days').locator('xpath=ancestor::fieldset')
		await expect(reminders).toBeVisible() // default anchor = contract_end

		await anchor.selectOption('anytime')
		await expect(reminders).toBeHidden()
		await anchor.selectOption('contract_end')
		await expect(reminders).toBeVisible()

		// indefinite term auto-switches anchor to anytime → reminders hidden too.
		await page.selectOption('#cc-f-termkind', 'indefinite')
		await expect(anchor).toHaveValue('anytime')
		await expect(reminders).toBeHidden()
	})

	test('dashboard stat tiles deep-link to a filtered contracts list', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-dash-counts')).toBeVisible({ timeout: 15_000 })

		await page.locator('a.cc-stat--danger').click() // missed windows
		await page.waitForURL(/\/contracts\?.*deadline=missed/, { timeout: 15_000 })
		await expect(page.locator('#cc-contract-deadline')).toHaveValue('missed')
	})

	test('contracts list honors URL filter params', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/contracts?status=archived', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-contract-status')).toHaveValue('archived')
	})

	test('import page offers a downloadable CSV template', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/import', { waitUntil: 'domcontentloaded' })
		const dl = page.waitForEvent('download', { timeout: 10_000 })
		await page.locator('#cc-import-template').click()
		const download = await dl
		expect(download.suggestedFilename()).toBe('contractcheck-import-template.csv')
		const path = await download.path()
		const fs = await import('fs')
		const text = fs.readFileSync(path, 'utf8')
		expect(text).toContain('title,type,partner,start,end,term,renewal,notice,anchor')
	})
})
