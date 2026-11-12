// @ts-check
/**
 * ATLAS_BTN_VARIANT_ARMOR — button_variant_specificity_collapse oracle.
 * NC core inputs.scss repaints every button:not(.button-vue) at 0-1-1 with
 * the pale --color-primary-element-light fill; a lone .cc-btn--primary
 * (0-1-0) loses on real <button> elements while anchors keep the true paint.
 * The synced config-transfer pack emits class="cc-btn cc-btn--{v}".
 * Oracle: inject <a class="cc-btn cc-btn--{v}"> (never core-repainted)
 * beside a <button class="cc-btn cc-btn--{v}"> and a body-mounted
 * .cc-dialog <button> for every fill variant — all three must carry the
 * same fill for that variant.
 * Ported from whistlecheck e2e/atlas-btn-variant-armor.spec.js ({p}=cc).
 */
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'

const APP = '/index.php/apps/contractcheck'
const VARIANTS = ['primary', 'secondary', 'ghost']

const admin = {
	username: process.env.NC_ADMIN_USER,
	password: process.env.NC_ADMIN_PASS,
}

function paintOf(locator) {
	return locator.first().evaluate((el) => {
		const cs = getComputedStyle(el)
		return { bg: cs.backgroundColor, border: cs.borderTopColor, ink: cs.color }
	})
}

test.describe('Button-variant armor', () => {
	test.skip(!admin.username || !admin.password, 'Requires NC_ADMIN_* credentials')

	test('ATLAS_BTN_VARIANT_ARMOR variant parity button-vs-anchor vs dialog (settings/transfer)', async ({ page }) => {
		await login(page, admin)
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' })
		await page.locator('#cc-transfer-export-card').waitFor({ state: 'visible', timeout: 20000 })
		await page.evaluate((variants) => {
			document.querySelectorAll('.cc-variant-oracle').forEach((n) => n.remove())
			const host = document.querySelector('.cc-app') || document.body
			for (const v of variants) {
				const zone = document.createElement('div')
				zone.className = 'cc-variant-oracle'
				const a = document.createElement('a')
				a.className = `cc-btn cc-btn--${v} cc-oracle-anchor-${v}`
				a.href = '#'
				a.textContent = 'oracle'
				const b = document.createElement('button')
				b.className = `cc-btn cc-btn--${v} cc-oracle-button-${v}`
				b.textContent = 'oracle'
				zone.appendChild(a)
				zone.appendChild(b)
				host.appendChild(zone)
				// body-mounted dialog twin — the overlay scope that hid the collapse
				const dlg = document.createElement('dialog')
				dlg.className = 'cc-dialog'
				const db = document.createElement('button')
				db.className = `cc-btn cc-btn--${v} cc-oracle-dialog-button-${v}`
				db.textContent = 'oracle'
				dlg.appendChild(db)
				document.body.appendChild(dlg)
				dlg.showModal()
				dlg.close()
			}
		}, VARIANTS)
		// dialog autofocus lands on the first button — focused paint is
		// legitimately the :focus-visible fill, not base; blur before sampling.
		await page.evaluate(() => { if (document.activeElement instanceof HTMLElement) document.activeElement.blur() })
		await page.waitForTimeout(250)
		for (const v of VARIANTS) {
			const anchor = await paintOf(page.locator(`.cc-oracle-anchor-${v}`))
			const button = await paintOf(page.locator(`.cc-oracle-button-${v}`))
			const dialogBtn = await paintOf(page.locator(`.cc-oracle-dialog-button-${v}`))
			expect(button.bg, `${v}: <button> repainted pale vs anchor`).toBe(anchor.bg)
			expect(dialogBtn.bg, `${v}: dialog <button> repainted pale vs anchor`).toBe(anchor.bg)
			expect(button.ink, `${v}: <button> ink drifted vs anchor`).toBe(anchor.ink)
			// pale core fill is not the variant paint — except ghost, whose
			// fill is transparent by design (parity above is the contract).
			if (v === 'ghost') {
				expect(button.bg, `${v}: ghost <button> lost its transparent fill`).toBe('rgba(0, 0, 0, 0)')
			} else {
				expect(button.bg, `${v}: <button> transparent — variant never painted`).not.toBe('rgba(0, 0, 0, 0)')
			}
		}
	})
})
