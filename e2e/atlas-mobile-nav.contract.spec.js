// @ts-check
/** ATLAS_MOBILE_NAV_CONTRACT — phone Menu → open nav + no h-scroll */
import { test } from '@playwright/test'
import { createRequire } from 'node:module'
import { login, primaryCreds } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasMobileNav } = require('../../_shared/e2e/atlas-mobile-nav-contract.js')

const creds = primaryCreds()

test('ATLAS_MOBILE_NAV_CONTRACT in-page Menu opens drawer', async ({ page }) => {
	test.skip(!creds, 'Requires NC_E2E_*/NC_ADMIN_* credentials')
	await login(page, creds)
	await page.setViewportSize({ width: 375, height: 812 })
	await page.goto('/apps/contractcheck/', { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('[data-cc-nav-toggle]', { timeout: 30_000 })
	await assertAtlasMobileNav(page, {
		toggle: page.locator('[data-cc-nav-toggle]').first(),
		nav: page.locator('#app-navigation'),
		openClass: /cc-nav--open/,
	})
})
