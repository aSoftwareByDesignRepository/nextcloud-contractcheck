// @ts-check
import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const composeDir = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..')

function docker(args) {
	execFileSync('docker', ['compose', 'exec', '-T', ...args], {
		cwd: composeDir, stdio: 'pipe', timeout: 60000,
	})
}

/**
 * occ is a shared-instance single point of failure: any enabled app's PHP
 * fatal in console-command loading (observed 20261109 — a sibling lane's
 * half-written class bricked `occ` mid-suite, taking every provisioning
 * call with it) kills this helper while HTTP stays healthy. When the first
 * occ call of the process fails, subsequent calls go straight to the HTTP
 * fallbacks instead of paying a 60s exec timeout each:
 *   user create/delete → OCS provisioning API (admin basic auth)
 *   user:setting       → direct oc_preferences row (mariadb, no PHP at all)
 */
let occDead = false
const occFailed = () => { occDead = true }
const NC_BASE = (process.env.NC_BASE_URL || 'http://127.0.0.1:8081').replace(/\/$/, '')

function curlOcs(method, path, fields = {}) {
	const user = process.env.NC_ADMIN_USER
	const pass = process.env.NC_ADMIN_PASS
	if (!user || !pass) {
		throw new Error('OCS provisioning fallback needs NC_ADMIN_* credentials')
	}
	const args = [
		'-sfS', '-m', '30', '-u', `${user}:${pass}`,
		'-H', 'OCS-APIRequest: true', '-X', method, `${NC_BASE}${path}`,
	]
	for (const [k, v] of Object.entries(fields)) {
		args.push('--data-urlencode', `${k}=${v}`)
	}
	const out = execFileSync('curl', args, { encoding: 'utf8', timeout: 40_000 })
	if (!/<status>ok<\/status>|"status"\s*:\s*"ok"/.test(out)) {
		throw new Error(`OCS ${method} ${path} failed: ${out.slice(0, 200)}`)
	}
}

/** REPLACE INTO oc_preferences — no PHP involved, immune to app fatals. */
function dbSetUserPref(uid, appid, key, value) {
	const sql = `REPLACE INTO oc_preferences (userid,appid,configkey,configvalue) ` +
		`VALUES ('${uid}','${appid}','${key}','${value.replace(/'/g, "''")}')`
	docker(['mariadb', 'bash', '-lc',
		`mariadb -u root -p"$MYSQL_ROOT_PASSWORD" nextcloud -e "${sql.replace(/"/g, '\\"')}"`])
}

/**
 * Throwaway manager user for theme tests. A fresh user in the admin group is
 * a contractcheck manager + cost-viewer by default — the fullest surface —
 * and theme flips on this uid cannot disturb the shared e2e/admin sessions.
 */
export function provisionManager() {
	const uid = 'cctheme' + randomBytes(4).toString('hex')
	const password = 'CcTheme-' + randomBytes(8).toString('hex')
	if (!occDead) {
		try {
			docker(['-e', `OC_PASS=${password}`, '-u', 'www-data', 'nextcloud',
				'php', 'occ', 'user:add', '--password-from-env', uid])
			docker(['-u', 'www-data', 'nextcloud', 'php', 'occ', 'group:adduser', 'admin', uid])
			return { uid, password }
		} catch {
			occFailed()
		}
	}
	curlOcs('POST', '/ocs/v1.php/cloud/users', {
		userid: uid, password, 'groups[]': 'admin',
	})
	return { uid, password }
}

/** Server-rendered theme switch — emulation cannot reach data-theme-*. */
export function setUserTheme(uid, themesJson) {
	if (!occDead) {
		try {
			docker(['-u', 'www-data', 'nextcloud', 'php', 'occ',
				'user:setting', uid, 'theming', 'enabled-themes', themesJson])
			return
		} catch {
			occFailed()
		}
	}
	dbSetUserPref(uid, 'theming', 'enabled-themes', themesJson)
}

export function deprovisionUser(uid) {
	if (!uid) {
		return
	}
	if (!occDead) {
		try {
			docker(['-u', 'www-data', 'nextcloud', 'php', 'occ', 'user:delete', uid])
			return
		} catch {
			occFailed()
		}
	}
	try {
		curlOcs('DELETE', `/ocs/v1.php/cloud/users/${uid}`)
	} catch { /* already gone */ }
}
