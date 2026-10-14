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
 * Throwaway manager user for theme tests. A fresh user in the admin group is
 * a contractcheck manager + cost-viewer by default — the fullest surface —
 * and theme flips on this uid cannot disturb the shared e2e/admin sessions.
 */
export function provisionManager() {
	const uid = 'cctheme' + randomBytes(4).toString('hex')
	const password = 'CcTheme-' + randomBytes(8).toString('hex')
	docker(['-e', `OC_PASS=${password}`, '-u', 'www-data', 'nextcloud',
		'php', 'occ', 'user:add', '--password-from-env', uid])
	docker(['-u', 'www-data', 'nextcloud', 'php', 'occ', 'group:adduser', 'admin', uid])
	return { uid, password }
}

/** Server-rendered theme switch — emulation cannot reach data-theme-*. */
export function setUserTheme(uid, themesJson) {
	docker(['-u', 'www-data', 'nextcloud', 'php', 'occ',
		'user:setting', uid, 'theming', 'enabled-themes', themesJson])
}

export function deprovisionUser(uid) {
	try {
		docker(['-u', 'www-data', 'nextcloud', 'php', 'occ', 'user:delete', uid])
	} catch { /* already gone */ }
}
