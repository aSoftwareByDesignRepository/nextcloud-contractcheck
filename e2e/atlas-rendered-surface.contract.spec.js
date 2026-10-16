// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — asserts the *rendered* truth of each app
 * page surface: content list markers are not reset to none by shell CSS
 * leaks, selects are vertically centred (not sunken/clipped), icons and SVGs
 * have non-zero boxes, and form controls/labels are not text-centered by an
 * inherited shell alignment. DOM-level specs pass on pages that are visually
 * broken; this is the pixel-adjacent invariant class.
 */
import { test } from '@playwright/test'
import { createRequire } from 'node:module'
import { login, primaryCreds } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract.js')

const SURFACES = [
	['/apps/contractcheck/dashboard', 'dashboard'],
	['/apps/contractcheck/contracts', 'contracts list + filters'],
	['/apps/contractcheck/contracts/new', 'contract form'],
	['/apps/contractcheck/parties', 'parties'],
	['/apps/contractcheck/reports', 'reports'],
	['/apps/contractcheck/audit', 'audit'],
	['/apps/contractcheck/import', 'import'],
	['/apps/contractcheck/settings', 'settings hub'],
	['/apps/contractcheck/settings/deadlines', 'settings deadlines'],
	['/apps/contractcheck/settings/access', 'settings access'],
	['/apps/contractcheck/settings/feed', 'settings feed'],
	['/apps/contractcheck/settings/support', 'settings support'],
]

/**
 * Intentionally marker-less lists: nav, breadcrumbs, chip/pill rows,
 * deadline lists with custom row chrome and action menus use explicit
 * `list-style: none` + icons/pills, not bullet lists. Content lists must
 * NOT appear here — they must show markers.
 */
const LIST_ALLOW = [
	'.cc-nav',
	'.cc-breadcrumb__list',
	'.cc-check-row',
	'.cc-toolbar',
	'.cc-stat-row',
	'.cc-chip-row',
	'.cc-deadline-list',
	'.cc-detail-actions',
	'.cc-settings-nav',
].join(', ')

const creds = primaryCreds()

test.describe('Rendered-surface contract', () => {
	test.skip(!creds, 'Requires NC_E2E_*/NC_ADMIN_* credentials')

	for (const [routePath, name] of SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${routePath})`, async ({ page }) => {
			await login(page, creds)
			await page.setViewportSize({ width: 1280, height: 800 })
			await page.goto(routePath, { waitUntil: 'domcontentloaded' })
			await page.waitForSelector('#cc-main-content', { timeout: 30_000 })
			await page.waitForLoadState('networkidle').catch(() => {})

			await assertAtlasRenderedSurface(page, {
				content: '#cc-main-content',
				navExclude: '#app-navigation, nav, .cc-nav, .cc-breadcrumb, .cc-nav-toggle',
				listAllow: LIST_ALLOW,
			})
		})
	}
})
