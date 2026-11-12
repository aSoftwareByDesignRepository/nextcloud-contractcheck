import { defineConfig, devices } from '@playwright/test'
import { existsSync, readFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const configDir = dirname(fileURLToPath(import.meta.url))
const envFile = resolve(configDir, 'e2e/.env')
if (existsSync(envFile)) {
	for (const line of readFileSync(envFile, 'utf8').split('\n')) {
		const trimmed = line.trim()
		if (!trimmed || trimmed.startsWith('#')) {
			continue
		}
		const eq = trimmed.indexOf('=')
		if (eq <= 0) {
			continue
		}
		const key = trimmed.slice(0, eq).trim()
		let value = trimmed.slice(eq + 1).trim()
		if (
			(value.startsWith('"') && value.endsWith('"'))
			|| (value.startsWith("'") && value.endsWith("'"))
		) {
			value = value.slice(1, -1)
		}
		if (process.env[key] === undefined) {
			process.env[key] = value
		}
	}
}

const baseURL = process.env.NC_BASE_URL || 'http://127.0.0.1:8081'

export default defineConfig({
	testDir: 'e2e',
	globalTeardown: './e2e/global-teardown.mjs',

	globalSetup: './e2e/global-setup.js',
	timeout: 60_000,
	expect: { timeout: 15_000 },
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium-1280',
			testIgnore: ['access-door.spec.js', 'craft-transfer.spec.js'],
			use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
		},
		{
			name: 'chromium-320',
			testIgnore: ['access-door.spec.js', 'craft-transfer.spec.js'],
			use: { ...devices['Desktop Chrome'], viewport: { width: 320, height: 640 } },
		},
		{
			name: 'chromium-store',
			testIgnore: ['access-door.spec.js', 'craft-transfer.spec.js'],
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1920, height: 1040 },
				deviceScaleFactor: 1,
			},
		},
		// The access-door spec narrows `allowedGroups` instance-wide: every
		// non-admin fixture user is denied for the mutation window. Inside the
		// parallel pool that races sibling specs (a ccsmoke-context load
		// mid-window lands on the 403 page) — instance_wide_state_race. Run it
		// once, serialized after the suite via `dependencies` (azc stateful-N
		// precedent). Targeted local re-run: `--project=chromium-door --no-deps`.
		{
			name: 'chromium-door',
			testMatch: 'access-door.spec.js',
			dependencies: ['chromium-1280', 'chromium-320', 'chromium-store'],
			use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
		},
		// The transfer apply legs rewrite the shared cc_settings CAS doc and
		// upsert every business row — running them inside the parallel pool
		// races settings-writing and contract-archiving specs
		// (instance_wide_state_race). Serialize after everything, including
		// the door window (same convention as chromium-door).
		// Targeted local re-run: `--project=chromium-transfer --no-deps`.
		{
			name: 'chromium-transfer',
			testMatch: 'craft-transfer.spec.js',
			dependencies: ['chromium-door'],
			use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
		},
	],
})
