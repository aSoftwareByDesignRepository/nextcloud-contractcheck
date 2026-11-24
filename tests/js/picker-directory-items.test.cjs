#!/usr/bin/env node
/**
 * Regression pin for the 2026-11-24 settings/access defect (Johan, 1.0.3):
 * the picker consumed search results as {id,label} while every directory
 * endpoint returns its own shape — {uid,displayName} (users),
 * {gid,displayName} (groups), {fileId,name} (files). Result rows rendered
 * with an empty label and data-id="undefined", emitted undefined ids into
 * the settings save payload, and looked like "group isn't found".
 *
 * Contract:
 *   1. Group/user/file rows are normalized inside initPicker — every
 *      result row carries its real id and a non-empty label.
 *   2. Choosing a row emits the real gid/uid/fileId through onChange.
 *   3. Enter/Space on a focused option chooses it (state.lastItems was
 *      never populated — keyboard selection was dead, WCAG 2.1.1).
 *   4. Rows without any id are dropped instead of rendering "undefined".
 *
 * Run: node tests/js/picker-directory-items.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'app.js'), 'utf8');

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
		focus() {},
		contains(node) { return node === this || (node && node.parentNode && this.contains(node.parentNode)); },
		fire(type, ev) { (this._listeners[type] || []).forEach((f) => f(ev || { preventDefault() {} })); },
	};
	Object.defineProperty(el, 'innerHTML', {
		get() { return this._html || ''; },
		set(v) { this._html = v; if (v === '') this.children = []; },
	});
	Object.defineProperty(el, 'firstChild', { get() { return this.children[0] || null; } });
	Object.defineProperty(el, 'nextSibling', {
		get() {
			if (!this.parentNode) return null;
			const i = this.parentNode.children.indexOf(this);
			return this.parentNode.children[i + 1] || null;
		},
	});
	Object.defineProperty(el, 'previousSibling', {
		get() {
			if (!this.parentNode) return null;
			const i = this.parentNode.children.indexOf(this);
			return this.parentNode.children[i - 1] || null;
		},
	});
	return el;
}

function bootPicker(searchResult) {
	const document = {
		activeElement: null,
		_listeners: {},
		createElement(tag) { return makeEl(tag); },
		querySelector() { return null; },
		addEventListener(type, fn) { (this._listeners[type] ||= []).push(fn); },
	};
	const sandbox = { window: {}, document, console, AbortController };
	sandbox.window.document = document;
	sandbox.window.setTimeout = setTimeout;
	sandbox.window.clearTimeout = clearTimeout;
	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox, { filename: 'js/app.js' });
	const CC = sandbox.window.CC;

	const emitted = [];
	const nodes = {
		container: makeEl('div'),
		input: makeEl('input'),
		results: makeEl('ul'),
		chips: makeEl('div'),
	};
	const picker = CC.initPicker({
		container: nodes.container,
		input: nodes.input,
		results: nodes.results,
		chips: nodes.chips,
		hidden: null,
		multiple: true,
		search() { return Promise.resolve(searchResult); },
		onChange(ids) { emitted.push(ids); },
	});
	nodes.container.appendChild(nodes.input);
	nodes.container.appendChild(nodes.results);
	nodes.container.appendChild(nodes.chips);
	return { CC, nodes, emitted, document, picker };
}

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

async function typeAndSearch(nodes) {
	nodes.input.value = 'adm';
	nodes.input.fire('input');
	await sleep(320); // 250ms debounce + promise resolution
}

(async () => {
	// 1+2. Groups: {gid,displayName} rows render real id + label, click
	// emits the gid — before the fix every row was data-id="undefined".
	{
		const { nodes, emitted } = bootPicker([
			{ gid: 'contractmanagers', displayName: 'Contract managers' },
			{ gid: 'admin', displayName: 'admin' },
		]);
		await typeAndSearch(nodes);
		assert(nodes.results.hidden === false, 'results must open for matches');
		assert(nodes.results.children.length === 2, 'two options rendered');
		const opt = nodes.results.children[0];
		assert(opt.getAttribute('data-id') === 'contractmanagers',
			'option data-id must be the gid, got ' + opt.getAttribute('data-id'));
		assert(opt.children[0] && opt.children[0].textContent === 'Contract managers',
			'option must render the displayName');
		opt.fire('mousedown', { preventDefault() {} });
		assert(emitted.length === 1 && emitted[0][0] === 'contractmanagers',
			'onChange must emit the gid, got ' + JSON.stringify(emitted));
		const chipLabel = nodes.chips.children[0] && nodes.chips.children[0].children[0];
		assert(chipLabel && chipLabel.textContent === 'Contract managers',
			'chip must render the displayName');
	}

	// 3. Keyboard: Enter on a focused option chooses it — the old code
	// looked up cfg.searchLastItem, which nothing ever populated.
	{
		const { nodes, emitted, document } = bootPicker([
			{ gid: 'legal', displayName: 'Legal team' },
		]);
		await typeAndSearch(nodes);
		const opt = nodes.results.children[0];
		document.activeElement = opt;
		nodes.results.fire('keydown', { key: 'Enter', preventDefault() {} });
		assert(emitted.length === 1 && emitted[0][0] === 'legal',
			'Enter must choose the focused option, got ' + JSON.stringify(emitted));
	}

	// 2b. Files: {fileId:int,name} → emits the numeric fileId (documents
	// POST expects an int — String() here would break paramId).
	{
		const { nodes, emitted } = bootPicker([
			{ fileId: 42, name: 'mietvertrag.pdf', path: '/docs/mietvertrag.pdf', mime: 'application/pdf' },
		]);
		await typeAndSearch(nodes);
		const opt = nodes.results.children[0];
		assert(opt.getAttribute('data-id') === '42', 'file option data-id');
		assert(opt.children[0] && opt.children[0].textContent === 'mietvertrag.pdf',
			'file option shows the name');
		opt.fire('mousedown', { preventDefault() {} });
		assert(emitted[0][0] === 42, 'fileId must stay numeric, got ' + JSON.stringify(emitted));
	}

	// 4. Rows without any id are dropped — never render "undefined".
	{
		const { nodes } = bootPicker([
			{ displayName: 'no id here' },
			{ uid: 'j.smith', displayName: 'Jo Smith' },
		]);
		await typeAndSearch(nodes);
		assert(nodes.results.children.length === 1, 'id-less rows dropped');
		assert(nodes.results.children[0].getAttribute('data-id') === 'j.smith',
			'user row carries the uid');
	}

	// 5. Raw {id,label} items still work (the picker's original contract —
	// setSelected/emit paths must not regress for already-normal rows).
	{
		const { nodes, emitted } = bootPicker([{ id: 'x', label: 'Already fine' }]);
		await typeAndSearch(nodes);
		nodes.results.children[0].fire('mousedown', { preventDefault() {} });
		assert(emitted[0][0] === 'x', 'native {id,label} rows keep working');
	}
})().then(() => {
	if (failures) { process.stderr.write(failures + ' failure(s)\n'); process.exit(1); }
	process.stdout.write('picker-directory-items: all assertions passed\n');
}).catch((e) => { process.stderr.write('ERROR: ' + e.stack + '\n'); process.exit(1); });
