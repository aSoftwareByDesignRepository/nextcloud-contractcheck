// @ts-check
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const composeDir = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..')

/**
 * Stack helpers for the mariadb dev stack (:8081) — `php occ` and SQL against
 * the compose services from nextcloud/. Used by specs that must drive the
 * background-job queue or assert persisted rows directly.
 */

/** Run `php occ <args>` as www-data. @param {string[]} args */
export function occ(args, env = {}) {
	const envFlags = Object.entries(env).flatMap(([k, v]) => ['-e', `${k}=${v}`])
	return execFileSync('docker', ['compose', 'exec', '-T', ...envFlags, '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args], {
		cwd: composeDir, stdio: 'pipe', timeout: 60000, encoding: 'utf8',
	})
}

/** Run SQL against the stack database. Returns trimmed stdout. */
export function queryDb(sql) {
	return execFileSync('docker', ['compose', 'exec', '-T', 'mariadb',
		'mariadb', '-N', '-unextcloud', '-pnextcloud_password', 'nextcloud', '-e', sql], {
		cwd: composeDir, stdio: 'pipe', timeout: 30000, encoding: 'utf8',
	}).trim()
}

/** Best-effort queryDb that swallows a missing/unreachable stack. */
export function queryDbSafe(sql) {
	try {
		return queryDb(sql)
	} catch {
		return ''
	}
}
