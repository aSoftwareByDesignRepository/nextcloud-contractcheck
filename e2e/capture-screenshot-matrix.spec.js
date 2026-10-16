// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/contractcheck/craft/web/. The companion
 * gate scripts/check-screenshot-matrix.py verifies completeness — a declared
 * matrix cell that produces no artifact fails the ready-gate craft stage.
 *
 * States:
 *   populated — seeded data visible (party + active contract + cost row,
 *               audit events from the same seeding)
 *   empty     — deterministic empty render: a no-match list search /
 *               a date range filter outside all seeded events
 *   current   — state-invariant chrome (forms, settings sections, import)
 *
 * Extra craft (beyond matrix cells): the party dialog, form field-error
 * states, the shared CC.confirmDialog on a destructive action, a
 * light-highcontrast variant and the viewer role's limited surfaces — all
 * PNGs land in the same outDir and are recorded in _manifest-ds-chrome.json.
 *
 * A dedicated throwaway manager is provisioned for the run: theme flips are
 * per-user settings and must never touch the shared e2e/admin fixtures.
 *
 * Run:
 *   npx playwright test e2e/capture-screenshot-matrix.spec.js
 */
import { test, expect } from '@playwright/test'
import { existsSync, mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login } from './helpers/auth.js'
import { apiPost, apiCall, archiveContract } from './helpers/api.js'
import { provisionManager, deprovisionUser, setUserTheme } from './helpers/theme.js'

const HERE = dirname(fileURLToPath(import.meta.url))
const MANIFEST = JSON.parse(readFileSync(join(HERE, 'screenshot-matrix.json'), 'utf8'))
const OUT = resolve(HERE, '../../../../.cursor/atlas-farm-v3/artifacts/contractcheck', MANIFEST.outDir)
const APP = '/apps/contractcheck'
const TS = Date.now()
const NO_MATCH = 'zz-nomatch-9x7q'

/** @type {{ contractId: number, partyId: number }} */
const seed = { contractId: 0, partyId: 0 }

/**
 * `#app-content` scrolls internally — a viewport-height shot only captures
 * the fold. Grow the viewport to full content height before shooting.
 */
async function shot(/** @type {import('@playwright/test').Page} */ page, /** @type {string} */ name, /** @type {number} */ vpHeight) {
	const contentH = await page.evaluate(() =>
		(document.querySelector('#app-content') || document.documentElement).scrollHeight)
	const shotH = Math.min(Math.max(contentH + 60, vpHeight), 8192)
	if (shotH !== vpHeight) {
		await page.setViewportSize({ width: page.viewportSize()?.width || 1280, height: shotH })
		await page.waitForTimeout(150)
	}
	const file = join(OUT, `${name}.png`)
	await page.screenshot({ path: file })
	const size = existsSync(file) ? statSync(file).size : 0
	expect(size, `${name}.png too small — likely blank/error frame`).toBeGreaterThan(4096)
}

async function gotoSurface(/** @type {import('@playwright/test').Page} */ page, /** @type {string} */ path) {
	await page.goto(`${APP}${path}`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#cc-main-content', { timeout: 30_000 })
	await page.waitForLoadState('networkidle').catch(() => {})
	// Async list/detail pages resolve their payloads after first paint.
	await page.locator('.cc-loading:visible').first()
		.waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {})
}

/**
 * Reach the deterministic "empty" render on JS-bound filters: a no-match
 * search text on list filters, an out-of-range date window on the audit log.
 */
async function applyEmptyState(/** @type {import('@playwright/test').Page} */ page, /** @type {string} */ surfaceId) {
	if (surfaceId === 'contracts') {
		await page.locator('#cc-contract-search').fill(NO_MATCH)
		await expect(page.locator('#cc-contracts-empty')).toBeVisible({ timeout: 15_000 })
	} else if (surfaceId === 'parties') {
		await page.locator('#cc-party-search').fill(NO_MATCH)
		await expect(page.locator('#cc-parties-empty')).toBeVisible({ timeout: 15_000 })
	} else if (surfaceId === 'audit') {
		await page.locator('#cc-audit-from').fill('1999-01-01')
		await page.locator('#cc-audit-to').fill('1999-12-31')
		await expect(page.locator('#cc-audit-empty')).toBeVisible({ timeout: 15_000 })
	}
}

function resolvePath(/** @type {string} */ p) {
	return p.replace('{contractId}', String(seed.contractId))
}

function shotName(/** @type {string} */ sid, /** @type {string} */ state, /** @type {string} */ vpName, /** @type {string} */ theme) {
	return `${MANIFEST.prefix}-${sid}-${state}-${vpName}${theme === 'dark' ? '-dark' : ''}`
}

function* matrixCells(/** @type {string} */ theme) {
	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states) {
			for (const vp of MANIFEST.viewports) {
				if (theme === 'dark' && (!surface.dark || vp.name !== 'desktop' || state !== surface.states[0])) { continue }
				yield { surface, state, vp }
			}
		}
	}
}

// Traces off: the PNGs are the artifacts and parallel trace recording has
// raced on context.close before. (Must be top-level — describe-scope forces
// a new worker.)
test.use({ trace: 'off' })

test.describe('SCREENSHOT_MATRIX capture', () => {
	test.setTimeout(600_000)
	// Captures set every viewport per cell — project choice is irrelevant, so
	// run the whole spec once (chromium-1280). Without this guard a full-suite
	// `npx playwright test` triple-captures the same filenames and each
	// project's afterAll races the manifest write.
	test.beforeEach(async ({}, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'capture runs once — viewports are set per cell')
	})
	let mgr

	test.beforeAll(async ({ browser }, testInfo) => {
		if (testInfo.project.name !== 'chromium-1280') { return }
		mgr = provisionManager()
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		try {
			await login(page, { username: mgr.uid, password: mgr.password })
			await gotoSurface(page, '/')

			const party = await apiPost(page, '/api/parties', {
				name: `MX ${TS} Telekommunikation GmbH`, contactName: 'Ada Beispiel',
				contactEmail: 'ada@example.invalid',
			})
			seed.partyId = party.id

			const contract = await apiPost(page, '/api/contracts', {
				title: `MX ${TS} Büro-Mietvertrag`, type: 'rent', scope: 'org',
				status: 'draft', partnerId: seed.partyId,
				refNo: `MX-${TS}`, costCenter: `MX-${TS}`,
				startDate: '2026-01-01', endDate: '2028-12-31',
				termKind: 'fixed', renewalValue: 12, renewalUnit: 'months',
				noticeValue: 3, noticeUnit: 'months', noticeAnchor: 'contract_end',
				leadDays: [30, 7], notes: 'Matrix seed — archived after capture.',
			})
			seed.contractId = contract.id

			await apiPost(page, `/api/contracts/${seed.contractId}/costs`, {
				label: 'Monatliche Miete', amountCents: 120000, currency: 'EUR',
				interval: 'monthly', validFrom: '2026-01-01',
			})
			await apiPost(page, `/api/contracts/${seed.contractId}/transition`, {
				action: 'activate', revision: contract.revision,
			})
		} finally {
			await ctx.close()
		}
	})

	test.afterAll(async ({ browser }, testInfo) => {
		if (testInfo.project.name !== 'chromium-1280' || !mgr) { return }
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		try {
			await login(page, { username: mgr.uid, password: mgr.password })
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
			if (seed.contractId) {
				await archiveContract(page, seed.contractId).catch(() => {})
			}
			if (seed.partyId) {
				await apiCall(page, 'POST', `/api/parties/${seed.partyId}/archive`).catch(() => {})
			}
		} finally {
			await ctx.close()
			deprovisionUser(mgr?.uid)
		}
	})

	test('captures every manifest cell', async ({ page }) => {
		mkdirSync(OUT, { recursive: true })
		await login(page, { username: mgr.uid, password: mgr.password })

		for (const theme of ['light', 'dark']) {
			if (theme === 'dark') {
				setUserTheme(mgr.uid, '["dark"]')
			}
			for (const { surface, state, vp } of matrixCells(theme)) {
				await page.setViewportSize({ width: vp.width, height: vp.height })
				await gotoSurface(page, resolvePath(surface.path))
				if (state === 'empty') { await applyEmptyState(page, surface.id) }
				await page.waitForTimeout(350)
				await shot(page, shotName(surface.id, state, vp.name, theme), vp.height)
			}
		}
	})

	test('captures dialogs, forms, error states and theme variants', async ({ page }) => {
		test.setTimeout(300_000)
		// The matrix loop ends on dark cells — reset to the default theme so
		// these extra craft PNGs are real light-theme renders, not mislabeled.
		setUserTheme(mgr.uid, '[]')
		await login(page, { username: mgr.uid, password: mgr.password })
		await page.setViewportSize({ width: 1280, height: 800 })

		// ── Party dialog (create) + field-error state ──
		await gotoSurface(page, '/parties')
		await page.locator('#cc-party-new').click()
		await expect(page.locator('#cc-party-dialog[open]')).toBeVisible()
		await shot(page, 'cck-party-dialog-desktop', 800)
		await page.locator('#cc-party-form button[type="submit"]').click()
		await expect(page.locator('#cc-p-name[aria-invalid="true"], #cc-party-form [aria-invalid="true"]').first()).toBeVisible({ timeout: 10_000 })
		await shot(page, 'cck-party-dialog-errors-desktop', 800)
		await page.locator('#cc-p-cancel').click()
		await expect(page.locator('#cc-party-dialog[open]')).toBeHidden()

		// ── Contract create form + validation errors ──
		await gotoSurface(page, '/contracts/new')
		await expect(page.locator('#cc-contract-form')).toBeVisible()
		await shot(page, 'cck-contract-form-desktop', 800)
		await page.locator('#cc-form-save').click()
		// Either native validity or app field-errors must paint — assert the
		// app-level marker so the capture is a real error state.
		await expect(page.locator('#cc-contract-form [aria-invalid="true"], #cc-f-title:invalid').first()).toBeVisible({ timeout: 10_000 })
		await shot(page, 'cck-contract-form-errors-desktop', 800)

		// ── Shared CC.confirmDialog on a destructive transition ──
		await gotoSurface(page, `/contracts/${seed.contractId}`)
		await expect(page.locator('#cc-detail-loading')).toBeHidden({ timeout: 15_000 })
		// Action order: Edit (link) → Terminate → Archive — the last button is
		// the destructive-with-confirm one.
		await page.locator('#cc-detail-actions button').last().click()
		await expect(page.locator('dialog.cc-dialog.confirm-dialog[open]')).toBeVisible({ timeout: 10_000 })
		await shot(page, 'cck-confirm-dialog-desktop', 800)
		await page.locator('dialog.cc-dialog.confirm-dialog .cc-btn--secondary').click()
		await expect(page.locator('dialog.cc-dialog.confirm-dialog[open]')).toBeHidden()

		// ── High-contrast variant (matrix covers dark at desktop) ──
		setUserTheme(mgr.uid, '["light-highcontrast"]')
		await gotoSurface(page, '/dashboard')
		await shot(page, 'cck-dashboard-current-desktop-hc', 800)
		await gotoSurface(page, '/contracts')
		await shot(page, 'cck-contracts-populated-desktop-hc', 800)
		setUserTheme(mgr.uid, '[]')
	})

	test('captures the viewer role surface', async ({ browser }) => {
		// The E2E fixture user is a viewer (not a manager): manager-only
		// chrome (new-contract CTA, manager settings sections) must not render.
		const e2eUser = process.env.NC_E2E_USER
		const e2ePass = process.env.NC_E2E_PASS
		test.skip(!e2eUser || !e2ePass, 'Requires NC_E2E_* credentials')
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		try {
			await login(page, { username: e2eUser, password: e2ePass })
			await page.setViewportSize({ width: 1280, height: 800 })
			await gotoSurface(page, '/contracts')
			await shot(page, 'cck-viewer-contracts-desktop', 800)
			await gotoSurface(page, '/settings/feed')
			await shot(page, 'cck-viewer-settings-feed-desktop', 800)
		} finally {
			await ctx.close()
		}
	})

	test.afterAll(async () => {
		// Manifest = what is actually on disk. Tests of this file may be split
		// across workers, so `written` only covers the last test's shots — a
		// directory scan stays complete regardless of worker scheduling.
		if (!existsSync(OUT)) { return }
		const files = readdirSync(OUT)
			.filter((f) => f.startsWith(`${MANIFEST.prefix}-`) && f.endsWith('.png'))
			.sort()
			.map((f) => ({ id: f.replace(/\.png$/, ''), path: `craft/web/${f}`, bytes: statSync(join(OUT, f)).size }))
		writeFileSync(join(OUT, '_manifest-ds-chrome.json'), JSON.stringify({
			app: 'contractcheck',
			lane: 'ds_chrome',
			captured_at: new Date().toISOString(),
			manifest: 'nextcloud/apps/contractcheck/e2e/screenshot-matrix.json',
			spec: 'nextcloud/apps/contractcheck/e2e/capture-screenshot-matrix.spec.js',
			files,
		}, null, 1) + '\n')
	})
})
