// @ts-check
/**
 * Theme & viewport matrix — the Nextcloud UI-master contract:
 *  - no horizontal overflow at 320 / 375 / 414 / 768 / 1024 / 1280
 *  - dark + high-contrast themes via the real user setting (server-rendered
 *    CSS vars — prefers-color-scheme emulation cannot reach them)
 *  - forced-colors hardening: borders/system colors where backgrounds strip
 *  - axe WCAG 2.1 AA per theme on the densest surface
 *
 * A dedicated fixture manager per describe — theme is a user setting, and
 * flipping it mid-suite on a shared uid would poison parallel specs.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login } from './helpers/auth.js'
import { provisionManager, deprovisionUser, setUserTheme } from './helpers/theme.js'
import { apiPost } from './helpers/api.js'

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']
const VIEWPORTS = [320, 375, 414, 768, 1024, 1280]
const PAGES = [
	'/apps/contractcheck/',
	'/apps/contractcheck/contracts',
	'/apps/contractcheck/contracts/new',
	'/apps/contractcheck/parties',
	'/apps/contractcheck/reports',
	'/apps/contractcheck/import',
	'/apps/contractcheck/settings/deadlines',
]

async function assertNoOverflow(page, width, url) {
	const overflow = await page.evaluate(
		() => document.documentElement.scrollWidth - document.documentElement.clientWidth
	)
	expect(overflow, `horizontal overflow at ${width}px on ${url}`).toBeLessThanOrEqual(1)
}

async function axeClean(page, label) {
	const results = await new AxeBuilder({ page })
		.include('#cc-main-content')
		.withTags(AXE_TAGS)
		.analyze()
	const bad = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')
	expect(bad.map((v) => `${v.id}: ${v.nodes.length}`), `axe ${label}`).toEqual([])
}

test.describe('viewport matrix (default theme)', () => {
	let mgr
	test.beforeAll(() => { mgr = provisionManager() })
	test.afterAll(() => deprovisionUser(mgr?.uid))
	test.beforeEach(async ({ page }) => {
		await login(page, { username: mgr.uid, password: mgr.password })
	})

	for (const path of PAGES) {
		test(`${path}: no horizontal overflow 320→1280`, async ({ page }) => {
			await page.setViewportSize({ width: 320, height: 844 })
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			// redirect (viewer deep-links) is acceptable — the audit targets layout
			for (const w of VIEWPORTS) {
				await page.setViewportSize({ width: w, height: 844 })
				await assertNoOverflow(page, w, path)
			}
		})
	}

	test('320px: touch targets ≥44px on primary controls', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 844 })
		await page.goto('/apps/contractcheck/contracts/new', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-contract-form')).toBeVisible()
		const box = await page.locator('#cc-form-save').boundingBox()
		expect(box?.height ?? 0).toBeGreaterThanOrEqual(44)
	})

	test('320px: cc-btn--small row actions ≥44px', async ({ page }) => {
		// Seed a contract so the list renders row actions.
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		await apiPost(page, '/api/contracts', {
			title: `Touch ${Date.now()}`, type: 'rent', scope: 'org',
			status: 'active', startDate: '2026-01-01',
			termKind: 'indefinite', noticeAnchor: 'anytime',
		})
		await page.setViewportSize({ width: 320, height: 844 })
		await page.goto('/apps/contractcheck/contracts', { waitUntil: 'domcontentloaded' })
		const btn = page.locator('.cc-table .cc-btn--small').first()
		await expect(btn).toBeVisible({ timeout: 15000 })
		const box = await btn.boundingBox()
		expect(box?.height ?? 0).toBeGreaterThanOrEqual(44)
	})
})

test.describe('dark theme (real user setting)', () => {
	let mgr
	test.beforeAll(() => {
		mgr = provisionManager()
		setUserTheme(mgr.uid, '["dark"]')
	})
	test.afterAll(() => deprovisionUser(mgr?.uid))
	test.beforeEach(async ({ page }) => {
		await login(page, { username: mgr.uid, password: mgr.password })
	})

	test('dashboard: dark vars actually applied + axe AA clean', async ({ page }) => {
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(800)
		const bg = await page.evaluate(() => {
			const v = getComputedStyle(document.getElementById('content') || document.body).backgroundColor
			return v.match(/\d+/g)?.map(Number) || [255]
		})
		expect(Math.max(...bg.slice(0, 3)), `background not dark: rgb(${bg})`).toBeLessThan(80)
		// App surface must not be a stale light card on dark chrome.
		const card = await page.locator('.cc-stat').first().evaluate(
			(el) => getComputedStyle(el).backgroundColor.match(/\d+/g)?.map(Number) || [255])
		expect(Math.max(...card.slice(0, 3)), `cc-stat bg not dark: rgb(${card})`).toBeLessThan(80)
		await axeClean(page, 'dark dashboard')
	})

	test('contract form + contracts list: axe AA under dark theme', async ({ page }) => {
		for (const path of ['/apps/contractcheck/contracts/new', '/apps/contractcheck/contracts']) {
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			await page.waitForTimeout(600)
			await axeClean(page, `dark ${path}`)
		}
	})
})

test.describe('high-contrast themes (real user setting)', () => {
	for (const [theme, label] of [
		['["light-highcontrast"]', 'light-hc'],
		['["dark-highcontrast"]', 'dark-hc'],
	]) {
		test.describe(label, () => {
			let mgr
			test.beforeAll(() => {
				mgr = provisionManager()
				setUserTheme(mgr.uid, theme)
			})
			test.afterAll(() => deprovisionUser(mgr?.uid))
			test.beforeEach(async ({ page }) => {
				await login(page, { username: mgr.uid, password: mgr.password })
			})

			test(`dashboard + form axe AA under ${label}`, async ({ page }) => {
				for (const path of ['/apps/contractcheck/', '/apps/contractcheck/contracts/new']) {
					await page.goto(path, { waitUntil: 'domcontentloaded' })
					await page.waitForTimeout(600)
					await axeClean(page, `${label} ${path}`)
				}
			})

			test(`${label}: interactive borders ≥1px (3:1 chrome)` , async ({ page }) => {
				await page.goto('/apps/contractcheck/contracts/new', { waitUntil: 'domcontentloaded' })
				const bw = await page.locator('#cc-f-title')
					.evaluate((el) => parseFloat(getComputedStyle(el).borderTopWidth))
				expect(bw, 'input border must survive HC').toBeGreaterThanOrEqual(1)
			})
		})
	}
})

test.describe('forced-colors emulation', () => {
	let mgr
	test.beforeAll(() => { mgr = provisionManager() })
	test.afterAll(() => deprovisionUser(mgr?.uid))
	test.beforeEach(async ({ page }) => {
		await login(page, { username: mgr.uid, password: mgr.password })
		await page.emulateMedia({ forcedColors: 'active' })
	})

	test('panels + inputs keep borders; buttons stay operable', async ({ page }) => {
		await page.goto('/apps/contractcheck/contracts/new', { waitUntil: 'domcontentloaded' })
		expect(await page.evaluate(() => matchMedia('(forced-colors: active)').matches)).toBe(true)
		const fieldBorder = await page.locator('#cc-f-title')
			.evaluate((el) => parseFloat(getComputedStyle(el).borderTopWidth))
		expect(fieldBorder, 'forced-colors input border').toBeGreaterThan(0)
		await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
		const stat = page.locator('a.cc-stat').first()
		await expect(stat).toBeVisible()
		const panelBorder = await stat.evaluate((el) => parseFloat(getComputedStyle(el).borderTopWidth))
		expect(panelBorder, 'forced-colors stat border').toBeGreaterThan(0)
		// OS owns the palette — contrast not axe-checkable under forced-colors.
		const axe = await new AxeBuilder({ page })
			.include('#cc-main-content').withTags(AXE_TAGS).disableRules(['color-contrast']).analyze()
		expect(axe.violations.map((v) => v.id)).toEqual([])
	})
})
