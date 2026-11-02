#!/usr/bin/env node
/**
 * Regression pin for the 2026-11-02 shared-hosting 404: appBase() used to
 * return OC.getRootPath() + '/apps/contractcheck', which drops the
 * /index.php front-controller prefix whenever the instance reports
 * modRewriteWorking=false (typical shared hosting). Every CC.api() call
 * and every appBase()-built link 404'd while the page shell still
 * rendered via index.php.
 *
 * Contract:
 *   1. OC.generateUrl is the only URL authority — appBase() must defer
 *      to it so pretty-URL and index.php installs both resolve.
 *   2. Without generateUrl the fallback is webroot + '/index.php/apps/…'
 *      (routable on every install), never the bare '/apps/…' form.
 *   3. Subdirectory webroots keep their prefix in both paths.
 *
 * Run: node tests/js/app-base.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'app.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

function loadKernel(oc) {
	const sandbox = {
		window: { OC: oc },
		OC: oc,
	};
	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox, { filename: 'js/app.js' });
	return sandbox.window.CC;
}

// Realistic OC.generateUrl stand-in mirroring @nextcloud/router:
// modRewriteWorking=false → webroot + '/index.php' + url.
function makeOC(webroot, modRewriteWorking) {
	const oc = {
		getRootPath() { return webroot; },
		get modRewriteWorking() { return modRewriteWorking; },
	};
	if (modRewriteWorking !== undefined) {
		oc.generateUrl = function (url, params) {
			let out = url;
			for (const key of Object.keys(params || {})) {
				out = out.split('{' + key + '}').join(encodeURIComponent(String(params[key])));
			}
			return webroot + (modRewriteWorking ? '' : '/index.php') + out;
		};
	}
	return oc;
}

// 1. Pretty URLs on: generateUrl is authoritative.
{
	const CC = loadKernel(makeOC('', true));
	assert(CC.appBase() === '/apps/contractcheck',
		'modRewriteWorking=true → /apps/contractcheck, got ' + CC.appBase());
}

// 2. Pretty URLs off (shared hosting): index.php prefix mandatory —
//    this is the regression being pinned.
{
	const CC = loadKernel(makeOC('', false));
	assert(CC.appBase() === '/index.php/apps/contractcheck',
		'modRewriteWorking=false → /index.php/apps/contractcheck, got ' + CC.appBase());
}

// 3. Subdirectory install + no rewrites: webroot preserved.
{
	const CC = loadKernel(makeOC('/cloud', false));
	assert(CC.appBase() === '/cloud/index.php/apps/contractcheck',
		'subdir + no rewrite → /cloud/index.php/apps/contractcheck, got ' + CC.appBase());
}

// 4. No generateUrl at all: fallback must still emit the index.php form.
{
	const CC = loadKernel(makeOC('', undefined));
	assert(CC.appBase() === '/index.php/apps/contractcheck',
		'no generateUrl → /index.php/apps/contractcheck, got ' + CC.appBase());
}

// 5. No generateUrl + subdir: webroot still applies.
{
	const CC = loadKernel(makeOC('/cloud', undefined));
	assert(CC.appBase() === '/cloud/index.php/apps/contractcheck',
		'no generateUrl + subdir → /cloud/index.php/apps/contractcheck, got ' + CC.appBase());
}

// 6. appBase() result must never be the bare-webroot '/apps/…' shape that
//    404s under index.php routing: the '/index.php' segment is present
//    whenever generateUrl is absent.
{
	const CC = loadKernel(makeOC('/deep/root', undefined));
	assert(CC.appBase().indexOf('/index.php/apps/') !== -1,
		'fallback must contain /index.php/apps/, got ' + CC.appBase());
}

// 7. fileUrl() rides the same authority — /f/<id> is a core route, so it
//    needs the index.php prefix on non-rewriting installs as well.
{
	const CC = loadKernel(makeOC('', false));
	assert(CC.fileUrl(42) === '/index.php/f/42',
		'fileUrl + no rewrite → /index.php/f/42, got ' + CC.fileUrl(42));
}
{
	const CC = loadKernel(makeOC('/cloud', undefined));
	assert(CC.fileUrl('7') === '/cloud/index.php/f/7',
		'fileUrl fallback + subdir → /cloud/index.php/f/7, got ' + CC.fileUrl('7'));
}

if (failures) {
	process.stderr.write(failures + ' failure(s)\n');
	process.exit(1);
}
process.stdout.write('app-base: all assertions passed\n');
