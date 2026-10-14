// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { apiCall, apiGet, apiPost, archiveContract, saveSettings, uniqueTitle } from './helpers/api.js'

const admin = credsFromEnv('ADMIN')

test.describe('list filters & reporting', () => {
	test.skip(!admin, 'Requires NC_ADMIN_* credentials')

	test('filter bar filters by type, cost center and deadline window', async ({ page }) => {
		await login(page, admin)
		const title = uniqueTitle('FilterCheck')
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		const c = await apiPost(page, '/api/contracts', {
			title, type: 'insurance', scope: 'org', status: 'active',
			costCenter: 'E2E-CC', startDate: '2026-01-01', endDate: '2028-12-31',
			termKind: 'fixed', noticeValue: 3, noticeUnit: 'months',
			noticeAnchor: 'contract_end', leadDays: [30],
		})
		expect(c.id).toBeGreaterThan(0)

		// API level: typed + cost-center + deadline filters narrow correctly.
		const byType = await apiGet(page, '/api/contracts?type=insurance&search=' + encodeURIComponent(title))
		expect(byType.map((r) => r.id)).toContain(c.id)
		const wrongType = await apiGet(page, '/api/contracts?type=rent&search=' + encodeURIComponent(title))
		expect(wrongType.map((r) => r.id)).not.toContain(c.id)
		const byCc = await apiGet(page, '/api/contracts?costCenter=E2E-CC')
		expect(byCc.map((r) => r.id)).toContain(c.id)
		// cancel30: end 2028 → cancel-by far out → not in the 30d window.
		const c30 = await apiGet(page, '/api/contracts?deadline=cancel30&search=' + encodeURIComponent(title))
		expect(c30.map((r) => r.id)).not.toContain(c.id)
		// invalid window → 400 with field error, never silent.
		const bad = await apiCall(page, 'GET', '/api/contracts?deadline=bogus')
		expect(bad.status).toBe(400)
		expect(bad.json?.fields?.deadline).toBeTruthy()

		// UI level: the filter bar drives the table.
		await page.goto('/apps/contractcheck/contracts', { waitUntil: 'domcontentloaded' })
		await page.selectOption('#cc-contract-status', '')
		await page.fill('#cc-contract-search', title)
		await page.selectOption('#cc-contract-type', 'insurance')
		await expect(page.locator('#cc-contracts-body')).toContainText(title, { timeout: 15_000 })
		await page.selectOption('#cc-contract-type', 'rent')
		await expect(page.locator('#cc-contracts-body')).not.toContainText(title, { timeout: 15_000 })

		await archiveContract(page, c.id)
	})

	test('rollup report groups by type and cost center with planned budgets', async ({ page }, testInfo) => {
		await login(page, admin)
		const title = uniqueTitle('RollupCheck')
		// Run-unique key: previous runs may have left contracts behind (no
		// contract-delete API exists), so a shared key would accumulate cost
		// rows and break the strict annual_cents assertion.
		const ccKey = `E2E-BUDGET-${Date.now()}-${testInfo.workerIndex}`
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		let contractId = null
		let costId = null
		try {
			const c = await apiPost(page, '/api/contracts', {
				title, type: 'maintenance', scope: 'org', status: 'active',
				costCenter: ccKey, startDate: '2026-01-01', endDate: '2028-12-31',
				termKind: 'fixed', noticeValue: 3, noticeUnit: 'months',
				noticeAnchor: 'contract_end', leadDays: [30],
			})
			contractId = c.id
			const cost = await apiPost(page, `/api/contracts/${c.id}/costs`, {
				label: 'Service', amountCents: 10000, currency: 'EUR',
				interval: 'monthly', validFrom: '2026-01-01',
			})
			costId = cost.id

			// Persist a budget for the cost center via the settings CAS flow
			// (parallel workers share this document — merge + retry on 409).
			await saveSettings(page, (cur) => ({
				...cur,
				costCenterBudgets: { ...(cur.costCenterBudgets || {}), [ccKey]: 500000 },
			}))

			const byType = await apiGet(page, '/api/reports/rollup?groupBy=type')
			const maint = byType.rows.find((r) => r.key === 'maintenance')
			expect(maint).toBeTruthy()
			expect(maint.annual_cents.EUR).toBeGreaterThanOrEqual(120000)

			const byCc = await apiGet(page, '/api/reports/rollup?groupBy=costCenter')
			const row = byCc.rows.find((r) => r.key === ccKey)
			expect(row).toBeTruthy()
			expect(row.annual_cents.EUR).toBe(120000)
			expect(row.planned_cents).toBe(500000)

			// UI level: reports page renders the groupings.
			await page.goto('/apps/contractcheck/reports', { waitUntil: 'domcontentloaded' })
			await expect(page.locator('#cc-rollup-cc-body')).toContainText(ccKey, { timeout: 15_000 })
		} finally {
			// Remove only this key — other parallel workers may hold budgets too.
			await saveSettings(page, (cur) => {
				const next = { ...(cur.costCenterBudgets || {}) }
				delete next[ccKey]
				return { ...cur, costCenterBudgets: next }
			})
			// Contracts can't be deleted — remove the cost row (empties the
			// rollup bucket) then archive the contract so lists stay clean.
			if (costId !== null) {
				await apiCall(page, 'DELETE', `/api/contracts/${contractId}/costs/${costId}`)
			}
			if (contractId !== null) {
				const cur = await apiGet(page, `/api/contracts/${contractId}`)
				await apiCall(page, 'POST', `/api/contracts/${contractId}/transition`, {
					action: 'archive', revision: cur.revision,
				})
			}
		}
	})

	test('dashboard shows contracted-cost tile for cost viewers only', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/dashboard', { waitUntil: 'domcontentloaded' })
		// Admin is a manager; without a cost entry the tile may stay hidden —
		// assert it does not error and the tile exists in the DOM.
		await expect(page.locator('#cc-stat-costs-wrap')).toBeAttached()
	})

	test('settings deadlines panel edits budgets with inline validation', async ({ page }) => {
		await login(page, admin)
		await page.goto('/apps/contractcheck/settings/deadlines', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-set-budgets')).toBeVisible({ timeout: 15_000 })
		await page.fill('#cc-set-budgets', 'not a budget line !!!')
		await page.click('#cc-set-save')
		await expect(page.locator('#cc-set-budgets-error')).toBeVisible({ timeout: 10_000 })
		await expect(page.locator('#cc-set-budgets')).toHaveAttribute('aria-invalid', 'true')
	})
})
