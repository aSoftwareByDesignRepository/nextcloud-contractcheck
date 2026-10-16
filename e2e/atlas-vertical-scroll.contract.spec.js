// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — the tall settings/access page must scroll
 * its last control into reach. Guards CSS Overflow L3 unpaired
 * overflow-x:clip truncating the Save CTA. Access & roles is the app's
 * tallest settings surface (three group pickers + save row).
 */
import { test } from '@playwright/test'
import { createRequire } from 'node:module'
import { login, credsFromEnv, adminCreds } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasVerticalScrollReachable } = require('../../_shared/e2e/atlas-vertical-scroll-contract.js')

// /settings/access is manager-only — a viewer lands on the calendar feed
// fallback and the Save CTA never renders. Prefer NC_ADMIN_* (admin group ⇒
// contractcheck manager); adminCreds() alone would pick the E2E viewer first.
const creds = credsFromEnv('ADMIN') || adminCreds()

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test.skip(!creds, 'Requires NC_E2E_*/NC_ADMIN_* credentials')

	test('settings access scrolls to the Save CTA', async ({ page }) => {
		await login(page, creds)
		await page.setViewportSize({ width: 1280, height: 640 })
		await page.goto('/apps/contractcheck/settings/access', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#cc-set-save-access', { timeout: 45_000 })

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#cc-set-save-access, .cc-settings [class*="cc-support"], #cc-feed-mint',
			bottomSlopPx: 12,
		})
	})

	test('settings access stays reachable at phone height', async ({ page }) => {
		await login(page, creds)
		await page.setViewportSize({ width: 390, height: 667 })
		await page.goto('/apps/contractcheck/settings/access', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#cc-set-save-access', { timeout: 45_000 })

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#cc-set-save-access',
			bottomSlopPx: 16,
		})
	})
})
