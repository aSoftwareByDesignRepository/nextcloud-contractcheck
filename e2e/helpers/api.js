// @ts-check
/**
 * In-page API helper — runs fetch inside the app session so the requesttoken
 * and CSRF dance is handled by the app's own OC.requestToken plumbing.
 */
export async function apiCall(page, method, path, body) {
	return page.evaluate(async ({ method, path, body }) => {
		const headers = { 'OCS-APIRequest': 'true' }
		if (window.OC && OC.requestToken) headers['requesttoken'] = OC.requestToken
		if (body !== undefined) headers['Content-Type'] = 'application/json'
		const res = await fetch(`${OC.webroot}/apps/contractcheck${path}`, {
			method,
			headers,
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		let json = null
		try { json = await res.json() } catch { /* empty body */ }
		return { status: res.status, json }
	}, { method, path, body })
}

export async function apiGet(page, path) {
	const r = await apiCall(page, 'GET', path)
	if (r.status !== 200 || !r.json?.success) throw new Error(`GET ${path} → ${r.status} ${JSON.stringify(r.json)}`)
	return r.json.data
}

export async function apiPost(page, path, body) {
	const r = await apiCall(page, 'POST', path, body)
	if (!r.json?.success) throw new Error(`POST ${path} → ${r.status} ${JSON.stringify(r.json)}`)
	return r.json.data
}

export function uniqueTitle(prefix = 'E2E Contract') {
	return `${prefix} ${Date.now()}-${Math.floor(Math.random() * 1e4)}`
}

/**
 * Settings writes are globally shared CAS — parallel e2e workers will
 * legitimately conflict. Read → patch → save, retrying on 409.
 * `patch` receives the current settings object and returns the next one.
 */
export async function saveSettings(page, patch) {
	for (let i = 0; i < 6; i++) {
		const s = await apiGet(page, '/api/settings')
		const r = await apiCall(page, 'POST', '/api/settings', {
			version: s.version,
			settings: patch(s.settings),
		})
		if (r.status === 200 && r.json?.success) return r.json.data
		if (r.status !== 409) throw new Error(`POST /api/settings → ${r.status} ${JSON.stringify(r.json)}`)
	}
	throw new Error('POST /api/settings → persistent CAS conflicts')
}

/**
 * Contracts can't be deleted via the API — archive them so finished specs
 * don't leave the register (and any rollups) polluted across runs.
 */
export async function archiveContract(page, id) {
	if (!id) return
	const cur = await apiGet(page, `/api/contracts/${id}`).catch(() => null)
	if (!cur || cur.status === 'archived') return
	await apiCall(page, 'POST', `/api/contracts/${id}/transition`, {
		action: 'archive', revision: cur.revision,
	})
}
