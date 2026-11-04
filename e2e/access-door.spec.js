// @ts-check
/**
 * Access-door denied page — the 403 surface must carry a REAL escape.
 *
 * Regression pin for denied_recovery_href_self_loop (intake
 * 20261104T052839Z): the access-denied template's "Back to Nextcloud" CTA
 * was rendered with homeUrl = linkToRouteAbsolute('page.index') — the same
 * route beforeController denies, so the button looped a locked-out user
 * straight back onto the 403. The CTA must resolve to the platform default
 * page (SafeHomeUrl) and must actually be reachable for a denied user.
 *
 * Also captures the denied-surface craft PNGs (the 403 page is a real user
 * surface; happy-path-only craft must not hide it).
 */
import { test, expect } from '@playwright/test'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { mkdirSync } from 'node:fs'
import { login, credsFromEnv } from './helpers/auth.js'
import { apiGet, saveSettings } from './helpers/api.js'

const HERE = dirname(fileURLToPath(import.meta.url))
const OUT = resolve(HERE, '../../../../.cursor/atlas-farm-v3/artifacts/contractcheck/craft/web')
const APP = '/apps/contractcheck'

const viewer = credsFromEnv('E2E')
const manager = credsFromEnv('ADMIN')

test.describe('access door', () => {
	test.skip(!viewer || !manager, 'Requires NC_E2E_* and NC_ADMIN_* credentials')

	test('denied page returns 403 and its recovery CTA leaves the door', async ({ page, browser }) => {
		await login(page, manager)
		const before = await apiGet(page, '/api/settings')
		const originalGroups = before.settings?.allowedGroups ?? []

		const vctx = await browser.newContext()
		const vpage = await vctx.newPage()
		try {
			// Narrow the door to admin-only: the viewer now hits the denied page.
			await saveSettings(page, (s) => ({ ...s, allowedGroups: ['admin'] }))
			await login(vpage, viewer)

			const resp = await vpage.goto(`${APP}/contracts`, { waitUntil: 'domcontentloaded' })
			expect(resp?.status()).toBe(403)
			await expect(vpage.locator('.cc-app--denied')).toBeVisible()
			mkdirSync(OUT, { recursive: true })
			await vpage.screenshot({ path: join(OUT, 'cck-access-denied-desktop.png') })

			// Nav-track wallpaper gutter (shell_grid_nav_track_wallpaper_bleed):
			// the denied page renders no #app-navigation — #app-content must span
			// the full grid row, never leave an empty 280px nav track that bleeds
			// the host wallpaper through as page chrome.
			await expect(vpage.locator('#content > #app-navigation')).toHaveCount(0)
			const contentBox = await vpage.locator('#app-content').boundingBox()
			const shellBox = await vpage.locator('#content').boundingBox()
			expect(contentBox?.x, '#app-content must start at the shell left edge').toBe(0)
			expect(shellBox?.x).toBe(0)
			const navTrackCovered = await vpage.evaluate(() => {
				// Probe the middle of the former nav track (x=140 < 280px nav width).
				const el = document.elementFromPoint(140, Math.floor(window.innerHeight / 2))
				return el !== null && el.closest('#app-content') !== null
			})
			expect(navTrackCovered, 'former nav track must be covered by #app-content').toBe(true)

			// The recovery CTA must point somewhere a denied user can actually go —
			// never back at the app index (self-loop to the same 403).
			const cta = vpage.locator('.cc-app--denied .cc-btn--primary')
			await expect(cta).toBeVisible()
			const href = await cta.getAttribute('href')
			expect(href, 'recovery CTA needs a real href').toBeTruthy()
			expect(href, 'recovery CTA must not self-loop to the denied app index')
				.not.toContain('/apps/contractcheck')
			const landing = await vpage.goto(/** @type {string} */ (href), { waitUntil: 'domcontentloaded' })
			expect(landing?.status(), `recovery CTA target ${href} must be reachable`).toBeLessThan(400)
			await expect(vpage.locator('.cc-app--denied')).toHaveCount(0)

			// Mobile-width craft variant.
			await vpage.setViewportSize({ width: 375, height: 800 })
			await vpage.goto(`${APP}/contracts`, { waitUntil: 'domcontentloaded' })
			await expect(vpage.locator('.cc-app--denied')).toBeVisible()
			await vpage.screenshot({ path: join(OUT, 'cck-access-denied-mobile.png') })
		} finally {
			await vctx.close()
			await saveSettings(page, (s) => ({ ...s, allowedGroups: originalGroups }))
		}
	})
})
