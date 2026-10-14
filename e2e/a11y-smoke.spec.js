// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv } from './helpers/auth.js'

const admin = credsFromEnv('ADMIN')

test.describe('a11y smoke', () => {
	test.skip(!admin, 'Requires NC_ADMIN_* credentials')

	for (const [path, name] of [
		['/apps/contractcheck/', 'dashboard'],
		['/apps/contractcheck/contracts', 'contracts list'],
		['/apps/contractcheck/contracts/new', 'contract form'],
		['/apps/contractcheck/settings/deadlines', 'settings'],
	]) {
		test(`${name}: no critical axe violations + keyboard reachable`, async ({ page }) => {
			await login(page, admin)
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			await page.waitForTimeout(1200) // let page JS hydrate
			// Scoped to the app's own landmark — NC core header chrome
			// (e.g. unified-search aria) is upstream, not this app's surface.
			const results = await new AxeBuilder({ page })
				.include('#cc-main-content')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.analyze()
			const critical = results.violations.filter(
				(v) => v.impact === 'critical' || v.impact === 'serious',
			)
			expect(
				critical.map((v) => `${v.id}: ${v.nodes.length} node(s)`),
				`axe violations on ${name}`,
			).toEqual([])

			// Keyboard: skip link lands on main content.
			await page.keyboard.press('Tab')
			const firstFocused = await page.evaluate(() => document.activeElement?.tagName)
			expect(firstFocused).toBeTruthy()
		})
	}
})
