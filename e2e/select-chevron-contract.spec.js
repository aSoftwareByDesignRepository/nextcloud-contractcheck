// @ts-check
/**
 * Rendered regression proof for the 2026-11-04 farm class-gate sweep
 * (required_field_or_step_undiscoverable / check-select-background-shorthand.py):
 * the shared `#app-content.cc-app .cc-input/.cc-select/.cc-textarea` rule in
 * css/app.css painted the bare `background:` shorthand, which resets
 * background-image/position/repeat/size to initial — the select loses its
 * picker cue and reads as a plain text field.
 *
 * ContractCheck draws its own chevron: two linear-gradients in
 * var(--color-main-text) on appearance:none selects
 * (css/common/form-controls.css) — an SVG url() cannot follow the Nextcloud
 * theme. The rendered contract is therefore computed background-image
 * contains 'linear-gradient(' AND background-repeat === 'no-repeat' AND a
 * non-default position, in light AND dark.
 *
 * Legs:
 *  1. Contracts filter select #cc-contract-status (statically rendered).
 *  2. Contract-form type select #cc-f-type.
 *
 * Theme is the real user setting (server-rendered) on a throwaway manager —
 * emulation cannot reach data-theme-*, and flipping a shared uid would
 * poison parallel specs.
 */
import { test, expect, request } from '@playwright/test'
import { login, rememberSessionCookies } from './helpers/auth.js'
import { provisionManager, deprovisionUser, setUserTheme } from './helpers/theme.js'

const APP = '/apps/contractcheck'
const THEMES = ['["light"]', '["dark"]']

/**
 * A brand-new user's first form login blocks >30s on skeleton provisioning —
 * past the login() navigation bound. Prime the session through the request
 * API (same shape as globalSetup's primeSession) so the expensive first
 * login happens once, off the page, and login() reuses the cached session.
 */
async function primeUserSession(baseURL, username, password) {
	const context = await request.newContext({ baseURL })
	try {
		const loginPage = await context.get('/login')
		const html = await loginPage.text()
		const requesttoken = html.match(/data-requesttoken="([^"]*)"/)?.[1] || ''
		const res = await context.post('/login', {
			form: { user: username, password, requesttoken },
			headers: { Origin: baseURL, requesttoken },
			maxRedirects: 0,
			timeout: 120_000,
		})
		const location = res.headers()['location'] || ''
		if (res.status() !== 303 || location.includes('/login')) {
			throw new Error(`prime login for "${username}" returned ${res.status()} -> ${location || '(no location)'}`)
		}
		rememberSessionCookies(username, (await context.storageState()).cookies)
	} finally {
		await context.dispose()
	}
}

/** Computed chevron probe: app gradient caret = image + no-repeat + positioned. */
async function probeChevron(select, theme) {
	const probe = await select.evaluate((node) => {
		const cs = getComputedStyle(node)
		return {
			image: cs.backgroundImage,
			repeat: cs.backgroundRepeat,
			position: cs.backgroundPosition,
		}
	})
	expect(probe.image, `${theme}: chevron background-image missing (bare background: regression)`).toContain('linear-gradient(')
	// Two chevron layers — computed repeat serializes per layer; none may tile.
	expect(probe.repeat.split(',').map((v) => v.trim()), `${theme}: chevron tiles (dark-grid regression)`)
		.toEqual(probe.repeat.split(',').map(() => 'no-repeat'))
	expect(probe.position, `${theme}: chevron unpositioned`).not.toBe('0% 0%')
}

test.describe('select chevron contract', () => {
	test.describe.configure({ mode: 'serial' })
	let mgr
	test.beforeAll(async ({}, testInfo) => {
		mgr = provisionManager()
		const baseURL = testInfo.project.use.baseURL || process.env.NC_BASE_URL || 'http://localhost:8081'
		await primeUserSession(baseURL, mgr.uid, mgr.password)
	})
	test.afterAll(() => deprovisionUser(mgr?.uid))
	test.beforeEach(async ({ page }) => {
		await login(page, { username: mgr.uid, password: mgr.password })
	})

	test('contracts filter select keeps the picker chevron (light+dark)', async ({ page }) => {
		test.setTimeout(120_000)
		await page.setViewportSize({ width: 1280, height: 900 })
		for (const theme of THEMES) {
			setUserTheme(mgr.uid, theme)
			await page.goto(`${APP}/contracts`, { waitUntil: 'domcontentloaded' })
			const select = page.locator('#cc-contract-status')
			await expect(select).toHaveCount(1)
			await probeChevron(select, theme)
		}
	})

	test('contract-form type select keeps the picker chevron (light+dark)', async ({ page }) => {
		test.setTimeout(120_000)
		await page.setViewportSize({ width: 1280, height: 900 })
		for (const theme of THEMES) {
			setUserTheme(mgr.uid, theme)
			await page.goto(`${APP}/contracts/new`, { waitUntil: 'domcontentloaded' })
			const select = page.locator('#cc-f-type')
			await expect(select).toHaveCount(1)
			await probeChevron(select, theme)
		}
	})
})
