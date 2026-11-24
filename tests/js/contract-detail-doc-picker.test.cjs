#!/usr/bin/env node
/**
 * Regression pin for the 2026-11-24 viewer dead-CTA defect: the contract
 * detail page rendered + initialized the "link document" picker
 * unconditionally while GET /api/files/search 403s and
 * POST /api/contracts/{id}/documents 404s for non-managers — a control that
 * could only error on every render for viewers.
 *
 * Contract:
 *   1. canEdit=false render → #cc-doc-picker hidden AND CC.initPicker never
 *      called (no search wiring exists that could fire /api/files/search).
 *   2. canEdit=true render → picker visible AND initPicker wired exactly
 *      once (re-renders must not double-bind).
 *   3. Regression: #cc-cost-form keeps its canEdit hidden behaviour.
 *
 * Run: node tests/js/contract-detail-doc-picker.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'pages', 'contract.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) { failures += 1; process.stderr.write('FAIL: ' + msg + '\n'); }
}

function makeEl(tag) {
	const el = {
		tagName: (tag || 'div').toUpperCase(),
		className: '',
		id: '',
		children: [],
		attrs: {},
		parentNode: null,
		textContent: '',
		hidden: false,
		disabled: false,
		value: '',
		_listeners: {},
		setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'id') this.id = String(v); },
		getAttribute(k) { return this.attrs[k] !== undefined ? this.attrs[k] : null; },
		appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
		addEventListener(type, fn) { (this._listeners[type] ||= []).push(fn); },
		reset() {},
		querySelector(sel) { return findOne(this, sel); },
		querySelectorAll(sel) { return findAll(this, sel); },
	};
	Object.defineProperty(el, 'innerHTML', {
		get() { return this._html || ''; },
		set(v) { this._html = v; if (v === '') this.children = []; },
	});
	return el;
}

function matchSel(el, sel) {
	const s = sel.trim();
	if (s.startsWith('.')) { return (el.className || '').split(/\s+/).includes(s.slice(1)); }
	if (s.startsWith('#')) { return el.id === s.slice(1); }
	const m = s.match(/^([a-zA-Z][a-zA-Z0-9]*)(?:\[([a-zA-Z0-9_-]+)(?:="([^"]*)")?\])?$/);
	if (!m) { return false; }
	if (el.tagName !== m[1].toUpperCase()) { return false; }
	if (m[2] !== undefined) {
		const v = el.getAttribute(m[2]);
		if (v === null) { return false; }
		if (m[3] !== undefined && v !== m[3]) { return false; }
	}
	return true;
}
function walk(root, fn) {
	for (const c of (root.children || [])) { fn(c); walk(c, fn); }
}
function findAll(root, sel) {
	const out = [];
	walk(root, (c) => { if (matchSel(c, sel)) { out.push(c); } });
	return out;
}
function findOne(root, sel) { return findAll(root, sel)[0] || null; }

const IDS = [
	'cc-detail-loading', 'cc-detail-root', 'cc-hero-title', 'cc-hero-meta',
	'cc-hero-badges', 'cc-hero-deadline', 'cc-detail-actions', 'cc-d-info',
	'cc-d-costs-panel', 'cc-d-rollup', 'cc-d-costs-body', 'cc-d-costs-table',
	'cc-d-docs', 'cc-d-docs-empty', 'cc-doc-picker', 'cc-doc-search',
	'cc-doc-results', 'cc-doc-kind', 'cc-d-events', 'cc-d-events-empty',
];

function boot(contractPayload) {
	const nodes = new Map();
	const body = makeEl('body');
	const detail = makeEl('section');
	detail.className = 'cc-contract-detail';
	detail.setAttribute('data-contract-id', '42');
	body.appendChild(detail);
	for (const id of IDS) {
		const el = makeEl('div');
		el.id = id;
		el.setAttribute('id', id);
		nodes.set(id, el);
		body.appendChild(el);
	}
	// Mirror the template: picker starts visible, cost form starts hidden.
	nodes.get('cc-doc-picker').className = 'cc-picker';
	const costForm = makeEl('form');
	costForm.id = 'cc-cost-form';
	costForm.setAttribute('id', 'cc-cost-form');
	costForm.hidden = true;
	const submitBtn = makeEl('button');
	submitBtn.setAttribute('type', 'submit');
	costForm.appendChild(submitBtn);
	nodes.set('cc-cost-form', costForm);
	body.appendChild(costForm);

	const apiCalls = [];
	const pickerCalls = [];
	const CC = {
		t: (s) => s,
		role: { manager: contractPayload.canEdit, costViewer: true },
		api(url) {
			apiCalls.push(url);
			if (url.indexOf('/events') !== -1) { return Promise.resolve([]); }
			return Promise.resolve(contractPayload);
		},
		el(tag, attrs, kids) {
			const el = makeEl(tag);
			const a = attrs || {};
			for (const k of Object.keys(a)) {
				if (k === 'text') { el.textContent = a[k]; }
				else if (k === 'class') { el.className = a[k]; }
				else { el.setAttribute(k, a[k]); }
			}
			(kids || []).forEach((c) => el.appendChild(c));
			return el;
		},
		colLabels() { return ['l0', 'l1', 'l2', 'l3']; },
		enumLabel(k, v) { return String(v); },
		fmtDate(v) { return String(v || ''); },
		fmtCents(v) { return String(v); },
		fmtRollup() { return 'rollup'; },
		statusBadge() { return makeEl('span'); },
		daysBadge() { return null; },
		busyGuard() { return { start() { return true; }, end() {} }; },
		handleError() {},
		confirmDialog() { return Promise.resolve(true); },
		appBase() { return '/apps/contractcheck'; },
		parseAmount() { return null; },
		getLeadDays() { return []; },
		initPicker(cfg) { pickerCalls.push(cfg); },
	};
	const doc = {
		body,
		getElementById(id) {
			if (nodes.has(id)) { return nodes.get(id); }
			let found = null;
			walk(body, (c) => { if (c.id === id && !found) { found = c; } });
			return found;
		},
		querySelector(sel) { return matchSel(body, sel) ? body : findOne(body, sel); },
		querySelectorAll(sel) { return findAll(body, sel); },
		createElement(tag) { return makeEl(tag); },
	};
	const sandbox = { document: doc, console, CC };
	sandbox.window = sandbox;
	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox, { filename: 'js/pages/contract.js' });
	return { nodes, apiCalls, pickerCalls };
}

const base = {
	id: 42, title: 'Lease', type: 'rent', scope: 'org', status: 'active',
	partnerName: 'Acme', deadline: { state: 'none' }, costEntries: [],
	documents: [], revision: 1,
};

async function main() {
	// 1. Viewer: picker hidden, never initialized, no search call possible.
	{
		const { nodes, apiCalls, pickerCalls } = boot({ ...base, canEdit: false });
		await new Promise((r) => setTimeout(r, 0));
		assert(nodes.get('cc-doc-picker').hidden === true,
			'viewer: #cc-doc-picker must be hidden');
		assert(pickerCalls.length === 0,
			'viewer: CC.initPicker must not run (got ' + pickerCalls.length + ')');
		assert(!apiCalls.some((u) => u.indexOf('/api/files/search') === 0),
			'viewer: no /api/files/search request may fire');
		assert(nodes.get('cc-cost-form').hidden === true,
			'viewer regression: #cc-cost-form must stay hidden');
	}

	// 2. Manager: picker visible, initialized exactly once across re-renders.
	{
		const { nodes, pickerCalls } = boot({ ...base, canEdit: true });
		await new Promise((r) => setTimeout(r, 0));
		assert(nodes.get('cc-doc-picker').hidden === false,
			'manager: #cc-doc-picker must be visible');
		assert(pickerCalls.length === 1,
			'manager: initPicker wired exactly once (got ' + pickerCalls.length + ')');
		assert(pickerCalls[0] && pickerCalls[0].container === nodes.get('cc-doc-picker'),
			'manager: initPicker bound to #cc-doc-picker');
		assert(nodes.get('cc-cost-form').hidden === false,
			'manager regression: #cc-cost-form must be visible');
	}
}

main().then(() => {
	if (failures) {
		process.stderr.write(failures + ' assertion(s) failed\n');
		process.exit(1);
	}
	process.stdout.write('OK contract-detail doc-picker role gating\n');
});
