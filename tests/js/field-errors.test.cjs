#!/usr/bin/env node
/**
 * Behavioural tests for the verbatim-synced field-errors.js
 * (WCAG 3.3.1/3.3.3 contract: aria-invalid + inline error + describedby +
 * first-offender focus + clear-on-edit).
 *
 * Run: node tests/js/field-errors.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'common', 'field-errors.js'), 'utf8');

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
		_focused: false,
		_listeners: {},
		setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'id') this.id = String(v); },
		getAttribute(k) { return this.attrs[k] !== undefined ? this.attrs[k] : null; },
		removeAttribute(k) { delete this.attrs[k]; if (k === 'id') this.id = ''; },
		appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
		remove() { if (this.parentNode) { this.parentNode.removeChild(this); } },
		removeChild(c) { const i = this.children.indexOf(c); if (i !== -1) { this.children.splice(i, 1); c.parentNode = null; } },
		insertAdjacentElement(pos, sibling) {
			if (!this.parentNode) { return sibling; }
			const i = this.parentNode.children.indexOf(this);
			if (pos === 'afterend') { this.parentNode.children.splice(i + 1, 0, sibling); }
			else { this.parentNode.children.splice(i, 0, sibling); }
			sibling.parentNode = this.parentNode;
			return sibling;
		},
		addEventListener(type, fn) { (this._listeners[type] ||= []).push(fn); },
		focus() { this._focused = true; },
		matches(sel) { return matchSel(this, sel); },
		querySelector(sel) { return findOne(this, sel); },
		querySelectorAll(sel) { return findAll(this, sel); },
	};
	return el;
}

function matchSel(el, sel) {
	// Supports: .cls, [attr], [attr="v"], tag, tag[attr], #id — single part only.
	const s = sel.trim();
	if (s.startsWith('#')) { return el.id === s.slice(1); }
	let tag = null, rest = s;
	const tm = rest.match(/^([a-zA-Z][a-zA-Z0-9]*)/);
	if (tm) { tag = tm[1].toUpperCase(); rest = rest.slice(tm[1].length); }
	if (tag && el.tagName !== tag) { return false; }
	const clsRe = /\.([a-zA-Z0-9_-]+)/g;
	let m;
	while ((m = clsRe.exec(rest))) {
		if (!(el.className || '').split(/\s+/).includes(m[1])) { return false; }
	}
	const attrRe = /\[([a-zA-Z0-9_-]+)(?:="([^"]*)")?\]/g;
	while ((m = attrRe.exec(rest))) {
		const v = el.getAttribute(m[1]);
		if (v === null) { return false; }
		if (m[2] !== undefined && v !== m[2]) { return false; }
	}
	return true;
}

function walk(root, fn) {
	for (const c of (root.children || [])) { fn(c); walk(c, fn); }
}

function findAll(root, sel) {
	const parts = sel.split(',').map((s) => s.trim());
	const out = [];
	walk(root, (el) => { if (parts.some((p) => matchSel(el, p))) { out.push(el); } });
	return out;
}
function findOne(root, sel) { return findAll(root, sel)[0] || null; }

function boot() {
	const nodes = new Map();
	const body = makeEl('body');
	const doc = {
		body,
		_listeners: {},
		getElementById(id) {
			if (nodes.has(id)) { return nodes.get(id); }
			let found = null;
			walk(body, (c) => { if (c.id === id && !found) { found = c; } });
			return found;
		},
		querySelector(sel) { return matchSel(body, sel) ? body : findOne(body, sel); },
		querySelectorAll(sel) { return findAll(body, sel); },
		createElement(tag) { return makeEl(tag); },
		addEventListener(type, fn) { (doc._listeners[type] ||= []).push(fn); },
		dispatch(type, ev) { (doc._listeners[type] || []).forEach((fn) => fn(ev)); },
	};
	const sandbox = { document: doc, console, CSS: { escape: (s) => s } };
	sandbox.window = sandbox;
	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox, { filename: 'field-errors.js' });
	return { sandbox, doc, body, nodes };
}

// 1. marks fields: aria-invalid + inline error + describedby link + focus.
{
	const { sandbox, body } = boot();
	sandbox.window.CheckFieldErrors.install({ prefix: 'cc' });
	const form = makeEl('form');
	const input = makeEl('input');
	input.setAttribute('name', 'title');
	form.appendChild(input);
	body.appendChild(form);
	sandbox.window.CheckFieldErrors.markValidationFields({ title: 'Required.' });
	assert(input.getAttribute('aria-invalid') === 'true', 'aria-invalid not set');
	const err = findOne(body, '.cc-field-error');
	assert(err && err.textContent === 'Required.', 'inline error element missing/wrong text');
	assert((input.getAttribute('aria-describedby') || '').includes(err.id), 'aria-describedby not linked');
	assert(input._focused, 'first offender not focused');
}

// 2. clear-on-edit restores describedby and drops the mark.
{
	const { sandbox, doc, body } = boot();
	sandbox.window.CheckFieldErrors.install({ prefix: 'cc' });
	const input = makeEl('input');
	input.setAttribute('name', 'amount');
	input.setAttribute('aria-describedby', 'amount-hint');
	body.appendChild(input);
	sandbox.window.CheckFieldErrors.markValidationFields({ amount: 'Invalid.' });
	assert(input.getAttribute('aria-describedby') === 'amount-hint cc-field-error-amount',
		'describedby did not append error id');
	doc.dispatch('input', { target: input });
	assert(input.getAttribute('aria-invalid') === null, 'aria-invalid not cleared on edit');
	assert(input.getAttribute('aria-describedby') === 'amount-hint', 'original describedby not restored');
}

// 3. unknown keys / empty messages are ignored safely.
{
	const { sandbox, body } = boot();
	sandbox.window.CheckFieldErrors.install({ prefix: 'cc' });
	sandbox.window.CheckFieldErrors.markValidationFields({ ghost: 'x', '': 'y', ok: '' });
	assert(findAll(body, '.cc-field-error').length === 0, 'errors rendered for missing/empty fields');
	sandbox.window.CheckFieldErrors.markValidationFields(null);
	sandbox.window.CheckFieldErrors.markValidationFields('bogus');
	assert(true, 'non-object payload threw');
}

if (failures === 0) { process.stdout.write('field-errors.test.cjs: all assertions passed\n'); }
process.exit(failures === 0 ? 0 : 1);
