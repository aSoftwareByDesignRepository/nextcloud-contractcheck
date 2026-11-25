// @ts-check
/**
 * Regression pin for the 2026-11-24 Johan intake (ContractCheck 1.0.3):
 * adding a group to "Contract managers" showed "group isn't found" and the
 * save 409'd with "this record has been changed in the meantime".
 *
 * Two root causes, both pinned here:
 *
 * 1. directory_picker_row_shape — the group directory returns
 *    {gid, displayName} while the picker rendered item.id/item.label:
 *    blank option text, data-id="undefined", undefined ids emitted.
 *    Same class hit the owner picker ({uid,displayName}) and the document
 *    picker ({fileId,name}).
 * 2. cas_token_zero_rejected — POST /api/settings parsed `version` via a
 *    strictly-positive id helper, so the pristine never-saved version 0
 *    always failed with CONFLICT. On a fresh install the FIRST settings
 *    save was impossible.
 *
 * Also pinned: Enter/Space on a focused option (the old code looked up a
 * `searchLastItem` map that nothing ever populated — pointer-only
 * selection, WCAG 2.1.1).
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { apiCall, apiGet, saveSettings } from './helpers/api.js'

const APP = '/apps/contractcheck'
// Manager role required — the E2E viewer is redirected off /settings/access
// and saveSettings 403s for non-managers (same convention as access-door).
const manager = credsFromEnv('ADMIN')

test.describe('settings access pickers', () => {
	test.skip(!manager, 'Requires NC_E2E_* or NC_ADMIN_* credentials')

	test('group picker renders real labels+ids, Enter selects, save persists', async ({ page }, testInfo) => {
		// shared_fixture_race (2026-11-25 trace-proven): every parallel project
		// used to run this leg against the same managerGroups fixture on the
		// shared cc_settings CAS doc. A sibling project's save landing between
		// hydration and the baseline apiGet skewed the two snapshots (v2620 vs
		// v2621 in the traces) — the "new" target gid was already a hydrated
		// chip, choose() deduped into a silent no-op, and the chip-count
		// assertion died. The mutation leg is therefore pinned to ONE project;
		// the non-mutating legs still run in every project. Targeted local
		// re-run of the leg: `--project=chromium-1280`.
		test.skip(testInfo.project.name !== 'chromium-1280',
			'shared cc_settings CAS fixture — mutation leg runs in one project only')
		await login(page, manager)

		// Baseline for the cleanup restore. Target selection below keys off
		// the PAGE's chip state instead — the hydrated chips are the exact
		// selection set choose() dedups against.
		const before = await apiGet(page, '/api/settings')
		const originalManagers = before.settings?.managerGroups ?? []

		try {
			let pickedGid = null
			// Retry bound: sibling writers that legitimately share the settings
			// doc (e.g. the reports spec's merge+retry budget saves) can still
			// win the CAS race between our hydration and our save — the page
			// then toasts the conflict and we reload for a fresh version.
			for (let attempt = 0; attempt < 4 && pickedGid === null; attempt++) {
				// The pickers hydrate asynchronously off GET /api/settings — a pick
				// landing before hydration completes gets clobbered by setSelected.
				const hydrated = page.waitForResponse(
					(r) => r.url().includes('/api/settings') && r.request().method() === 'GET' && r.ok(),
				)
				await page.goto(`${APP}/settings/access`, { waitUntil: 'domcontentloaded' })
				await hydrated

				const input = page.locator('#cc-set-managers')
				await input.fill('check')
				const options = page.locator('#cc-managers-results .cc-picker__result')
				await expect(options.first()).toBeVisible({ timeout: 10000 })

				// 1. Every option carries a real id and non-empty label — the
				//    shipped bug rendered data-id="undefined" with blank text.
				const count = await options.count()
				expect(count, 'group search must return at least one option').toBeGreaterThan(0)
				const ids = []
				for (let i = 0; i < count; i++) {
					const opt = options.nth(i)
					const dataId = await opt.getAttribute('data-id')
					expect(dataId, 'option id must be the gid').toBeTruthy()
					expect(dataId).not.toBe('undefined')
					expect((await opt.textContent())?.trim() ?? '', 'option label must not be blank').not.toBe('')
					ids.push(dataId)
				}

				// 2. Keyboard path: ArrowDown into the listbox, Enter selects the
				//    focused option (dead lookup in shipped code). Re-picking an
				//    already-selected gid dedups silently — correct behaviour, so
				//    navigate to the first option that is not yet a chip. Hydrated
				//    chips carry the gid as their label.
				const chipGids = (await page.locator('#cc-managers-chips .cc-chip__label').allTextContents())
					.map((t) => t.trim())
				const targetIdx = ids.findIndex((id) => !chipGids.includes(id))
				expect(targetIdx, 'search must offer at least one not-yet-selected group').toBeGreaterThan(-1)
				const targetGid = ids[targetIdx]
				const chipsBefore = await page.locator('#cc-managers-chips .cc-chip').count()
				await input.press('ArrowDown')
				for (let i = 0; i < targetIdx; i++) await page.keyboard.press('ArrowDown')
				await page.keyboard.press('Enter')
				const chips = page.locator('#cc-managers-chips .cc-chip')
				await expect(chips).toHaveCount(chipsBefore + 1)
				const chipTexts = await chips.allTextContents()
				expect(chipTexts.join(' ')).not.toContain('undefined')
				// The emitted id must be the gid, not "undefined" — the save below
				// would otherwise fail validation with a field error, not succeed.

				// 3. Save persists the picked gid via the real button. A 409 is
				//    the honest CAS conflict — reload and retry; anything else
				//    that is not 200 is a real product failure, assert it.
				const posted = page.waitForResponse(
					(r) => r.url().includes('/api/settings') && r.request().method() === 'POST',
				)
				await page.locator('#cc-set-save-access').click()
				const resp = await posted
				if (resp.status() === 409) continue
				expect(resp.status(), 'settings save response').toBe(200)
				await expect(page.locator('#cc-toasts .cc-toast--success')).toBeVisible({ timeout: 10000 })
				pickedGid = targetGid
			}
			expect(pickedGid, 'settings save kept losing the CAS race across retries').toBeTruthy()
			const after = await apiGet(page, '/api/settings')
			expect(after.settings.managerGroups).toContain(pickedGid)
		} finally {
			await saveSettings(page, (s) => ({ ...s, managerGroups: originalManagers }))
		}
	})

	test('version 0 reaches CAS — never a parser rejection', async ({ page }) => {
		await login(page, manager)
		await page.goto(`${APP}/settings/access`, { waitUntil: 'domcontentloaded' })

		// On a seeded instance version 0 is simply stale: the store must
		// answer the real CAS conflict, not the "missing version" parse
		// rejection that made every fresh-install first save impossible.
		const r = await apiCall(page, 'POST', '/api/settings', {
			version: 0,
			settings: { managerGroups: ['admin'] },
		})
		expect(r.status).toBe(409)
		expect(r.json?.error).toBe('CONFLICT')
		expect(String(r.json?.message ?? '')).not.toContain('Missing settings version')
	})
})
