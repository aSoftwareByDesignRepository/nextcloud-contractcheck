// @ts-check
/**
 * ATLAS_UI_INVARIANTS — shared-contract coverage for ContractCheck web
 * surfaces. Wires nextcloud/apps/_shared/e2e/atlas-ui-invariants.js onto
 * representative surfaces with a unique `ccui-` marked party fixture
 * (created + archived by this spec — no shared fixture mutation):
 *   a11y-dom sweep, console errors, raw i18n keys, O(1) list requests,
 *   stored-xss via party fields, mutation→surface freshness,
 *   double-submit guard, form-survival-on-5xx.
 */
import { test, expect } from '@playwright/test'
import { createRequire } from 'node:module'
import { login, credsFromEnv, adminCreds } from './helpers/auth.js'
import { apiPost, apiCall } from './helpers/api.js'

const require = createRequire(import.meta.url)
const {
	ATLAS_XSS_PAYLOADS,
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoDuplicateSubmit,
	assertNoInjection,
	assertNoRawI18nKeys,
	assertSurfaceFresh,
	assertFormSurvivesFailure,
	countApiRequests,
	trackConsoleErrors,
} = require('../../_shared/e2e/atlas-ui-invariants.js')

const CONTENT = '#cc-main-content'
const MARK = `ccui-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`
// Mutating legs need a contractcheck manager; NC_ADMIN_* is in the admin
// group, while NC_E2E_* is deliberately a viewer (adminCreds prefers E2E).
const creds = credsFromEnv('ADMIN') || adminCreds()

test.describe('ContractCheck UI invariants (atlas-ui-invariants)', () => {
	test.describe.configure({ mode: 'serial' })
	test.skip(!creds, 'Requires NC_E2E_*/NC_ADMIN_* credentials')
	test.beforeEach(async ({ page }) => {
		await login(page, creds)
		await page.setViewportSize({ width: 1280, height: 800 })
	})

	test.afterAll(async ({ browser }) => {
		// Tear down every party this run created (xss + freshness + dblsubmit
		// legs) — MARK is run-unique, so a search sweep finds them all. Leaving
		// them active would leak orphans into shared list captures.
		const page = await browser.newPage()
		try {
			await login(page, creds)
			await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
			const list = await apiCall(page, 'GET', `/api/parties?search=${encodeURIComponent(MARK)}&includeArchived=1`)
			for (const p of list.json?.data || []) {
				if (String(p.name || '').includes(MARK)) {
					await apiCall(page, 'POST', `/api/parties/${p.id}/archive`).catch(() => {})
				}
			}
		} finally {
			await page.close()
		}
	})

	for (const [label, path] of [
		['contracts', '/apps/contractcheck/contracts'],
		['parties', '/apps/contractcheck/parties'],
		['settings access', '/apps/contractcheck/settings/access'],
	]) {
		test(`a11y-dom sweep /${label}`, async ({ page }) => {
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			await page.waitForLoadState('networkidle').catch(() => {})
			const findings = await assertA11yDom(page, {
				content: CONTENT,
				// .cc-check label wraps its <input> — the label (≥44px) is the
				// conforming target; the bare input rect is not the hit surface.
				// Deliberate exemption — do not allowlist anything else here.
				sizeAllow: '.cc-check input',
			})
			expect(findings, `a11y-dom findings on ${path}:\n${findings.join('\n')}`).toEqual([])
		})

		test(`console errors + raw i18n keys /${label}`, async ({ page }) => {
			const errs = trackConsoleErrors(page)
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			await page.waitForLoadState('networkidle').catch(() => {})
			assertNoConsoleErrors(errs, { allow: [/favicon/i] })
			await assertNoRawI18nKeys(page, { content: CONTENT })
		})
	}

	test('contracts list render is O(1) on API requests', async ({ page }) => {
		const calls = await countApiRequests(page, async () => {
			await page.goto('/apps/contractcheck/contracts', { waitUntil: 'domcontentloaded' })
			await page.waitForLoadState('networkidle').catch(() => {})
			await page.waitForSelector('#cc-contracts-loading', { state: 'hidden', timeout: 15_000 }).catch(() => {})
		}, /\/apps\/contractcheck\/api\//)
		expect(
			calls.length,
			`contracts list fired ${calls.length} API calls: ${calls.map((c) => c.url).join(', ')}`,
		).toBeLessThanOrEqual(4)
	})

	test('stored-xss: party fields render escaped on /parties', async ({ page }) => {
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		await apiPost(page, '/api/parties', {
			name: `${MARK} ${ATLAS_XSS_PAYLOADS[0]}`,
			contactName: ATLAS_XSS_PAYLOADS[1],
			contactEmail: `ccui-${MARK}@example.invalid`,
		})

		await page.goto('/apps/contractcheck/parties', { waitUntil: 'domcontentloaded' })
		await page.locator('#cc-party-search').fill(MARK)
		await expect(page.locator('#cc-parties-body')).toContainText(MARK, { timeout: 15_000 })
		await assertNoInjection(page)
	})

	test('mutation freshness: party created via dialog appears without reload', async ({ page }) => {
		const name = `${MARK} freshness`
		await page.goto('/apps/contractcheck/parties', { waitUntil: 'domcontentloaded' })
		await assertSurfaceFresh(page, {
			content: '#cc-parties-body',
			mutate: async () => {
				await page.locator('#cc-party-new').click()
				await expect(page.locator('#cc-party-dialog[open]')).toBeVisible()
				await page.locator('#cc-p-name').fill(name)
				await page.locator('#cc-party-form button[type="submit"]').click()
				await expect(page.locator('#cc-party-dialog[open]')).toBeHidden({ timeout: 15_000 })
			},
			visit: async () => { /* in-place list refresh — stay on the surface */ },
			expect: { present: name },
		})
		// clean up the row so repeated runs don't accumulate entries
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		const list = await page.evaluate(async (term) => {
			const token = window.OC?.requestToken || ''
			const res = await fetch(`${OC.webroot}/apps/contractcheck/api/parties?search=${encodeURIComponent(term)}`, {
				headers: { 'OCS-APIRequest': 'true', requesttoken: token },
			})
			return (await res.json()).data || []
		}, name)
		for (const p of list) {
			if (p.name === name) await apiCall(page, 'POST', `/api/parties/${p.id}/archive`)
		}
	})

	test('double-submit: party dialog second click cannot duplicate the write', async ({ page }) => {
		const name = `${MARK} dblsubmit`
		await page.goto('/apps/contractcheck/parties', { waitUntil: 'domcontentloaded' })
		await page.locator('#cc-party-new').click()
		await expect(page.locator('#cc-party-dialog[open]')).toBeVisible()
		await page.locator('#cc-p-name').fill(name)

		await assertNoDuplicateSubmit(page, {
			mutatingUrl: '/apps/contractcheck/api/parties',
			method: 'POST',
			// Tolerant click: on a fast first submit the dialog closes before the
			// helper's second click lands — a hidden button is the desired outcome,
			// not a failure. The postcondition below still demands exactly one row.
			submit: () => page.locator('#cc-party-form button[type="submit"]')
				.click({ timeout: 5_000 }).catch(() => {}),
		})
		await page.locator('#cc-party-dialog[open]').waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {})
		// Durable postcondition: exactly one row persisted for the unique name.
		const list = await apiCall(page, 'GET', `/api/parties?search=${encodeURIComponent(name)}`)
		const matches = (list.json?.data || []).filter((p) => p.name === name)
		expect(matches.length, `expected 1 persisted party named "${name}", got ${matches.length}`).toBe(1)
	})

	test('form survives a 5xx: party dialog keeps input + renders error', async ({ page }) => {
		const name = `${MARK} survive5xx`
		await page.goto('/apps/contractcheck/parties', { waitUntil: 'domcontentloaded' })
		await page.locator('#cc-party-new').click()
		await expect(page.locator('#cc-party-dialog[open]')).toBeVisible()

		await assertFormSurvivesFailure(page, {
			failUrl: '/apps/contractcheck/api/parties',
			method: 'POST',
			fields: { '#cc-p-name': name, '#cc-p-contact': 'Ada' },
			submit: () => page.locator('#cc-party-form button[type="submit"]').click(),
			// #cc-alert-region (sr-only live region) also matches [role=alert] —
			// pin the *visible* error toast so the locator stays strict-unique.
			errorSel: '.cc-toast--error',
		})
	})
})
