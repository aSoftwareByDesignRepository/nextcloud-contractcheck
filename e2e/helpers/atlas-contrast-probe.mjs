// @ts-check
/**
 * ATLAS ds_chrome live contrast probe (contractcheck).
 *
 * Provisions a throwaway manager (theme flips are per-user occ settings and
 * must never touch shared e2e/admin fixtures), logs in, walks representative
 * pages, and measures the COMPUTED WCAG 2.1 contrast of semantic chrome:
 * status badges, primary/danger CTAs, invalid-field borders, and control
 * borders — across light / dark / light-highcontrast user themes.
 *
 *   text ink   >= 4.5:1  (WCAG 1.4.3 AA)
 *   borders    >= 3.0:1  (WCAG 1.4.11)
 *
 * Usage (from the app dir):
 *   node e2e/helpers/atlas-contrast-probe.mjs [--out <path.json>]
 *
 * Requires e2e/.env creds (NC_E2E_USER/PASS) and NC_BASE_URL.
 */
import { writeFileSync, mkdirSync, existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'
import { login, primaryCreds } from './auth.js'
import { provisionManager, deprovisionUser, setUserTheme } from './theme.js'

const HERE = dirname(fileURLToPath(import.meta.url))
const APP_ROOT = resolve(HERE, '../..')
const ENV_PATH = resolve(APP_ROOT, 'e2e/.env')
if (existsSync(ENV_PATH)) {
	for (const line of readFileSync(ENV_PATH, 'utf8').split('\n')) {
		const t = line.trim()
		if (!t || t.startsWith('#')) continue
		const eq = t.indexOf('=')
		if (eq <= 0) continue
		const k = t.slice(0, eq).trim()
		let v = t.slice(eq + 1).trim()
		if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1)
		if (process.env[k] === undefined) process.env[k] = v
	}
}

const BASE = process.env.NC_BASE_URL || 'http://127.0.0.1:8081'
const APP = '/apps/contractcheck'

// ── WCAG contrast helpers (injected into the page for computed colors) ──
const EVAL_FN = String.raw`
function hexToRgb(c) {
  c = c.trim()
  // Chrome serialises color-mix() as color(srgb r g b / a) — floats 0..1.
  if (c.startsWith('color(')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) {
      const s = m.map(parseFloat)
      const scale = s.every((v) => v <= 1) ? 255 : 1
      return [s[0] * scale, s[1] * scale, s[2] * scale]
    }
    return null
  }
  if (c.startsWith('rgb')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) return [parseFloat(m[0]), parseFloat(m[1]), parseFloat(m[2])]
    return null
  }
  if (c.startsWith('#')) {
    let h = c.slice(1)
    if (h.length === 3) h = h.split('').map(x => x + x).join('')
    if (h.length === 4) h = h.split('').map(x => x + x).join('')
    if (h.length === 6 || h.length === 8) {
      return [parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16)]
    }
  }
  return null
}
function lum(rgb) {
  const f = v => {
    v /= 255
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
  }
  return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2])
}
function effBg(el) {
  // Walk ancestors for the first non-transparent background.
  let n = el
  while (n && n !== document.documentElement) {
    const bg = getComputedStyle(n).backgroundColor
    const m = bg && bg.match(/[\d.]+/g)
    if (m && m.length >= 4 && parseFloat(m[3]) > 0) return bg
    if (m && m.length === 3 && !bg.includes('transparent')) return bg
    n = n.parentElement
  }
  return getComputedStyle(document.body).backgroundColor
}
function alphaOf(c) {
  const m = c && c.match(/[\d.]+/g)
  if (m && m.length >= 4) return parseFloat(m[3])
  // color(srgb r g b / a) — fourth channel is the alpha.
  if (c && c.startsWith('color(')) {
    const parts = c.match(/[\d.]+/g)
    if (parts && parts.length >= 4) return parseFloat(parts[3])
  }
  return 1
}
function blend(fgRgb, bgRgb, a) {
  return [
    a * fgRgb[0] + (1 - a) * bgRgb[0],
    a * fgRgb[1] + (1 - a) * bgRgb[1],
    a * fgRgb[2] + (1 - a) * bgRgb[2],
  ]
}
function ratio(fg, bg) {
  const a = hexToRgb(fg), b = hexToRgb(bg)
  if (!a || !b) return null
  const l1 = lum(a), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
function borderRatio(border, bg) {
  const f = hexToRgb(border), b = hexToRgb(bg)
  if (!f || !b) return null
  const alpha = alphaOf(border)
  const eff = alpha >= 1 ? f : blend(f, b, alpha)
  const l1 = lum(eff), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
window.__ccProbe = { effBg, ratio, alphaOf, borderRatio }
`

/** Elements to measure per page: [page_url, [probe rows]] */
const PROBES = [
	{
		page: `${APP}/dashboard`,
		label: 'dashboard',
		rows: [
			{ sel: '.cc-badge', kind: 'badge', what: 'text' },
			{ sel: '.cc-section h3, .cc-card h3, #cc-main-content h2', kind: 'heading', what: 'text' },
		],
	},
	{
		page: `${APP}/contracts`,
		label: 'contracts',
		rows: [
			{ sel: '.cc-badge', kind: 'badge', what: 'text' },
			{ sel: '#cc-new-contract, .cc-btn--primary', kind: 'primary-cta', what: 'text+border' },
			{ sel: '#cc-contract-search, .cc-toolbar .cc-select, .cc-toolbar .cc-input', kind: 'control-border', what: 'border' },
		],
	},
	{
		page: `${APP}/parties`,
		label: 'parties',
		rows: [
			{ sel: '.cc-badge', kind: 'badge', what: 'text' },
			{ sel: '#cc-party-search, .cc-toolbar .cc-input, .cc-toolbar .cc-select', kind: 'control-border', what: 'border' },
		],
	},
	{
		page: `${APP}/audit`,
		label: 'audit',
		rows: [
			{ sel: '.cc-badge', kind: 'badge', what: 'text' },
			{ sel: '#cc-audit-from, #cc-audit-to, .cc-input, .cc-select', kind: 'control-border', what: 'border' },
		],
	},
	{
		page: `${APP}/settings/deadlines`,
		label: 'settings-deadlines',
		rows: [
			{ sel: '.cc-input, .cc-select, .cc-settings-form input, .cc-settings-form select', kind: 'control-border', what: 'border' },
			{ sel: '.cc-btn--primary', kind: 'primary-cta', what: 'text+border' },
		],
	},
]

/** First contract id from the manager's list (confirm-dialog danger CTA lives on detail). */
async function resolveContractDetailPage(page) {
	return page.evaluate(async (app) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { requesttoken: token, Accept: 'application/json' }
		const list = await fetch(`${app}/api/contracts`, { headers, credentials: 'same-origin' })
		const env = list.ok ? await list.json() : {}
		const items = env.data || env.contracts || env.items || []
		const first = items.find((c) => c && c.id)
		return first ? `${app}/contracts/${first.id}` : null
	}, APP)
}

async function measure(page, probes) {
	const results = []
	for (const p of probes) {
		await page.goto(p.page, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#cc-main-content', { timeout: 30_000 })
		await page.locator('.cc-loading:visible').first()
			.waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {})
		if (p.wait) await page.waitForSelector(p.wait, { timeout: 30_000 })
		await page.waitForTimeout(400)
		for (const row of p.rows) {
			const found = await page.evaluate(
				async ({ sel, what }) => {
					const els = Array.from(document.querySelectorAll(sel)).filter(
						(n) => n.offsetParent !== null,
					)
					const out = []
					for (const el of els.slice(0, 6)) {
						const cs = getComputedStyle(el)
						const bg = window.__ccProbe.effBg(el)
						// WCAG 1.4.11 measures a control's border against the
						// ADJACENT surface — the parent chain, not the control's
						// own fill (fill==border would always read 1:1).
						const parentBg = window.__ccProbe.effBg(el.parentElement || el)
						const item = {
							tag: el.tagName.toLowerCase(),
							cls: (el.getAttribute('class') || '').slice(0, 80),
							fg: cs.color,
							bg,
							borderColor: cs.borderColor,
							borderWidth: cs.borderWidth,
						}
						if (what !== 'border') {
							item.textRatio = window.__ccProbe.ratio(cs.color, bg)
						}
						if (what !== 'text' && parseFloat(cs.borderWidth) > 0) {
							item.borderRatio = window.__ccProbe.borderRatio(cs.borderColor, parentBg)
							item.borderAlpha = window.__ccProbe.alphaOf(cs.borderColor)
							// A transparent border is not a defect when the fill itself
							// clears 3:1 vs the surface (boundary carried by fill).
							item.fillVsSurface = window.__ccProbe.ratio(cs.backgroundColor, parentBg)
						}
						out.push(item)
					}
					return out
				},
				{ sel: row.sel, what: row.what },
			)
			results.push({ page: p.label, kind: row.kind, selector: row.sel, what: row.what, found: found.length, samples: found })
		}
	}
	return results
}

/** Danger CTA inside the shared CC.confirmDialog — measured then cancelled. */
async function measureDangerDialog(page, detailPage) {
	if (!detailPage) return { skipped: 'no contract seeded' }
	await page.goto(detailPage, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#cc-main-content', { timeout: 30_000 })
	await page.locator('#cc-detail-loading').waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {})
	const lastAction = page.locator('#cc-detail-actions button').last()
	if (!(await lastAction.isVisible().catch(() => false))) {
		return { skipped: 'no detail actions' }
	}
	await lastAction.click()
	const dialog = page.locator('dialog.cc-dialog.confirm-dialog[open]')
	await dialog.waitFor({ state: 'visible', timeout: 10_000 })
	const out = await page.evaluate(() => {
		const btn = document.querySelector('dialog.cc-dialog.confirm-dialog .cc-btn--danger')
		if (!btn) return { skipped: 'no .cc-btn--danger in confirm dialog' }
		const cs = getComputedStyle(btn)
		const bg = window.__ccProbe.effBg(btn)
		const parentBg = window.__ccProbe.effBg(btn.parentElement || btn)
		return {
			fg: cs.color,
			bg,
			fill: cs.backgroundColor,
			borderColor: cs.borderColor,
			textRatio: window.__ccProbe.ratio(cs.color, cs.backgroundColor),
			fillVsSurface: window.__ccProbe.ratio(cs.backgroundColor, parentBg),
			borderRatio: parseFloat(cs.borderWidth) > 0
				? window.__ccProbe.borderRatio(cs.borderColor, parentBg)
				: null,
		}
	})
	// Cancel — measurement only, never confirm a destructive transition.
	await dialog.locator('.cc-btn--secondary').click().catch(() => page.keyboard.press('Escape'))
	return out
}

async function measureFieldError(page) {
	// Party dialog with empty required name → aria-invalid + .cc-field-error.
	await page.goto(`${APP}/parties`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#cc-main-content', { timeout: 30_000 })
	await page.locator('.cc-loading:visible').first()
		.waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {})
	await page.locator('#cc-party-new').click()
	const dialog = page.locator('#cc-party-dialog')
	await dialog.waitFor({ state: 'visible', timeout: 10_000 })
	await dialog.locator('button[type="submit"]').click()
	const nameInput = dialog.locator('#cc-p-name')
	await page.waitForFunction(
		(el) => el && el.getAttribute('aria-invalid') === 'true',
		await nameInput.elementHandle(),
		{ timeout: 15_000 },
	)
	const out = await page.evaluate(() => {
		const input = document.querySelector('#cc-party-dialog #cc-p-name')
		const err = document.querySelector('#cc-party-dialog .cc-field-error:not([hidden])')
		const out = { ariaInvalid: input && input.getAttribute('aria-invalid') }
		if (err) {
			const cs = getComputedStyle(err)
			out.fieldErrorText = {
				fg: cs.color,
				bg: window.__ccProbe.effBg(err),
				textRatio: window.__ccProbe.ratio(cs.color, window.__ccProbe.effBg(err)),
				text: (err.textContent || '').trim().slice(0, 80),
			}
		}
		const cs = getComputedStyle(input)
		out.invalidBorder = {
			borderColor: cs.borderColor,
			borderWidth: cs.borderWidth,
			bg: window.__ccProbe.effBg(input),
			borderRatio: window.__ccProbe.borderRatio(cs.borderColor, window.__ccProbe.effBg(input)),
		}
		return out
	})
	await dialog.locator('#cc-p-cancel').click().catch(() => page.keyboard.press('Escape'))
	return out
}

async function main() {
	if (!primaryCreds()) throw new Error('NC_E2E_* creds missing (e2e/.env)')
	const mgr = provisionManager()
	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await context.newPage()
	const report = { app: 'contractcheck', probe: 'live-computed-contrast', base: BASE, generated_at: new Date().toISOString(), themes: {} }

	try {
		await login(page, { username: mgr.uid, password: mgr.password })
		await context.addInitScript(EVAL_FN)

		for (const [theme, enabled] of [['light', '[]'], ['dark', '["dark"]'], ['light-highcontrast', '["light-highcontrast"]']]) {
			setUserTheme(mgr.uid, enabled)
			// Resolve per theme — the shared instance mutates under lanes.
			const detailPage = await resolveContractDetailPage(page).catch(() => null)
			const themeRes = { pages: await measure(page, PROBES) }
			themeRes.danger_dialog = await measureDangerDialog(page, detailPage)
			if (theme === 'light') {
				themeRes.field_error_state = await measureFieldError(page)
			}
			report.themes[theme] = themeRes
		}
	} finally {
		await context.close()
		await browser.close()
		deprovisionUser(mgr.uid)
	}

	// Verdict
	const TEXT_MIN = 4.5
	const BORDER_MIN = 3.0
	const findings = []
	for (const [theme, t] of Object.entries(report.themes)) {
		for (const row of t.pages) {
			for (const s of row.samples || []) {
				if (s.textRatio !== undefined && s.textRatio !== null && s.textRatio < TEXT_MIN) {
					findings.push({ theme, page: row.page, kind: row.kind, cls: s.cls, ratio: s.textRatio, min: TEXT_MIN })
				}
				// Boundary (1.4.11): a load-bearing painted border must clear 3:1
				// vs the ADJACENT surface. A transparent/zero border fails only
				// when the fill itself does not carry the boundary (fill<3:1).
				if (s.borderRatio !== undefined && s.borderRatio !== null) {
					const borderPainted = (s.borderAlpha ?? 1) > 0
					if (borderPainted && s.borderRatio < BORDER_MIN) {
						findings.push({ theme, page: row.page, kind: row.kind + '-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
					}
					if (!borderPainted && s.fillVsSurface !== undefined && s.fillVsSurface !== null && s.fillVsSurface < BORDER_MIN) {
						findings.push({ theme, page: row.page, kind: row.kind + '-boundary', cls: s.cls, ratio: s.fillVsSurface, min: BORDER_MIN })
					}
				}
			}
		}
		const dd = t.danger_dialog
		if (dd && !dd.skipped) {
			if (dd.textRatio !== undefined && dd.textRatio !== null && dd.textRatio < TEXT_MIN) {
				findings.push({ theme, page: 'confirm-dialog', kind: 'danger-cta-text', ratio: dd.textRatio, min: TEXT_MIN })
			}
			if (dd.fillVsSurface !== undefined && dd.fillVsSurface !== null && dd.fillVsSurface < BORDER_MIN) {
				findings.push({ theme, page: 'confirm-dialog', kind: 'danger-cta-boundary', ratio: dd.fillVsSurface, min: BORDER_MIN })
			}
		}
		const fe = t.field_error_state
		if (fe) {
			if (fe.fieldErrorText && fe.fieldErrorText.textRatio !== null && fe.fieldErrorText.textRatio < TEXT_MIN) {
				findings.push({ theme, page: 'party-dialog', kind: 'field-error-text', ratio: fe.fieldErrorText.textRatio, min: TEXT_MIN })
			}
			if (fe.invalidBorder && fe.invalidBorder.borderRatio !== null && fe.invalidBorder.borderRatio < BORDER_MIN) {
				findings.push({ theme, page: 'party-dialog', kind: 'invalid-border', ratio: fe.invalidBorder.borderRatio, min: BORDER_MIN })
			}
		}
	}
	report.findings = findings
	report.verdict = findings.length === 0 ? 'PASS' : 'FAIL'

	const outIdx = process.argv.indexOf('--out')
	const outPath = outIdx > 0 ? process.argv[outIdx + 1] : null
	if (outPath) {
		mkdirSync(dirname(outPath), { recursive: true })
		writeFileSync(outPath, JSON.stringify(report, null, 2))
		console.log(`wrote ${outPath}`)
	} else {
		console.log(JSON.stringify(report, null, 2).slice(0, 4000))
	}
	console.log(`contrast probe: ${report.verdict} (${findings.length} findings)`)
	process.exit(findings.length > 0 ? 1 : 0)
}

main().catch((e) => {
	console.error(e)
	process.exit(2)
})
