// @ts-check
/**
 * ATLAS web craft capture — transfer settings surfaces. Writes PNGs to
 * .cursor/atlas-farm-v3/artifacts/contractcheck/craft/web/.
 * Flow: settings/transfer load → uniform file error → real
 * export→preview→report dialog → apply → progress dialog → after-apply.
 * Ported from whistlecheck e2e/craft-transfer.spec.js (same synced
 * config-transfer pack, {p}=cc). Asserts the rendered strings show
 * interpolated values (never %d/%s/%1$s/{0} residue), the replace token
 * row escapes the NC-core 130px pin, and the dialog footer (token row +
 * Cancel + Apply import) paints inside the dialog client box at both
 * 1280 and 375 (required_field_or_step_undiscoverable donor fix — the
 * .cc-dialog flex-column + .cc-dialog__body scroller).
 *
 * The apply legs mutate the shared cc_settings CAS doc + upsert business
 * rows — this spec runs alone in its own serialized `chromium-transfer`
 * project (dependencies chain it after every other project, like
 * chromium-door), never parallel with settings-writing specs.
 *
 * Session note: SessionCsrfMiddleware demands the session requesttoken on
 * every non-GET — API calls go through the page's own authenticated
 * request context + OC.requestToken, never bare basic auth.
 */
import { test, expect } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import shared from '../../_shared/e2e/atlas-rendered-surface-contract.js'
import { login } from './helpers/auth.js'
import { occ, queryDb } from './helpers/stack.js'

const { assertAtlasRenderedSurface } = shared

const APP = '/index.php/apps/contractcheck'
const PASS = 'atlas-e2e-passphrase-2026'
const OUT = path.resolve(path.dirname(fileURLToPath(import.meta.url)),
	'../../../../.cursor/atlas-farm-v3/artifacts/contractcheck/craft/web')
fs.mkdirSync(OUT, { recursive: true })

const admin = {
	username: process.env.NC_ADMIN_USER,
	password: process.env.NC_ADMIN_PASS,
}

async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false })
}

/** overlay_toast_button_armor_gap (donor: whistlecheck wc-vis-r13-01): the
 * toast dismiss is a bare <button class="cc-toast__close"> mounted on
 * document.body inside #cc-toasts — NC core's button repaint (width:130px
 * + pale fill + border at 0-1-1, margin-inline-start:0 at 0-2-1) must not
 * reach it. Assert the painted geometry is the declared transparent ~44px
 * icon-close docked at the toast's inline-end on one row — not the
 * collapsed ~128px pale core-skin block wrapped to a second row. */
async function assertToastCloseArmored(page) {
	const close = page.locator('.cc-toast.cc-toast--error .cc-toast__close').first()
	await expect(close).toBeVisible()
	const m = await close.evaluate((el) => {
		const r = el.getBoundingClientRect()
		const cs = getComputedStyle(el)
		const host = el.closest('.cc-toast').getBoundingClientRect()
		return {
			x: r.x, y: r.y, w: r.width, h: r.height,
			hostX: host.x, hostY: host.y, hostW: host.width, hostH: host.height,
			vpH: window.innerHeight,
			bg: cs.backgroundColor, bw: cs.borderWidth,
		}
	})
	expect(m.bg, 'dismiss keeps transparent background (no pale core skin)')
		.toBe('rgba(0, 0, 0, 0)')
	expect(m.bw, 'dismiss keeps border:0').toBe('0px')
	expect(m.w, 'dismiss ≈44px — core width:130px repaint would blow it out')
		.toBeLessThanOrEqual(56)
	expect(m.w).toBeGreaterThanOrEqual(40)
	expect(m.h).toBeGreaterThanOrEqual(40)
	expect(m.h).toBeLessThanOrEqual(64)
	// inline-end dock + single row: glyph sits in the toast's trailing half
	// and inside its height (the collapsed block wrapped to a second row)
	expect(m.x + m.w / 2).toBeGreaterThan(m.hostX + m.hostW / 2)
	expect(m.y + m.h).toBeLessThanOrEqual(m.hostY + m.hostH + 1)
	// the stack clears the bottom viewport edge (safe-area floor)
	expect(m.hostY + m.hostH).toBeLessThanOrEqual(m.vpH + 1)
}
/** Authenticated request through the page's own cookie jar + the session
 * CSRF token the app middleware demands on non-GET. */
async function authedPost(page, url, data) {
	const token = await page.evaluate(() => (window.OC && OC.requestToken) || '')
	return page.context().request.post(url, {
		data,
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	})
}
async function authedGet(page, url) {
	return page.context().request.get(url, { headers: { 'OCS-APIRequest': 'true' } })
}

test.describe('craft transfer', () => {
	test.skip(!admin.username || !admin.password, 'Requires NC_ADMIN_* credentials')

	test('manager transfer surface + report/progress dialogs 1280/375', async ({ page }) => {
		test.setTimeout(240_000)

		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, admin)
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-transfer-export-card')).toBeVisible({ timeout: 20000 })

		const exp = await authedPost(page, `${APP}/api/admin/transfer/export`, { passphrase: PASS })
		expect(exp.status()).toBe(200)
		const bytes = await exp.body()
		expect(bytes.length).toBeGreaterThan(200)

		await page.waitForTimeout(600)
		await shot(page, 'cc-manager-transfer-light-1280')

		// uniform file error — wrong passphrase on a real container
		await page.locator('#cc-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#cc-transfer-import-passphrase').fill('wrong-passphrase-000')
		// dead_platform_toast_api: the failure must reach a VISIBLE app-owned
		// toast, not only the visually-hidden live region.
		await expect(page.locator('.cc-toast.cc-toast--error').first()).toBeVisible({ timeout: 30000 })
		await assertToastCloseArmored(page)
		await expect(page.locator('#cc-transfer-result-live')).not.toBeEmpty({ timeout: 30000 })
		await shot(page, 'cc-manager-transfer-fileerror-light-1280')

		// correct passphrase → preview → report dialog (interpolated counters)
		await page.locator('#cc-transfer-import-passphrase').fill(PASS)
		const report = page.locator('#cc-transfer-report')
		await expect(report).toBeVisible({ timeout: 30000 })
		await expect(page.locator('#cc-transfer-report-verdict')).toContainText(/\d/)
		// positional t() interpolation: localized values, never raw tokens
		const fileLine = await page.locator('#cc-transfer-report-file').textContent()
		expect(fileLine || '').not.toMatch(/\{\d+\}|%\d+\$s|%d(?!\w)/)
		await shot(page, 'cc-manager-transfer-report-dialog-light-1280')

		// apply → progress dialog stays open while the job waits queued
		await page.locator('#cc-transfer-confirm').click()
		const progress = page.locator('#cc-transfer-progress')
		await expect(progress).toBeVisible({ timeout: 10000 })
		await expect(page.locator('#cc-transfer-progress-text')).not.toBeEmpty()
		await shot(page, 'cc-manager-transfer-progress-light-1280')

		// app-namespaced class id — other apps' TransferApplyJob rows never match
		const jobId = queryDb("SELECT id FROM oc_jobs WHERE class LIKE '%ContractCheck%TransferApplyJob%' ORDER BY id DESC LIMIT 1")
		expect(jobId).toMatch(/^\d+$/)
		const priorReceiptId = ((((await (await authedGet(page, `${APP}/api/admin/transfer/status`)).json()).data || {}).lastReceipt) || {}).id || ''
		occ(['background-job:execute', jobId])

		let receipt = {}
		for (let i = 0; i < 60; i++) {
			const s = await authedGet(page, `${APP}/api/admin/transfer/status`)
			receipt = (((await s.json()).data || {}).lastReceipt) || {}
			if (receipt.id && receipt.id !== priorReceiptId
				&& (receipt.result === 'applied' || receipt.result === 'failed')) break
			await new Promise((r) => setTimeout(r, 1000))
		}
		expect(receipt.result).toBe('applied')

		await page.waitForLoadState('domcontentloaded')
		await expect(page.locator('#cc-transfer-last')).toBeVisible({ timeout: 20000 })
		await page.waitForTimeout(600)
		await shot(page, 'cc-manager-transfer-after-apply-light-1280')
	})

	test('manager transfer surface mobile 375', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 })
		await login(page, admin)
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-transfer-export-card')).toBeVisible({ timeout: 20000 })
		await page.waitForTimeout(600)
		await shot(page, 'cc-manager-transfer-light-375')
	})

	test('manager transfer replace-confirm token row wide + footer painted (375/1280)', async ({ page }) => {
		test.setTimeout(180_000)

		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, admin)
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#cc-transfer-export-card')).toBeVisible({ timeout: 20000 })

		const exp = await authedPost(page, `${APP}/api/admin/transfer/export`, { passphrase: PASS })
		expect(exp.status()).toBe(200)
		const bytes = await exp.body()
		expect(bytes.length).toBeGreaterThan(200)

		// Full-form pinned-control sweep (cramped_form_controls).
		await assertAtlasRenderedSurface(page, { content: '#app-content.cc-app' })

		// Replace mode → real preview → report dialog footer shows token row.
		await page.locator('#cc-transfer-import-form input[type="radio"][value="replace"]').check()
		await page.locator('#cc-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#cc-transfer-import-passphrase').fill(PASS)
		const report = page.locator('#cc-transfer-report')
		await expect(report).toBeVisible({ timeout: 30000 })
		const tokenRow = page.locator('#cc-transfer-replace-confirm')
		await expect(tokenRow).toBeVisible()
		const token = page.locator('#cc-transfer-replace-token')
		await expect(token).toBeVisible()

		// cramped_form_controls fix live: the input escapes the core 130px pin.
		const w = await token.evaluate((el) => el.getBoundingClientRect().width)
		expect(w, `token input ${Math.round(w)}px still on the NC-core 130px pin`).toBeGreaterThan(140)

		// required_field_or_step_undiscoverable: the report dialog's footer —
		// token row + Cancel + Apply import — must paint INSIDE the dialog's
		// client box at every viewport; .cc-dialog__body absorbs overflow.
		const assertDialogFooterPainted = async (where) => {
			const g = await report.evaluate((d) => {
				const cs = getComputedStyle(d)
				const r = d.getBoundingClientRect()
				const bt = parseFloat(cs.borderTopWidth) || 0
				const bl = parseFloat(cs.borderLeftWidth) || 0
				const client = { top: r.top + bt, left: r.left + bl, bottom: r.top + bt + d.clientHeight, right: r.left + bl + d.clientWidth }
				const f = d.querySelector('.cc-dialog__footer').getBoundingClientRect()
				const a = d.querySelector('#cc-transfer-confirm').getBoundingClientRect()
				return { client, dScrollH: d.scrollHeight, dClientH: d.clientHeight,
					footer: { top: f.top, bottom: f.bottom, left: f.left, right: f.right },
					apply: { top: a.top, bottom: a.bottom, left: a.left, right: a.right } }
			})
			for (const [name, box] of Object.entries({ footer: g.footer, apply: g.apply })) {
				expect(box.bottom, `${where}: ${name} bottom ${Math.round(box.bottom)} hangs below dialog client box ${Math.round(g.client.bottom)}`).toBeLessThanOrEqual(g.client.bottom + 0.5)
				expect(box.top, `${where}: ${name} top above dialog client box`).toBeGreaterThanOrEqual(g.client.top - 0.5)
				expect(box.right, `${where}: ${name} right outside dialog client box`).toBeLessThanOrEqual(g.client.right + 0.5)
				expect(box.left, `${where}: ${name} left outside dialog client box`).toBeGreaterThanOrEqual(g.client.left - 0.5)
			}
			expect(g.dScrollH - g.dClientH,
				`${where}: dialog shell scrolls (scrollHeight ${g.dScrollH} > clientHeight ${g.dClientH}) — .cc-dialog__body must absorb the overflow`)
				.toBeLessThanOrEqual(1)
		}
		await assertDialogFooterPainted('1280')

		await shot(page, 'cc-manager-transfer-replace-confirm-light-1280')
		await page.setViewportSize({ width: 375, height: 812 })
		await page.waitForTimeout(300)
		await assertDialogFooterPainted('375')
		await shot(page, 'cc-manager-transfer-replace-confirm-light-375')
		// Never apply — replace is destructive; close the dialog instead.
		await page.keyboard.press('Escape')
		await expect(report).toBeHidden()
	})
})
