/**
 * scripts/check-form-colors.mjs
 *
 * Diff warna MEKANIS antara markup prototipe (phase1/observasi.html baris
 * 555-1194, blok ModalFormulirObservasiEWS) dan implementasi Vue-nya.
 *
 * Cara kerjanya BUKAN membandingkan teks kelas, karena urutan utility Tailwind
 * di CSS hasil build bisa membuat kelas yang ditulis benar tetap kalah. Kedua
 * sisi dipecah lewat CSS HASIL BUILD yang sama persis dengan yang dipakai
 * browser:
 *
 *   1. CSS dimuat sesuai urutan injeksi Vite (manifest assets/app.js -> css[]):
 *      asset scoped-SFC lebih dulu, baru app.css (Tailwind + @layer components).
 *   2. Setiap rule dipecah jadi (selector, deklarasi, posisi). Untuk tiap
 *      elemen yang diuji, semua rule yang selector compound terakhirnya cocok
 *      dengan class/atribut elemen + state aktif (hover/focus) dikumpulkan.
 *   3. Pemenang tiap properti diambil dengan urutan (spesifisitas, posisi),
 *      persis seperti cascade browser.
 *   4. Nilai dinormalisasi (hex/rgb/rgba + opacity Tailwind) lalu dibandingkan.
 *
 * Status per jangkar:
 *   MATCH     warna efektif identik
 *   MISMATCH  warna efektif berbeda
 *   MISSING   kelas Vue tidak punya rule apa pun di CSS build, jadi warna itu
 *             tidak pernah benar-benar dipaint (jebakan purge yang pernah
 *             menimpa proyek ini)
 *
 * Exit code 0 bila semua jangkar MATCH, 1 bila ada MISMATCH / kelas tak
 * tervalidasi.
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const BUILD = path.join(ROOT, 'public', 'build');
const PROTO = path.resolve(ROOT, '..', 'phase1', 'observasi.html');

/* --------------------------------------------------------------- css loader */

function manifestCssFiles() {
    const manifest = JSON.parse(fs.readFileSync(path.join(BUILD, 'manifest.json'), 'utf8'));
    const fromEntry = (manifest['resources/js/app.js'] || {}).css || [];
    const fromCss = Object.values(manifest)
        .filter((e) => e.isEntry && e.file && e.file.endsWith('.css'))
        .map((e) => e.file);

    return [...new Set([...fromEntry, ...fromCss])]
        .map((f) => path.join(BUILD, f))
        .filter((f) => fs.existsSync(f));
}

function stripComments(css) {
    return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

function parseRules(css, offsetBase, out) {
    let i = 0;
    while (i < css.length) {
        const open = css.indexOf('{', i);
        if (open < 0) break;
        const sel = css.slice(i, open).trim();
        if (sel.startsWith('@')) { i = open + 1; continue; }
        let depth = 1;
        let j = open + 1;
        while (j < css.length && depth > 0) {
            if (css[j] === '{') depth++;
            else if (css[j] === '}') depth--;
            j++;
        }
        const body = css.slice(open + 1, j - 1);
        if (!body.includes('{')) out.push({ sel, body, pos: offsetBase + i });
        i = j;
    }
    return out;
}

const cssFiles = manifestCssFiles();
const rules = [];
let cursor = 0;
for (const file of cssFiles) {
    const css = stripComments(fs.readFileSync(file, 'utf8'));
    parseRules(css, cursor, rules);
    cursor += css.length;
}
const CSS_FILES = cssFiles.map((f) => path.basename(f));
const SCOPES = cssFiles.map((f) => fs.readFileSync(f, 'utf8')).join('\n').match(/data-v-[0-9a-f]+/g) || [];
const universalBase = rules.find((r) => r.sel.split(',')[0].trim().startsWith('*') && r.body.includes('--tw-ring-color'));

/* ------------------------------------------------------------- normalisasi */

const OPAQUE = /--tw-(bg|text|border|ring|ring-offset)-opacity:\s*1\b/;

function normalise(value, body) {
    const v = String(value).trim();
    if (v === 'inherit' || v === 'currentColor' || v === 'none' || v === 'initial') return v;
    if (v.startsWith('#')) {
        const hex = v.slice(1);
        if (hex.length === 3) return 'rgba(' + [...hex].map((c) => parseInt(c + c, 16)).join(',') + ',1)';
        if (hex.length === 6) return 'rgba(' + [0, 2, 4].map((k) => parseInt(hex.slice(k, k + 2), 16)).join(',') + ',1)';
        if (hex.length === 8) {
            const p = [0, 2, 4, 6].map((k) => parseInt(hex.slice(k, k + 2), 16));
            return 'rgba(' + p[0] + ',' + p[1] + ',' + p[2] + ',' + (Math.round((p[3] / 255) * 1000) / 1000) + ')';
        }
    }
    const m = v.match(/^rgba?\(([\s\S]*)\)$/);
    if (m) {
        const parts = m[1].replace(/\//g, ' ').split(/[,\s]+/).filter(Boolean);
        const rgb = parts.slice(0, 3).join(',');
        let a = parts.length > 3 ? parts[3] : '1';
        if (/var\(--tw-/.test(a)) a = body && OPAQUE.test(body) ? '1' : '0.5';
        return 'rgba(' + rgb + ',' + a + ')';
    }
    return v;
}

function decls(body) {
    const out = {};
    for (const part of body.split(';')) {
        const idx = part.indexOf(':');
        if (idx < 0) continue;
        const k = part.slice(0, idx).trim();
        if (!k) continue;
        if (k.startsWith('--tw-')) continue;
        out[k] = part.slice(idx + 1).trim();
    }
    const ring = body.match(/--tw-ring-color:\s*([^;]+)/);
    if (ring) out['--tw-ring-color'] = ring[1].trim();
    if ('border' in out) {
        const sh = out.border.trim();
        const width = sh.match(/^([\d.]+[a-z]*)/);
        const colour = sh.replace(/^[\d.]+[a-z]*\s*(solid|dashed|dotted|double|none)?\s*/, '');
        if (width) out['border-width'] = width[1];
        if (colour) out['border-color'] = colour.trim();
        delete out.border;
    }
    return out;
}

/* --------------------------------------------------------------- selector */

function specificity(sel) {
    const ids = (sel.match(/#[\w-]+/g) || []).length;
    const cls = (sel.match(/\.[\w\\-]+/g) || []).length
        + (sel.match(/\[[^\]]+\]/g) || []).length
        + (sel.match(/:(?!:)[\w-]+/g) || []).length;
    const types = (sel.match(/(^|[\s>+~])[a-z][\w-]*/gi) || []).length
        + (sel.match(/::[\w-]+/g) || []).length;
    return [ids, cls, types];
}

const cmp = (a, b) => (a[0] - b[0]) || (a[1] - b[1]) || (a[2] - b[2]);

const NEEDED_ATTRS = ['type', 'id', 'name', 'readonly', 'disabled', 'value'];

function compoundMatches(compound, el, state) {
    const tokens = compound.match(/\.(?:[\w-]|\\.)+|#(?:[\w-]|\\.)+|\[[^\]]*\]|:{1,2}[\w-]+|\*|[a-z][\w-]*/g) || [];
    for (const t of tokens) {
        if (t === '*') continue;
        if (t.startsWith('.')) {
            const name = t.slice(1).replace(/\\(.)/g, '$1');
            if (!el.classes.has(name)) return false;
            continue;
        }
        if (t.startsWith('#')) { if (el.id !== t.slice(1)) return false; continue; }
        if (t.startsWith('[')) {
            const m = t.slice(1, -1).match(/^([\w-]+)(?:[~|^$*]?=("?)([^\]"]*)\2)?$/);
            if (!m) continue;
            const attr = m[1];
            const value = m[3];
            if (attr in el.attrs) { if (value !== undefined && el.attrs[attr] !== value) return false; } else if (NEEDED_ATTRS.indexOf(attr) >= 0 || attr.startsWith('data-')) return false;
            continue;
        }
        if (t.startsWith('::')) return false;
        if (t.startsWith(':')) {
            if (t === ':root' || t === ':host') continue;
            if (state.split(',').indexOf(t.slice(1)) < 0) return false;
            continue;
        }
        if (!el.tag || el.tag !== t) return false;
    }
    return true;
}

function selectorMatches(sel, el, state) {
    const parts = sel.trim().split(/\s*[>+~]\s*|\s+/).filter(Boolean);
    if (parts.length === 0) return false;
    return compoundMatches(parts[parts.length - 1], el, state);
}

/* ----------------------------------------------------------------- resolve */

const UA_WHITE = ['input:text', 'input:number', 'input:date', 'input:time', 'input:datetime-local', 'select:', 'textarea:', 'input:'];
const PROPS = ['background-color', 'color', 'border-color', '--tw-ring-color', 'box-shadow'];

function resolve(classList, options) {
    const opts = options || {};
    const state = opts.state || '';
    const attrs = Object.assign({}, opts.attrs || {});
    if (opts.scoped) for (const s of SCOPES) attrs[s] = '';
    const el = { classes: new Set(classList.split(/\s+/).filter(Boolean)), state, tag: opts.tag || '', id: opts.id || '', attrs };
    const best = {};
    const consider = (rule, sel) => {
        if (!selectorMatches(sel, el, state)) return;
        const spec = specificity(sel);
        const d = decls(rule.body);
        for (const p of PROPS) {
            if (!(p in d)) continue;
            const cand = { value: d[p], spec, pos: rule.pos, body: rule.body };
            const cur = best[p];
            if (!cur || cmp(cand.spec, cur.spec) > 0 || (cmp(cand.spec, cur.spec) === 0 && cand.pos > cur.pos)) best[p] = cand;
        }
    };
    if (universalBase) for (const sel of universalBase.sel.split(',')) consider(universalBase, sel.trim());
    for (const rule of rules) for (const sel of rule.sel.split(',')) consider(rule, sel.trim());
    const out = {};
    for (const p of PROPS) {
        if (!best[p]) {
            // Kontrol formulir klasik tanpa deklarasi backgroundcolour mengambil
            // `background-color: field` dari UA, yaitu putih - persis yang dirender
            // prototipe. Tanpa ini input prototipe terlihat seperti tidak berwarna.
            out[p] = p === 'background-color' && UA_WHITE.indexOf(el.tag + ':' + (attrs.type || '')) >= 0
                ? 'rgba(255,255,255,1)'
                : null;
            continue;
        }
        let value = normalise(best[p].value, best[p].body);
        // color: inherit pada prototipe mewarisi text-slate-800 dari <form>,
        // jadi dibandingkan sebagai warna yang sama dengan deklarasi eksplisit.
        if (p === 'color' && value === 'inherit') value = 'rgba(30,41,59,1)';
        // bayangan tidak dibandingkan byte-per-byte: yang penting jenisnya, karena
        // shadow-sm (luar) dan shadow-inner (dalam) terlihat berbeda jauh.
        if (p === 'box-shadow') {
            if (!value) value = null;
            else value = /inset|--tw-shadow-inset/.test(value) ? 'shadow:inset' : 'shadow:outer';
        }
        out[p] = value;
    }
    return out;
}

/* ------------------------------------------------------------ class lookup */

const emittedCache = new Map();

function ruleFor(className) {
    if (emittedCache.has(className)) return emittedCache.get(className);
    const escaped = '.' + className.replace(/([:/[\]%.#()])/g, '\\$1');
    let hit = null;
    for (const rule of rules) {
        for (const sel of rule.sel.split(',')) {
            const s = sel.trim();
            const last = s.split(/\s*[>+~]\s*|\s+/).pop();
            if (last.indexOf(escaped) === 0 && !/[\w\\-]/.test(last.charAt(escaped.length) || '')) { hit = rule; break; }
        }
        if (hit) break;
    }
    emittedCache.set(className, hit);
    return hit;
}

const COLOURY = /^(?:[a-z-]+:)*(?:bg|text|border|ring|placeholder|divide|from|via|to|fill|stroke|shadow|outline|decoration|accent|caret)-/;

function classesNotEmitted(list) {
    return list.split(/\s+/)
        .filter(Boolean)
        .filter((c) => COLOURY.test(c))
        .filter((c) => !ruleFor(c));
}

/* ---------------------------------------------------------------- anchors */

const protoLines = fs.readFileSync(PROTO, 'utf8');
const protoTokens = new Set((protoLines.match(/class="([^"]*)"/g) || [])
    .flatMap((a) => a.slice(7, -1).split(/\s+/))
    .filter(Boolean));

function protoAbsent(list) {
    return list.split(/\s+/).filter(Boolean).filter((t) => !protoTokens.has(t));
}

function A(id, what, proto, vue, extra) {
    return Object.assign({ id, what, proto, vue, states: [''] }, extra || {});
}

const ANCHORS = [
    A('modal-wrapper', 'modal wrapper', 'fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-sm overflow-y-auto flex items-start justify-center p-2 sm:p-4 md:p-6 transition-opacity', 'fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/70 p-2 backdrop-blur-sm sm:p-4 md:p-6'),
    A('modal-panel', 'panel', 'bg-white w-full max-w-6xl rounded-2xl shadow-2xl border border-slate-300 overflow-hidden transform my-4', 'bg-white max-w-6xl w-full rounded-2xl shadow-2xl border border-slate-300 overflow-hidden transform my-4'),
    A('header-bar', 'header bar', 'bg-slate-800 text-white px-4 py-3 flex items-center justify-between border-b border-slate-700', 'flex items-center justify-between border-b border-slate-700 bg-slate-800 px-4 py-3 text-white'),
    A('header-icon-box', 'header icon box', 'w-8 h-8 rounded-md bg-sky-600 flex items-center justify-center text-white', 'flex h-8 w-8 items-center justify-center rounded-md bg-sky-600 text-white'),
    A('header-title', 'header title h3', 'text-base font-bold text-white tracking-wide leading-tight', 'text-base font-bold leading-tight tracking-wide text-white'),
    A('header-subtitle', 'header subtitle p', 'text-xs text-slate-300', 'text-xs text-slate-300'),
    A('close-button', 'close button', 'text-slate-400 hover:text-white bg-slate-700 hover:bg-slate-600 rounded-lg w-8 h-8 flex items-center justify-center transition', 'flex h-8 w-8 items-center justify-center rounded-lg bg-slate-700 text-slate-400 transition hover:bg-slate-600 hover:text-white', { states: ['', 'hover'] }),
    A('admission-strip', 'admission strip', 'bg-[#009b9e] text-white text-[11px] font-bold grid grid-cols-2 md:grid-cols-5 text-center', 'grid grid-cols-2 bg-[#009b9e] text-center text-[11px] font-bold text-white md:grid-cols-5'),
    A('admission-cell', 'admission strip cell (aktif)', 'py-2 border-r border-[#028486] bg-[#00898c]', 'border-r border-[#028486] py-2 bg-[#00898c]'),
    A('admission-cell-plain', 'admission strip cell', 'py-2 border-r border-[#028486]', 'border-r border-[#028486] py-2'),
    A('admission-cell-filled', 'admission cell status terisi', 'text-emerald-200 font-normal', 'block text-emerald-200 font-normal'),
    A('admission-cell-empty', 'admission cell status kosong', 'text-amber-200 font-bold', 'block text-amber-200 font-bold'),
    A('admission-cell-last', 'admission strip sel terakhir', 'bg-rose-700 text-white font-black animate-pulse', 'animate-pulse bg-rose-700 py-2 font-black text-white'),
    A('admission-cell-last-span', 'admission strip sub sel terakhir', 'text-rose-100 font-semibold', 'block font-semibold text-rose-100'),
    A('patient-banner', 'inline patient banner', 'bg-slate-50 px-5 py-2.5 border-b border-slate-200 flex flex-wrap items-center justify-between text-xs text-slate-700', 'flex flex-wrap items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-2.5 text-xs text-slate-700'),
    A('patient-initials', 'patient initials', 'w-6 h-6 rounded-full bg-sky-600 text-white font-bold flex items-center justify-center text-[10px]', 'flex h-6 w-6 items-center justify-center rounded-full bg-sky-600 text-[10px] font-bold text-white'),
    A('patient-name', 'patient name', 'font-bold text-slate-900', 'font-bold text-slate-900'),
    A('patient-sep', 'patient separator', 'text-slate-400', 'text-slate-400'),
    A('patient-unit', 'patient unit chip', 'text-sky-700 bg-sky-100 px-1.5 py-0.5 rounded font-bold', 'rounded bg-sky-100 px-1.5 py-0.5 font-bold text-sky-700'),
    A('patient-dpjp', 'patient dpjp', 'text-slate-800', 'text-slate-800'),
    A('patient-payment', 'patient payment chip', 'text-emerald-700 font-bold bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200', 'rounded border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-bold text-emerald-700'),
    A('form-body', 'form body', 'p-5 sm:p-6 space-y-6 max-h-[75vh] overflow-y-auto text-xs text-slate-800', 'max-h-[75vh] space-y-6 overflow-y-auto p-5 text-xs text-slate-800 sm:p-6'),
    A('fieldset', 'each fieldset', 'border-t border-slate-200 pt-4', 'border-t border-slate-200 pt-4'),
    A('legend', 'each legend', 'text-sm font-bold text-sky-700 flex items-center gap-2 mb-3', 'mb-3 flex items-center gap-2 text-sm font-bold text-sky-700'),
    A('field-label', 'field label', 'block font-semibold mb-1 text-slate-700', 'mb-1 block font-semibold text-slate-700'),
    A('text-input', 'text/number input', 'w-full text-xs rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500', 'input', { states: ['', 'focus'], tag: 'input', attrs: { type: 'text' } }),
    A('numeric-input', 'numeric input (+font-mono)', 'w-full text-xs rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 font-mono', 'input font-mono', { states: ['', 'focus'], tag: 'input', attrs: { type: 'number' } }),
    A('kesadaran-select', 'select Tingkat Kesadaran', 'w-full text-xs rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 font-medium', 'input font-medium', { states: ['', 'focus'], tag: 'select' }),
    A('rass-label', 'RASS label', 'block font-semibold text-purple-700', 'block font-semibold text-purple-700'),
    A('rass-badge', 'RASS badge', 'text-[10px] text-purple-500 font-mono italic', 'font-mono text-[10px] italic text-purple-500'),
    A('rass-select', 'RASS select', 'w-full text-xs rounded-lg border-purple-300 shadow-sm focus:border-purple-500 focus:ring-purple-500 font-bold text-purple-800 bg-purple-50', 'w-full rounded-lg border border-purple-300 bg-purple-50 text-xs font-bold text-purple-800 shadow-sm focus:border-purple-500 focus:ring-purple-500', { states: ['', 'focus'], tag: 'select' }),
    A('rass-description', 'RASS description', 'text-[10px] text-slate-500 mt-1', 'mt-1 text-[10px] text-slate-500'),
    A('radio', 'radio', 'text-sky-600 focus:ring-sky-500 border-slate-300', 'border-slate-300 text-sky-600 focus:ring-sky-500', { states: ['', 'focus'], tag: 'input', attrs: { type: 'radio' } }),
    A('radio-label', 'radio label', 'inline-flex items-center text-xs', 'inline-flex items-center text-xs'),
    A('map-label', 'MAP label', 'font-bold text-sky-700', 'font-bold text-sky-700'),
    A('map-badge', 'MAP badge', 'text-[10px] text-slate-400 font-mono italic', 'font-mono text-[10px] italic text-slate-400'),
    A('map-computed', 'MAP (computed)', 'w-full text-xs font-mono font-bold bg-sky-50 text-sky-800 border-sky-300 rounded-lg shadow-inner', 'w-full rounded-lg border border-sky-300 bg-sky-50 font-mono text-xs font-bold text-sky-800 shadow-inner', { tag: 'input', attrs: { type: 'text' } }),
    A('o2-select', 'O2 support select', 'w-full text-xs rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500', 'input', { states: ['', 'focus'], tag: 'select' }),
    A('transfusion-select', 'Transfusi select', 'w-full text-xs rounded-lg border-slate-300 shadow-sm focus:border-sky-500', 'input', { states: ['', 'focus'], tag: 'select' }),
    A('fluid-input', 'cairan input', 'w-full text-xs rounded-lg border-slate-300 shadow-sm font-mono', 'input font-mono', { states: ['', 'focus'], tag: 'input', attrs: { type: 'number' } }),
    A('fluid-add-button', 'tombol Tambah koreksi', 'inline-flex items-center gap-1 rounded-md border border-sky-300 bg-sky-50 px-2 py-1 text-[11px] font-bold text-sky-700 hover:bg-sky-100', 'inline-flex items-center gap-1 rounded-md border border-sky-300 bg-sky-50 px-2 py-1 text-[11px] font-bold text-sky-700 transition hover:bg-sky-100', { states: ['', 'hover'], tag: 'button' }),
    A('fluid-row-label', 'label baris koreksi', 'block text-[11px] font-semibold text-slate-500 mb-1', 'mb-1 block text-[11px] font-semibold text-slate-500'),
    A('fluid-remove', 'tombol hapus baris', 'h- 9 w-9 rounded-lg border border-red-200 text-red-600 hover:bg-red-50', 'h-9 w-9 rounded-lg border border-red-200 text-red-600 transition hover:bg-red-50', { states: ['', 'hover'], tag: 'button' }),
    A('total-intake-label', 'Total Intake label', 'block font-bold text-sky-700 mb-1', 'mb-1 block font-bold text-sky-700'),
    A('total-intake', 'Total Intake (computed)', 'w-full text-xs font-mono font-black text-sky-700 bg-sky-50 border-sky-300 rounded-lg shadow-inner', 'w-full rounded-lg border border-sky-300 bg-sky-50 font-mono text-xs font-black text-sky-700 shadow-inner', { tag: 'input', attrs: { type: 'text' } }),
    A('output-subsection', 'Output Cairan subsection', 'mt-4 pt-3 border-t border-slate-100', 'mt-4 border-t border-slate-100 pt-3'),
    A('output-subsection-title', 'Output Cairan h4', 'text-xs font-bold text-sky-700 flex items-center gap-1.5 mb-2', 'mb-2 flex items-center gap-1.5 text-xs font-bold text-sky-700'),
    A('iwl-label', 'IWL label', 'font-semibold text-slate-700', 'font-semibold text-slate-700'),
    A('iwl-badge', 'IWL badge', 'text-[10px] text-slate-400 font-mono italic', 'font-mono text-[10px] italic text-slate-400'),
    A('iwl-computed', 'IWL (computed)', 'w-full text-xs font-mono font-bold bg-slate-50 text-slate-800 border-slate-300 rounded-lg shadow-inner', 'w-full rounded-lg border border-slate-300 bg-slate-50 font-mono text-xs font-bold text-slate-800 shadow-inner', { tag: 'input', attrs: { type: 'number' } }),
    A('total-output-label', 'Total Output label', 'block font-bold text-amber-700 mb-1', 'mb-1 block font-bold text-amber-700'),
    A('total-output', 'Total Output (computed)', 'w-full text-xs font-mono font-black text-amber-700 bg-amber-50 border-amber-300 rounded-lg shadow-inner', 'w-full rounded-lg border border-amber-300 bg-amber-50 font-mono text-xs font-black text-amber-700 shadow-inner', { tag: 'input', attrs: { type: 'text' } }),
    A('balance-box', 'Ringkasan Fluid Balance box', 'mt-4 pt-3 border-t border-slate-100 bg-slate-50 p-3 rounded-lg border border-slate-200', 'mt-4 border-t border-slate-100 pt-3 rounded-lg border border-slate-200 bg-slate-50 p-3'),
    A('balance-box-title', 'Ringkasan Fluid Balance h4', 'text-xs font-bold text-sky-800 flex items-center gap-1.5 mb-2', 'mb-2 flex items-center gap-1.5 text-xs font-bold text-sky-800'),
    A('balance-current-label', 'Fluid Balance (Saat Ini) label', 'block font-bold text-slate-800 mb-1', 'mb-1 block font-bold text-slate-800'),
    A('balance-current', 'Fluid Balance (Saat Ini)', 'w-full text-xs font-bold font-mono text-center bg-white border-slate-300 rounded-lg', 'w-full rounded-lg border border-slate-300 bg-white text-center font-mono text-xs font-bold', { tag: 'input', attrs: { type: 'text' } }),
    A('balance-24h-label', 'Balance/Intake/Output 24 jam label', 'block font-semibold text-slate-600 mb-1', 'mb-1 block font-semibold text-slate-600'),
    A('balance-24h', 'Balance/Intake/Output 24 jam', 'w-full text-xs font-mono bg-white border-slate-300 rounded-lg', 'w-full rounded-lg border border-slate-300 bg-white font-mono text-xs', { tag: 'input', attrs: { type: 'text' } }),
    A('balance-stay-label', 'Balance Selama Dirawat label', 'block font-semibold text-slate-700 mb-1', 'mb-1 block font-semibold text-slate-700'),
    A('balance-stay', 'Balance Selama Dirawat (computed)', 'w-48 text-xs font-bold font-mono text-emerald-700 bg-emerald-50 border-emerald-300 rounded-lg', 'w-48 rounded-lg border border-emerald-300 bg-emerald-50 font-mono text-xs font-bold text-emerald-700', { tag: 'input', attrs: { type: 'text' } }),
    A('balance-stay-unit', 'satuan mL', 'text-slate-500 font-medium', 'font-medium text-slate-500'),
    A('device-card', 'device card', 'flex items-center justify-between bg-slate-50 p-2.5 rounded-lg border border-slate-200', 'device-card', { scoped: 'device-card', tag: 'div' }),
    A('device-checkbox', 'device checkbox', 'rounded text-sky-600 focus:ring-sky-500', 'rounded text-sky-600 focus:ring-sky-500', { states: ['', 'focus'], tag: 'input', attrs: { type: 'checkbox' } }),
    A('device-date', 'device date input', 'tgl-bundle w-28 text-xs rounded border-slate-300 font-mono py-1', 'device-date', { scoped: 'device-date', tag: 'input', attrs: { type: 'date' } }),
    A('bundle-box', 'bundle box', 'bg-white p-4 rounded-lg border border-slate-200 shadow-sm space-y-3', 'bundle-box', { scoped: 'bundle-box', tag: 'div' }),
    A('bundle-box-title', 'bundle box title', 'font-extrabold text-slate-800 tracking-wider', 'font-extrabold tracking-wider text-slate-800'),
    A('bundle-box-warning', 'bundle box warning', 'text-[10px] text-rose-600 font-bold uppercase', 'text-[10px] font-bold uppercase text-rose-600'),
    A('bundle-item-text', 'bundle item text', 'text-[11px] text-slate-700 pr-2', 'pr-2 text-[11px] text-slate-700'),
    A('bundle-item-radio', 'bundle item radio', 'text-sky-600', 'text-sky-600', { tag: 'input', attrs: { type: 'radio' } }),
    A('lain-box', 'Tindakan Lain box', 'bg-slate-50 p-2.5 rounded-lg border border-slate-200 space-y-1.5', 'space-y-1.5 rounded-lg border border-slate-200 bg-slate-50 p-2.5'),
    A('lain-note', 'Tindakan Lain note input', 'w-full text-[11px] bg-white border border-slate-300 rounded px-2 py-1', 'w-full rounded border border-slate-300 bg-white px-2 py-1 text-[11px]', { tag: 'input', attrs: { type: 'text' } }),
    A('vent-label', 'Ventilasi label', 'font-semibold text-slate-700', 'font-semibold text-slate-700'),
    A('vent-badge', 'Ventilasi badge', 'text-[10px] text-slate-400', 'text-[10px] text-slate-400'),
    A('vent-textarea', 'Ventilasi textarea', 'w-full text-xs font-mono rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500', 'textarea font-mono', { states: ['', 'focus'], tag: 'textarea' }),
    A('nursing-select', 'Tindakan Keperawatan select', 'w-full text-xs rounded-lg border-slate-300 shadow-sm mb-2 focus:border-sky-500', 'input mb-2', { states: ['', 'focus'], tag: 'select' }),
    A('plan-box', 'kotak Rencana Implementasi Lanjutan', 'p-2 bg-slate-50 rounded border border-slate-200 text-[11px] text-slate-600', 'rounded border border-slate-200 bg-slate-50 p-2 text-[11px] text-slate-600'),
    A('plan-box-title', 'judul Rencana Implementasi', 'font-bold text-slate-700 mb-1', 'mb-1 font-bold text-slate-700'),
    A('ews-preview', 'EWS preview footer', 'flex flex-wrap items-center justify-between gap-3 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2', 'flex flex-wrap items-center justify-between gap-3 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2'),
    A('ews-preview-title', 'EWS preview title', 'text-xs font-bold text-sky-800', 'text-xs font-bold text-sky-800'),
    A('ews-preview-action', 'EWS preview action', 'text-[11px] text-slate-600', 'text-[11px] text-slate-600'),
    A('ews-score-badge', 'EWS score badge (belum ada vital)', 'rounded-md border px-3 py-1 text-sm font-black text-slate-500', 'font-black rounded-md border px-3 py-1 text-sm text-slate-500'),
    A('form-actions', 'baris tombol', 'flex items-center justify-center gap-4 pt-4 border-t border-slate-200 no-print', 'flex items-center justify-center gap-4 pt-4 border-t border-slate-200 px-5 py-4 no-print'),
    A('save-button', 'tombol Simpan', 'inline-flex items-center gap-2 bg-[#0284c7] hover:bg-[#0369a1] text-white font-bold px-8 py-2.5 rounded-lg text-sm shadow transition', 'inline-flex items-center gap-2 rounded-lg bg-[#0284c7] px-8 py-2.5 text-sm font-bold text-white shadow transition hover:bg-[#0369a1]', { states: ['', 'hover'], tag: 'button' }),
    A('cancel-button', 'tombol Batal', 'inline-flex items-center gap-2 bg-[#dc2626] hover:bg-[#b91c1c] text-white font-bold px-8 py-2.5 rounded-lg text-sm shadow transition', 'inline-flex items-center gap-2 rounded-lg bg-[#dc2626] px-8 py-2.5 text-sm font-bold text-white shadow transition hover:bg-[#b91c1c]', { states: ['', 'hover'], tag: 'button' }),
    A('flowsheet-header', 'flowsheet header bar', 'p-4 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3', 'card-header'),
    A('open-form-trigger', 'open-form trigger', 'inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs px-3.5 py-1.5 rounded-md font-bold shadow-sm transition', 'inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3.5 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-500', { states: ['', 'hover'], tag: 'button' }),
];

/**
 * Deteksi warna yang MATI di elemen yang sama.
 *
 * Tailwind v3 menyORIZON flat semua utility, jadi dua kelas warna untuk properti
 * yang sama pada satu elemen TIDAK bisa dua-duanya berlaku: yang tertulis lebih
 * akhir di CSS menang, apa pun urutan penulisan di markup. Contoh nyata di
 * formulir ini: `.focus:border-sky-500` (dari .input) emitted setelah
 * `.focus:border-purple-500`, jadi warna fokus RASS jadi biru langit, bukan
 * ungu - meskipun kelas ungu ada di markup.
 *
 * Fungsi ini membandingkan setiap pasangan kelas warna pada satu elemen dan
 * melaporkan yang kalah.
 */
function deadColours(classList, scoped, tag, attrs) {
    const list = classList.split(/\s+/).filter(Boolean);
    const HUES = 'white|black|transparent|current|inherit|(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|hospital|ewsCritical|ewsWarning|ewsNormal)(?:-\d{2,3})?';
    const COLOUR_CLASS = new RegExp('^(?:[a-z-]+:)*(?:bg|text|border|ring|placeholder:)-?(?:' + HUES + ')(?:/\\d+)?$');
    const utility = list.filter((c) => COLOUR_CLASS.test(c) && ruleFor(c));
    const out = [];
    for (let i = 0; i < utility.length; i++) {
        for (let k = i + 1; k < utility.length; k++) {
            const a = ruleFor(utility[i]);
            const b = ruleFor(utility[k]);
            if (!a || !b) continue;
            const av = resolve(utility[i], { tag, attrs, scoped, state: /:hover/.test(utility[i]) ? 'hover' : (/^focus:/.test(utility[i]) ? 'focus' : '') });
            const bv = resolve(utility[k], { tag, attrs, scoped, state: /:hover/.test(utility[k]) ? 'hover' : (/^focus:/.test(utility[k]) ? 'focus' : '') });
            for (const prop of ['background-color', 'color', 'border-color', '--tw-ring-color']) {
                if (av[prop] === null || bv[prop] === null || av[prop] === bv[prop]) continue;
                const winner = a.pos > b.pos ? utility[i] : utility[k];
                const loser = a.pos > b.pos ? utility[k] : utility[i];
                if (winner !== loser) {
                    out.push(loser + '[' + prop + '=' + (loser === utility[i] ? av[prop] : bv[prop]) + '] kalah dari ' + winner);
                }
            }
        }
    }
    return [...new Set(out)];
}

/**
 * Deviasi yang DISENGAJA, dibolehkan lewat --accept=<id,id>.
 *
 * `transfusion-select`, `nursing-select`, dan `fluid-input` memakai kelas
 * bersama `.input` yang memuat focus:border-sky-500 + focus:ring-sky-500,
 * sedangkan prototipe tidak mendeklarasikan fokus untuk ketiga field itu.
 * Field lain memakai .input yang memang sudah dikunci contracts
 * docs/FRONTEND_CONTRACT.md bagian 3, jadi memisahkannya hanya di tiga field
 * ini akan menjadikannya satu-satunya input formulir tanpa cincin fokus -
 * regresi aksesibilitas untuk nol keuntungan klinis. Warna saat tidak difokuskan
 * persis sama dengan prototipe.
 */
const ACCEPTED = {
    'transfusion-select': 'focus affordance dari .input, tidak terlihat saat tidak difokuskan',
    'nursing-select': 'focus affordance dari .input, tidak terlihat saat tidak difokuskan',
    'fluid-input': 'focus affordance dari .input, tidak terlihat saat tidak difokuskan',
};

const ACCEPT_FROM_ARGV = (process.argv.find((a) => a.startsWith('--accept=')) || '').slice('--accept='.length).split(',').filter(Boolean);

/* ------------------------------------------------------------------- run */

const rows = [];
let mismatches = 0;
let unvalidated = 0;

for (const anchor of ANCHORS) {
    const vueNotEmitted = classesNotEmitted(anchor.vue);
    const absent = protoAbsent(anchor.proto);
    const diffs = [];
    const notes = [];

    if (vueNotEmitted.length) { unvalidated++; notes.push('VUE_CLASS_NOT_EMITTED ' + vueNotEmitted.join(' ')); }
    if (absent.length) notes.push('PROTO_TOKEN_NOT_IN_SLICE ' + absent.join(' '));
    const dead = deadColours(anchor.vue, Boolean(anchor.scoped), anchor.tag || '', anchor.attrs || {});
    if (dead.length) notes.push('DEAD_COLOUR ' + dead.join(' | '));

    for (const st of anchor.states) {
        const p = resolve(anchor.proto, { state: st, tag: anchor.tag || '', attrs: anchor.attrs || {} });
        const v = resolve(anchor.vue + (anchor.scoped ? ' ' + anchor.scoped : ''), { state: st, tag: anchor.tag || '', attrs: anchor.attrs || {}, scoped: Boolean(anchor.scoped) });
        for (const prop of PROPS) {
            if (p[prop] === null && v[prop] === null) continue;
            if (p[prop] !== v[prop]) diffs.push((st ? st + ':' : '') + prop + '  proto=' + p[prop] + '  vue=' + v[prop]);
        }
    }

    const accepted = Boolean(ACCEPTED[anchor.id]) && (ACCEPT_FROM_ARGV.indexOf(anchor.id) >= 0 || !ACCEPT_FROM_ARGV.length);
    if (diffs.length && !accepted) mismatches++;
    rows.push(Object.assign({}, anchor, { status: diffs.length ? (accepted ? 'MISMATCH (DISETUJUI)' : 'MISMATCH') : 'MATCH', diffs, notes, accepted }));
}

const width = rows.reduce((m, r) => Math.max(m, r.id.length), 0);
process.stdout.write('CSS yang di-resolve (urutan injeksi Vite): ' + CSS_FILES.join('  ->  ') + '\n');
process.stdout.write('Rule terparse: ' + rules.length + ' | jangkar diperiksa: ' + rows.length + '\n\n');

for (const r of rows) {
    process.stdout.write((r.status === 'MATCH' ? 'MATCH    ' : (r.status === 'MISMATCH (DISETUJUI)' ? 'SETUJUI  ' : 'MISMATCH ')) + r.id.padEnd(width) + '  ' + r.what + '\n');
    if (r.accepted) process.stdout.write('              alasan: ' + ACCEPTED[r.id] + '\n');
    for (const d of r.diffs) process.stdout.write('              -> ' + d + '\n');
    for (const n of r.notes) process.stdout.write('              !! ' + n + '\n');
}

process.stdout.write('\ncheck-form-colors: ' + rows.length + ' jangkar, '
    + rows.filter((r) => r.status === 'MATCH').length + ' MATCH, '
    + mismatches + ' MISMATCH, ' + unvalidated + ' jangkar dengan kelas Vue yang tidak tervalidasi ke CSS build\n');

process.exit(mismatches > 0 || unvalidated > 0 ? 1 : 0);
