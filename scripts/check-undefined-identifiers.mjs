#!/usr/bin/env node
/**
 * scripts/check-undefined-identifiers.mjs
 *
 * Menangkap kelas bug "identifier yang tidak terdefinisi" pada SFC Vue, yang
 * lolos dari `vite build` DAN dari smoke test berbasis HTTP.
 *
 * Dua mode kegagalan, keduanya tidak tertangkap tool yang ada sebelumnya:
 *
 *   1. Dipanggil di body <script setup> tanpa import
 *        -> `ReferenceError: x is not defined` saat setup() -> WHITE SCREEN.
 *        (contoh nyata: Pages/Penj_support%2C_page%2C_group%2C_grade%2C_avatar.png memanggil usePage() tanpa mengimpornya)
 *
 *   2. Dirujuk HANYA di dalam template
 *        -> tidak melempar apa pun, hanya diam-diam merender `undefined`
 *           atau sel kosong. Jauh lebih sulit ditarik dari kode review.
 *
 * Kenapa `vite build` tidak menangkapnya:
 *
 *   - @vitejs/plugin-vue memanggil compileScript/compileTemplate, dan keduanya
 *     hanya memeriksa SYNTAX. Identifier yang tidak diimpor tetap syntactically
 *     valid: ia berubah menjadi referensi bebas `usePage(...)` di dalam setup().
 *   - Rollup tidak mengeluh karena `usePage` dianggap ambient/global.
 *
 * Kenapa smoke test lama (114/114 PASS) tidak menangkapnya:
 *
 *   Aplikasi ini TIDAK memakai SSR. Halaman Blade hanya mengirim `data-page`
 *     berisi JSON props; komponen Vue baru dieksekusi di browser. Jadi HTTP 200
 *     plus `data-page` yang berisi kunci yang benar memberi sinyal nol tentang
 *     apakah komponennya benar-benar bisa di-mount.
 *
 * Cara kerja alat ini
 *
 * @vue/compiler-sfc sudah jadi dependency proyek, jadi alat ini tidak menambah
 * paket npm apa pun. Untuk setiap .vue di bawah resources/js:
 *
 *   1. `parse()` SFC-nya.
 *   2. `compileScript()` untuk peta `bindings` (import + deklarasi top-level
 *      <script setup> plus props).
 *   3. Parse body <script>/<script setup> dengan `babelParse` (re-export
 *      @babel/parser milik @vue/compiler-sfc) lalu kumpulkan semua nama yang
 *      dideklarasikan di file itu: import, const/let/var/function/class,
 *      parameter fungsi, binding destructuring, catch, loop, dan option API.
 *   4. `compileTemplate()` untuk template. Compiler menulis ulang setiap
 *      identifier yang tidak ada di `bindings` menjadi `_ctx.<nama>`;
 *      itulah bukti mode kegagalan ke-2 (referensi template yang hanya
 *      diam-diam merender `undefined`). Barisnya diambil dari AST template,
 *      bukan dari teks hasil generate, jadi nomornya masih menunjuk ke sumber.
 *   5. Laporkan identifier yang DIREFERENSI tapi tidak ada di langkah 3,
 *      dalam bentuk `file:line: identifier`.
 * Kumpulan "ter deklarasikan" sengaja bersifat union per-file, bukan per-scope:
 * satu nama yang dideklarasikan di fungsi mana pun di file itu dianggap sah. Ini
 * intentional -- alat ini melaporkan KESALAHAN, bukan sekadar style, dan union
 * tersebut praktis menutup semua false positive (v-for, v-slot, shadowing) tanpa
 * membuat bug yang sesungguhnya bisa lolos.
 *
 * Exit code: 0 bila bersih, 1 bila ada temuan, 2 bila SFC gagal diparse.
 *
 * @example
 *   node scripts/check-undefined-identifiers.mjs
 *   node scripts/check-undefined-identifiers.mjs --quiet
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { parse as parseSfc, compileScript, compileTemplate, babelParse } from '@vue/compiler-sfc';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const JS_ROOT = path.join(ROOT, 'resources', 'js');

const QUIET = process.argv.slice(2).includes('--quiet');

/* ------------------------------------------------------------------ globals */

/*
 * Hanya nama yang BENAR-BENAR global yang masuk daftar ini. Modul export Vue/Inertia
 * (@vue/compiler-sfc) sengaja TIDAK dimasukkan: `computed`, `ref`, `usePage` dan
 * teman-temannya wajib di-import, dan itulah persis kelas bug yang mau dikejar.
 */
const GLOBALS = new Set([
    // ECMAScript
    'globalThis', 'undefined', 'NaN', 'Infinity', 'eval', 'Function', 'Object', 'Array', 'String',
    'Number', 'Boolean', 'Symbol', 'BigInt', 'Math', 'JSON', 'Date', 'RegExp', 'Error',
    'EvalError', 'RangeError', 'ReferenceError', 'SyntaxError', 'TypeError', 'URIError',
    'AggregateError', 'Map', 'Set', 'WeakMap', 'WeakSet', 'WeakRef', 'FinalizationRegistry',
    'Promise', 'Proxy', 'Reflect', 'Intl', 'ArrayBuffer', 'SharedArrayBuffer', 'DataView',
    'Int8Array', 'Uint8Array', 'Uint8ClampedArray', 'Int16Array', 'Uint16Array',
    'Int32Array', 'Uint32Array', 'Float32Array', 'Float64Array', 'BigInt64Array',
    'BigUint64Array', 'Atomics', 'encodeURI', 'encodeURIComponent', 'decodeURI',
    'decodeURIComponent', 'escape', 'unescape', 'parseInt', 'parseFloat', 'isNaN',
    'isFinite', 'isSafeInteger', 'structuredClone', 'queueMicrotask', 'arguments',
    // timers + browser
    'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'setImmediate',
    'clearImmediate', 'requestAnimationFrame', 'cancelAnimationFrame',
    'requestIdleCallback', 'cancelIdleCallback', 'window', 'document', 'navigator',
    'location', 'history', 'localStorage', 'sessionStorage', 'screen', 'self', 'top',
    'parent', 'frames', 'alert', 'confirm', 'prompt', 'fetch', 'XMLHttpRequest',
    'WebSocket', 'EventSource', 'AbortController', 'AbortSignal', 'Blob', 'File',
    'FileList', 'FileReader', 'FormData', 'Headers', 'Request', 'Response', 'URL',
    'URLSearchParams', 'TextEncoder', 'TextDecoder', 'crypto', 'performance', 'console',
    'Image', 'ImageData', 'CustomEvent', 'Event', 'EventTarget', 'MessageChannel',
    'MessagePort', 'MutationObserver', 'ResizeObserver', 'IntersectionObserver',
    'getComputedStyle', 'matchMedia', 'Notification', 'Worker', 'SharedWorker',
    'ServiceWorker', 'BroadcastChannel', 'DOMParser', 'XMLSerializer', 'atob',
    'btoa', 'CanvasRenderingContext2D',
    // Node (skrip ini jalan di node)
    'process', 'Buffer', 'global', 'module', 'exports', 'require', '__dirname', '__filename',
    // Macro compiler Vue: tersedia di <script setup> TANPA import
    'defineProps', 'defineEmits', 'defineExpose', 'defineOptions', 'defineSlots',
    'defineModel', 'withDefaults', 'useSlots', 'useAttrs', 'useCssModule', 'useCssVars',
    'useTemplateRef', 'useId', 'useModel', 'useDefaults',
]);

/* Macro internal compiler-sfc (`__VLS_*`, `_ctx`, `_cache`, `$setup`, ...) dan
 * properti instans Vue (`$props`, `$slots`, `$attrs`, `$emit`, ...) boleh muncul sebagai
 * referensi template; keduanya tidak mungkin jadi bug import yang hilang. */
function isKnown(name) {
    if (GLOBALS.has(name)) return true;
    if (name.charCodeAt(0) === 36 /* $ */) return true; // $props, $slots, $attrs, $emit, $el, ...
    if (name.charCodeAt(0) === 95 /* _ */) return true; // __VLS_*, _ctx, _cache, _hoisted_N, _component_X
    return false;
}

/* ------------------------------------------------------------------ AST: nama */

/** Kumpulkan nama yang di-bind oleh satu pattern (destructuring, param, dll). */
function patternNames(node, out) {
    if (!node || typeof node !== 'object') return;
    switch (node.type) {
        case 'Identifier':
        case 'JSXIdentifier':
            out.add(node.name);
            return;
        case 'ObjectPattern':
            for (const prop of node.properties) {
                if (prop.type === 'RestElement') patternNames(prop.argument, out);
                else patternNames(prop.value, out);
            }
            return;
        case 'ArrayPattern':
            for (const el of node.elements) patternNames(el, out);
            return;
        case 'RestElement':
            patternNames(node.argument, out);
            return;
        case 'AssignmentPattern':
            patternNames(node.left, out);
            return;
        case 'TSParameterProperty':
            patternNames(node.parameter, out);
            return;
        default:
    }
}

/* ------------------------------------------------------------------ AST: walk */

const SKIP_KEYS = new Set(['loc', 'start', 'end', 'range', 'extra', 'leadingComments', 'trailingComments', 'innerComments', 'comments']);

function eachChild(node, fn) {
    for (const key of Object.keys(node)) {
        if (SKIP_KEYS.has(key)) continue;
        const value = node[key];
        if (Array.isArray(value)) {
            for (const child of value) if (child && typeof child === 'object') fn(child);
        } else if (value && typeof value === 'object') {
            fn(value);
        }
    }
}

/**
 * Kumpulkan SETIAP nama yang dideklarasikan di dalam program, di kedalaman
 * berapa pun (termasuk parameter fungsi dan loop variable). Union ini yang
 * membuat v-for / v-slot / shadowing tidak pernah menjadi false positive.
 */
function collectDeclared(node, out) {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
        for (const child of node) collectDeclared(child, out);
        return;
    }
    switch (node.type) {
        case 'ImportDeclaration':
            for (const spec of node.specifiers || []) out.add(spec.local.name);
            return;
        case 'VariableDeclaration':
            for (const decl of node.declarations || []) patternNames(decl.id, out);
            break;
        case 'FunctionDeclaration':
        case 'FunctionExpression':
            if (node.id) out.add(node.id.name);
            for (const p of node.params || []) patternNames(p, out);
            break;
        case 'ArrowFunctionExpression':
            for (const p of node.params || []) patternNames(p, out);
            break;
        case 'ClassDeclaration':
        case 'ClassExpression':
            if (node.id) out.add(node.id.name);
            break;
        case 'ObjectMethod':
        case 'ClassMethod':
        case 'ClassPrivateMethod':
            for (const p of node.params || []) patternNames(p, out);
            break;
        case 'CatchClause':
            patternNames(node.param, out);
            break;
        default:
    }
    eachChild(node, (child) => collectDeclared(child, out));
}

/* ------------------------------------------- AST: referensi (mode 1: script) */

/**
 * True kalau Identifier `id` adalah READ (bukan nama property, key object, label,
 * atau nama yang di-bind).
 *
 * @param {object[]} ctxOut  collecting `_ctx.x` dari compiled render fn (mode 2).
 */
function isReference(id, parent, ctxOut) {
    if (!parent) return true;
    switch (parent.type) {
        case 'MemberExpression':
        case 'OptionalMemberExpression':
            if (parent.property === id && !parent.computed) {
                const obj = parent.object;
                if (obj && obj.type === 'Identifier' && obj.name === '_ctx' && ctxOut) {
                    ctxOut.push(id);
                }
                return false;
            }
            return true;

        case 'ObjectProperty':
        case 'Property':
            if (parent.key === id && !parent.computed) return parent.shorthand === true;
            return true;

        case 'ObjectMethod':
        case 'ClassMethod':
        case 'ClassPrivateMethod':
        case 'ClassProperty':
        case 'PropertyDefinition':
            if (parent.key === id && !parent.computed) return false;
            return true;

        case 'VariableDeclarator':
            return parent.id !== id;

        case 'FunctionDeclaration':
        case 'FunctionExpression':
        case 'ArrowFunctionExpression':
            if (parent.id === id) return false;
            if (Array.isArray(parent.params) && parent.params.includes(id)) return false;
            return true;

        case 'ClassDeclaration':
        case 'ClassExpression':
            return parent.superClass === id;

        case 'CatchClause':
            return parent.param !== id;

        case 'LabeledStatement':
        case 'BreakStatement':
        case 'ContinueStatement':
            return parent.label !== id;

        case 'ImportSpecifier':
        case 'ImportDefaultSpecifier':
        case 'ImportNamespaceSpecifier':
        case 'ImportAttribute':
            return false;

        case 'ExportSpecifier':
            return parent.local === id;

        case 'MetaProperty':
        case 'Super':
            return false;

        case 'ArrayPattern':
        case 'ObjectPattern':
        case 'RestElement':
        case 'AssignmentPattern':
            return false;

        case 'TSAsExpression':
        case 'TSNonNullExpression':
        case 'TSInstantiationExpression':
            return true; // biarkan descend ke anak

        default:
            return true;
    }
}

function collectReferences(node, out, parent, ctxOut) {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
        for (const child of node) collectReferences(child, out, parent, ctxOut);
        return;
    }
    if (node.type === 'Identifier') {
        if (isReference(node, parent, ctxOut)) out.push(node);
        return;
    }
    eachChild(node, (child) => collectReferences(child, out, node, ctxOut));
}

/* --------------------------------------------- option API (untuk <script>) */

function keyName(prop) {
    if (!prop || prop.computed) return null;
    if (prop.key && prop.key.type === 'Identifier') return prop.key.name;
    if (prop.key && (prop.key.type === 'StringLiteral' || prop.key.type === 'NumericLiteral')) {
        return String(prop.key.value);
    }
    return null;
}

function addKeysOfValue(value, out) {
    if (!value) return;
    if (value.type === 'ObjectExpression') {
        for (const prop of value.properties || []) {
            const name = keyName(prop);
            if (name) out.add(name);
        }
        return;
    }
    if (value.type === 'ArrayExpression') {
        for (const el of value.elements || []) {
            if (!el) continue;
            if (el.type === 'StringLiteral') out.add(el.value);
            else if (el.type === 'Identifier') out.add(el.name);
        }
    }
}

/** Nama yang di-return `data()` / `setup()` -- semuanya jadi konteks template. */
function addReturnedKeys(value, out) {
    const fn = value && (value.type === 'ArrowFunctionExpression' || value.type === 'FunctionExpression')
        ? value
        : null;
    if (!fn || !fn.body) return;
    const visit = (node) => {
        if (!node || typeof node !== 'object') return;
        if (Array.isArray(node)) {
            for (const child of node) visit(child);
            return;
        }
        if (node.type === 'ReturnStatement' && node.argument && node.argument.type === 'ObjectExpression') {
            for (const prop of node.argument.properties || []) {
                const name = keyName(prop);
                if (name) out.add(name);
            }
        }
        eachChild(node, visit);
    };
    visit(fn.body);
}

function collectOptionApi(program, out) {
    let options = null;
    for (const stmt of program.body || []) {
        if (stmt.type !== 'ExportDefaultDeclaration') continue;
        const decl = stmt.declaration;
        if (!decl) continue;
        if (decl.type === 'ObjectExpression') options = decl;
        else if (decl.type === 'CallExpression') {
            const arg = (decl.arguments || []).find((a) => a && a.type === 'ObjectExpression');
            if (arg) options = arg;
        }
    }
    if (!options) return;
    for (const prop of options.properties || []) {
        if (prop.type !== 'ObjectProperty' && prop.type !== 'ObjectMethod') continue;
        const name = keyName(prop);
        if (!name) continue;
        if (name === 'props' || name === 'emits' || name === 'methods' || name === 'computed' ||
            name === 'components' || name === 'inject') {
            addKeysOfValue(prop.value, out);
        } else if (name === 'data' || name === 'setup') {
            addReturnedKeys(prop.value, out);
        }
    }
}

/* --------------------------------------------------- AST template (mode 2) */

// Nomor node dari @vue/compiler-core (dipakai ulang oleh compileTemplate).
const TPL_SIMPLE_EXPRESSION = 4;
const TPL_DIRECTIVE = 7;
const TPL_FOR = 11;

// `codegenNode` memuat salinan AST yang sudah di-transform; melewati kedua
// key itu mencegah report ganda dan identifier bikinan compiler.
const TPL_SKIP_KEYS = new Set(['loc', 'codegenNode', 'ssrCodegenNode', 'parent']);

function descendTpl(node, fn) {
    for (const [key, value] of Object.entries(node)) {
        if (TPL_SKIP_KEYS.has(key)) continue;
        if (Array.isArray(value)) {
            for (const child of value) if (child && typeof child === 'object') fn(child);
        } else if (value && typeof value === 'object') {
            fn(value);
        }
    }
}

/** Nama yang di-bind oleh pattern di AST template (v-for alias, v-slot param). */
function patternNamesFromTpl(node, out) {
    if (!node || typeof node !== 'object') return;
    if (typeof node.name === 'string' && node.type !== TPL_SIMPLE_EXPRESSION) {
        out.add(node.name);
        return;
    }
    if (node.type === TPL_SIMPLE_EXPRESSION) {
        if (typeof node.content === 'string' && /^[A-Za-z_$][\w$]*$/.test(node.content.trim())) {
            out.add(node.content.trim());
        }
        return;
    }
    if (Array.isArray(node.properties)) {
        for (const prop of node.properties) {
            patternNamesFromTpl(prop.type === 'RestElement' ? prop.argument : (prop.value || prop), out);
        }
        return;
    }
    if (Array.isArray(node.elements)) {
        for (const el of node.elements) patternNamesFromTpl(el, out);
        return;
    }
    if (node.left) return patternNamesFromTpl(node.left, out);
    if (node.argument) return patternNamesFromTpl(node.argument, out);
    if (typeof node.name === 'string') out.add(node.name);
}

function slotParamNames(exp, out) {
    if (!exp || typeof exp.content !== 'string') return;
    const content = exp.content.trim();
    if (!content) return;
    try {
        const ast = babelParse('(' + content + '\n)', { sourceType: 'module' });
        const expr = ast.program.body[0] && ast.program.body[0].expression;
        if (!expr) return;
        if (expr.type === 'ObjectExpression') {
            for (const prop of expr.properties || []) {
                if (prop.type === 'RestElement') patternNames(prop.argument, out);
                else patternNames(prop.value, out);
            }
            return;
        }
        if (expr.type === 'ArrayExpression') {
            for (const el of expr.elements || []) patternNames(el, out);
            return;
        }
        patternNames(expr, out);
    } catch { /* bukan pattern: abaikan */ }
}

/** PASS A -- hanya kumpulkan deklarasi yang lahir di template. */
function collectTemplateNames(node, out) {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
        for (const child of node) collectTemplateNames(child, out);
        return;
    }
    if (node.type === TPL_FOR) {
        patternNamesFromTpl(node.valueAlias, out);
        patternNamesFromTpl(node.keyAlias, out);
        patternNamesFromTpl(node.objectIndexAlias, out);
    }
    if (node.type === TPL_DIRECTIVE && node.name === 'slot' && node.exp) {
        slotParamNames(node.exp, out);
    }
    descendTpl(node, (child) => collectTemplateNames(child, out));
}

/**
 * PASS B -- laporkan referensi template yang tidak bisa dipecahkan Vue.
 *
 * `compileTemplate` menulis ulang setiap identifier yang tidak ada di `bindings`
 * menjadi `_ctx.<nama>`. Itulah bukti yang tepat untuk mode kegagalan ke-2:
 * template merujuk sesuatu yang tidak dideklarasikan, dan komponen TIDAK
 * melempar error -- ia hanya merender `undefined` atau sel kosong.
 *
 * Nama diambil dari AST template (bukan dari teks hasil generate) supaya nomor
 * barisnya masih menunjuk ke sumber aslinya. Karena filter ini memakai resolver
 * milik Vue sendiri, v-for alias, v-slot parameter, `#default="x"`, props, dan
 * import semuanya sudah dihitung compiler sebelum `_ctx.` muncul -- tidak ada
 * daftar pengecualian yang perlu dirawat manual di sini.
 */
const CTX_REF = /_ctx\.([A-Za-z_$][\w$]*)/g;

function reportTemplateRefs(node, state) {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
        for (const child of node) reportTemplateRefs(child, state);
        return;
    }
    if (node.type === TPL_SIMPLE_EXPRESSION && !node.isStatic && typeof node.content === 'string') {
        CTX_REF.lastIndex = 0;
        let match;
        while ((match = CTX_REF.exec(node.content)) !== null) {
            const name = match[1];
            if (isKnown(name) || state.declared.has(name)) continue;
            const skipped = node.content.slice(0, match.index).split('\n').length - 1;
            const line = state.tplBase + (node.loc ? node.loc.start.line : 1) + skipped;
            state.push(name, line);
        }
        return;
    }
    descendTpl(node, (child) => reportTemplateRefs(child, state));
}


/* ------------------------------------------------------------------ per-file */

function listVueFiles(dir) {
    const out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const abs = path.join(dir, entry.name);
        if (entry.isDirectory()) out.push(...listVueFiles(abs));
        else if (entry.name.endsWith('.vue')) out.push(abs);
    }
    return out.sort();
}

function sourceLine(lines, line) {
    return (lines[line - 1] || '').trim();
}

function checkFile(abs) {
    const rel = path.relative(ROOT, abs).split(path.sep).join('/');
    const lines = fs.readFileSync(abs, 'utf8').split(/\r?\n/);
    const findings = [];

    const add = (line, identifier, note) => findings.push({ rel, line, identifier, note });

    const { descriptor, errors } = parseSfc(lines.join('\n'), { filename: abs });
    if (errors && errors.length) {
        for (const err of errors) {
            add(err.loc ? err.loc.start.line : 1, '<parse error>', String(err.message || err));
        }
        return findings;
    }

    // 1. `bindings` dari compileScript: import + deklarasi top-level + props.
    let bindings = {};
    if (descriptor.script || descriptor.scriptSetup) {
        try {
            bindings = compileScript(descriptor, { id: 'sfc-check' }).bindings || {};
        } catch (err) {
            add(descriptor.scriptSetup ? descriptor.scriptSetup.loc.start.line : 1,
                '<compileScript error>', String(err.message || err));
        }
    }

    const declared = new Set(Object.keys(bindings));

    // 2. Deklarasi di body script (union seluruh scope di file ini).
    const scriptBlocks = [descriptor.script, descriptor.scriptSetup].filter(Boolean);
    for (const block of scriptBlocks) {
        let ast;
        try {
            ast = babelParse(block.content, { sourceType: 'module' });
        } catch (err) {
            add(block.loc.start.line, '<script parse error>', String(err.message || err));
            continue;
        }
        collectDeclared(ast.program, declared);
        if (block === descriptor.script && !descriptor.scriptSetup) {
            collectOptionApi(ast.program, declared);
        }
    }

    // 3. Template: compile lalu ambil AST-nya (lokasi asli, bukan hasil generate).
    let tplAst = null;
    let tplBase = 0;
    if (descriptor.template) {
        tplBase = descriptor.template.loc.start.line - 1;
        let compiled;
        try {
            compiled = compileTemplate({
                source: descriptor.template.content,
                filename: abs,
                id: 'sfc-check',
                compilerOptions: { bindingMetadata: bindings, mode: 'module' },
            });
        } catch (err) {
            add(tplBase + 1, '<template compile error>', String(err.message || err));
        }
        if (compiled) {
            for (const err of compiled.errors || []) {
                add(tplBase + (err.loc ? err.loc.start.line : 1), '<template error>',
                    String(err.message || err));
            }
            tplAst = compiled.ast || null;
            if (tplAst) collectTemplateNames(tplAst, declared);
        }
    }

    // 4. Kumpulkan seluruh referensi, baru saring dengan `declared`.
    const refs = [];

    for (const block of scriptBlocks) {
        let ast;
        try {
            ast = babelParse(block.content, { sourceType: 'module' });
        } catch {
            continue;
        }
        const base = block.loc.start.line - 1;
        const found = [];
        collectReferences(ast.program, found, null, null);
        for (const ref of found) {
            refs.push({ name: ref.name, line: base + ref.loc.start.line, origin: 'script' });
        }
    }

    if (tplAst) {
        const seen = new Set();
        const state = {
            declared,
            tplBase,
            push(name, line) {
                const key = name + ':' + line;
                if (seen.has(key)) return;
                seen.add(key);
                refs.push({ name, line, origin: 'template' });
            },
        };
        reportTemplateRefs(tplAst, state);
    }

    for (const ref of refs) {
        if (isKnown(ref.name) || declared.has(ref.name)) continue;
        add(ref.line, ref.name, ref.origin);
    }

    return findings;
}

/* ------------------------------------------------------------------- main */

function main() {
    if (!fs.existsSync(JS_ROOT)) {
        process.stderr.write('resources/js tidak ditemukan: ' + JS_ROOT + '\n');
        return 2;
    }

    const files = listVueFiles(JS_ROOT);
    const all = [];
    for (const abs of files) all.push(...checkFile(abs));

    all.sort((a, b) => (a.rel === b.rel ? a.line - b.line : a.rel < b.rel ? -1 : 1));

    if (all.length) {
        for (const f of all) {
            const src = fs.readFileSync(path.join(ROOT, f.rel.split('/').join(path.sep)), 'utf8').split(/\r?\n/);
            process.stdout.write('' + f.rel + ':' + f.line + ': ' + f.identifier + '\n');
            process.stdout.write('    ' + (sourceLine(src, f.line) || '') + '\n');
            if (f.note) process.stdout.write('      -> ' + f.note + '\n');
        }
    }

    if (!QUIET) {
        process.stdout.write(
            'check-undefined-identifiers: ' + files.length + ' file .vue dipindai, ' +
            all.length + ' temuan' + (all.length ? '' : ' (bersih)') + '\n');
    }
    return all.length ? 1 : 0;
}

process.exitCode = main();
