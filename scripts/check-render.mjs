#!/usr/bin/env node
/**
 * scripts/check-render.mjs
 *
 * ME-MOUNT halaman Observasi dan seluruh komponennya dengan payload SEED yang
 * nyata, lalu memastikan tidak ada yang melempar dan tidak ada `undefined` /
 * `NaN` di HTML hasil render.
 *
 * Kenapa alat ini perlu ada
 *
 * Aplikasi ini TIDAK memakai SSR. Halaman Blade hanya mengirim `data-page`
 * berisi JSON props; komponen Vue baru dieksekusi di browser. Jadi "HTTP 200
 * + kunci props benar" memberi sinyal NOL tentang apakah komponennya benar-benar
 * bisa di-mount. Dua kelas bug yang lolos dari tool lain:
 *   1. identifier yang dipakai di <script setup> tanpa import -> ReferenceError
 *      saat setup() -> white screen;
 *   2. identifier yang hanya dirujuk di template -> diam-diam merender
 *      `undefined` atau string kosong.
 *
 * Yang dilakukan alat ini
 *
 *  1. Memuat payload asli dari scripts/fixtures/render-props.json, yang dibuat
 *     `php scripts/dump-render-props.php` lewat HTTP kernel Inertia (header
 *     X-Inertia) sehingga bentuk propsnya sama persis dengan produksi.
 *  2. Membangun bundle SSR sementara dengan `vite build --ssr` (keluaran ke
 *     node_modules/.cache/check-render, dibersihkan tiap jalan). Tidak ada paket
 *     npm yang ditambahkan. Build dipakai, bukan `ssrLoadModule()`, karena
 *     loader dev-SSR Vite memaksa `vue/index.mjs` (re-export CJS) dievaluasi
 *     sebagai ESM dan gagal dengan "module is not defined".
 *  3. Me-render tiap komponen dengan renderToString dari vue/server-renderer
 *     yang sudah terpasang. `Teleport to="body"` tidak muncul di HTML utama,
 *     jadi isi `ctx.teleports.body` ikut digabungkan - tanpa itu isi formulir
 *     tidak akan pernah ikut teruji.
 *  4. Memindai HTML untuk `undefined`, `NaN`, dan `Invalid Date`, lalu mencetak
 *     hasil per file.
 *
 * Stub `@inertiajs/vue3`
 *   useForm() memanggil usePage() di dalam setup(), dan usePage() melempar
 *   error tanpa Inertia app yang aktif. Modul itu diganti stub dengan bentuk
 *   API yang sama (scripts/fixtures/inertia-stub.mjs) lewat alias Vite, jadi
 *   kode formulir yang diuji tetap kode produksi. Stub ini tidak pernah ikut
 *   ke build aplikasi.
 *
 * Exit code: 0 bila semua file bersih, 1 bila ada yang gagal, 2 bila fixture
 * tidak ada.
 *
 * @example
 *   php artisan migrate:fresh --seed --force
 *   php scripts/dump-render-props.php
 *   npm run build
 *   node scripts/check-render.mjs
 *   node scripts/check-render.mjs --page Modal
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL, fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const FIXTURE = path.join(HERE, 'fixtures', 'render-props.json');
const ENTRY = path.join(HERE, 'check-render-entry.mjs');
const STUB = path.join(HERE, 'fixtures', 'inertia-stub.mjs');
const OUT_DIR = path.join(ROOT, 'node_modules', '.cache', 'check-render');

const argv = process.argv.slice(2);

function argValue(flag) {
    const prefix = `--${flag}=`;
    const found = argv.find((value) => value.startsWith(prefix));

    return found ? found.slice(prefix.length) : null;
}

const NAME_FILTER = argValue('name');
const DUMP_DIR = argValue('dump');
const QUIET = argv.includes('--quiet');

/* --------------------------------------------------------- global minimal */

/*
 * Hanya `window`. `document` SENGAJA tidak di-stub: @vue/runtime-dom membaca
 * `typeof document` saat modul dimuat dan langsung memanggil
 * doc.createElement() untuk membuat container Teleport. Stub yang tidak
 * lengkap justru membuat pemuatan gagal, sedangkan tanpa stub apa pun
 * runtime-dom memakai jalur server-safe yang tidak menyentuh DOM. Tidak ada
 * komponen yang memakai document di luar onMounted/watch, dan keduanya tidak
 * dijalankan saat renderToString.
 */
if (typeof globalThis.window === 'undefined') {
    globalThis.window = {
        location: { hash: '', pathname: '/', search: '', origin: 'http://localhost' },
        history: { replaceState() {} },
        addEventListener() {},
        removeEventListener() {},
        print() {},
    };
}

/* ------------------------------------------------------------------ payload */

if (!fs.existsSync(FIXTURE)) {
    process.stderr.write(
        'Fixture tidak ada. Jalankan:\n'
        + '  php artisan migrate:fresh --seed --force\n'
        + '  php scripts/dump-render-props.php\n',
    );
    process.exit(2);
}

const pages = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const observasiKey = Object.keys(pages).find((key) => key.startsWith('observasi|'));

if (!observasiKey) {
    process.stderr.write('Fixture tidak punya halaman observasi.\n');
    process.exit(2);
}

const page = pages[observasiKey];
const firstVariant = Object.values(page.variants || {})[0];
const props = firstVariant ? firstVariant.props : page.props;

const flowsheet = Array.isArray(props.flowsheet) ? props.flowsheet : [];
const devices = Array.isArray(props.devices) ? props.devices : [];

/** Jawaban bundle latest, bentuk yang sama seperti Observasi.vue susun. */
function bundleAnswersFrom(compliance) {
    const answers = {};
    const groups = compliance && compliance.groups;

    if (!groups || typeof groups !== 'object') return answers;

    for (const group of Object.values(groups)) {
        const items = group && group.items;

        if (!items || typeof items !== 'object') continue;

        for (const item of Object.values(items)) {
            if (item && item.key && item.answerLabel && item.answerLabel !== '-') {
                answers[item.key] = item.answerLabel;
            }
        }
    }

    return answers;
}

const storeUrl = `/encounters/${props.encounterId}/observasi`;

const modalBase = {
    row: null,
    bundleAnswers: bundleAnswersFrom(props.bundleCompliance),
    devices,
    patient: props.banner,
    reference: props.reference,
    storeUrl,
    defaultDate: flowsheet[0] ? flowsheet[0].date : '',
    encounterId: props.encounterId,
    admissionCompletion: props.admissionCompletion || {},
};

const targets = [
    {
        name: 'Pages/Observasi.vue',
        component: 'Observasi',
        props,
    },
    {
        name: 'Observasi/ModalFormulirObservasiEWS.vue (closed)',
        component: 'ModalFormulirObservasiEWS',
        props: { ...modalBase, open: false },
    },
    {
        name: 'Observasi/ModalFormulirObservasiEWS.vue (open, new observation)',
        component: 'ModalFormulirObservasiEWS',
        props: { ...modalBase, open: true },
    },
    {
        name: 'Observasi/ModalFormulirObservasiEWS.vue (open, edit seeded row)',
        component: 'ModalFormulirObservasiEWS',
        props: {
            ...modalBase,
            open: true,
            row: flowsheet[0] || null,
            defaultDate: '',
        },
    },
    {
        name: 'Observasi/MedicationRowsEditor.vue',
        component: 'MedicationRowsEditor',
        props: {
            modelValue: [
                { name: 'KCl dalam NaCl 0,9%', dose: '25 mEq / 500 mL', category: 'Cairan & Elektrolit', volume: 500 },
                { name: 'Norepinefrin 4 mg / 50 mL', dose: '2 mcg/kg/mnt', category: 'Inotropik / Vasopressor', volume: 50 },
                { name: '', dose: '', category: '', volume: '' },
            ],
            categories: (props.reference && props.reference.medicationCategories) || {},
            disabled: false,
        },
    },
    {
        name: 'Observasi/EwsLivePreview.vue',
        component: 'EwsLivePreview',
        props: {
            state: {
                scores: { RR: 0, HR: 1, SBP: 0, SpO2: 0, Temp: 0, Kesadaran: 0 },
                total: 1,
                components: [
                    { key: 'RR', label: 'Respirasi (x/mnt)', unit: 'x/mnt', score: 0, band: '12 - 20', inGap: false, value: 16, hasValue: true },
                    { key: 'HR', label: 'Nadi (x/mnt)', unit: 'x/mnt', score: 1, band: '91 - 110', inGap: false, value: 96, hasValue: true },
                    { key: 'SBP', label: 'Tekanan Darah Sistolik (mmHg)', unit: 'mmHg', score: 0, band: '101 - 179', inGap: false, value: 128, hasValue: true },
                    { key: 'SpO2', label: 'Saturasi Oksigen (%)', unit: '%', score: 0, band: '95 - 100', inGap: false, value: 98, hasValue: true },
                    { key: 'Temp', label: 'Suhu Tubuh', unit: 'C', score: 0, band: '-', inGap: true, value: 38.05, hasValue: true },
                    { key: 'Kesadaran', label: 'Tingkat Kesadaran (AVPU)', unit: '', score: 0, band: 'DPO', inGap: false, value: null, hasValue: false },
                ],
                risk: 'low',
                riskLabel: 'Low',
                action: 'Observasi rutin tiap 8 jam',
                hasVitalData: true,
                gaps: ['Temp'],
            },
            max: 18,
        },
    },
    {
        name: 'Observasi/EwsTrendPanel.vue',
        component: 'EwsTrendPanel',
        props: {
            trend: props.ewsTrend || {},
            rows: flowsheet,
            scale: (props.reference && props.reference.ews) || {},
        },
    },
    {
        name: 'Observasi/FlowsheetObservationTable.vue',
        component: 'FlowsheetObservationTable',
        props: {
            rows: flowsheet,
            filters: props.filters || {},
            refreshing: false,
            destroyUrl: `/encounters/${props.encounterId}/observasi/delete`,
            showNewButton: true,
        },
    },
    {
        name: 'Observasi/FlowsheetDeleteConfirmModal.vue',
        component: 'FlowsheetDeleteConfirmModal',
        props: { open: true, row: flowsheet[0] || null, processing: false },
    },
    {
        name: 'Observasi/HAIsBundleCompliancePanel.vue',
        component: 'HAIsBundleCompliancePanel',
        props: { summary: props.bundleCompliance || {}, devices },
    },
    {
        name: 'Observasi/QuickWidgets.vue',
        component: 'QuickWidgets',
        props: {
            latest: (props.telemetry && props.telemetry.latest) || null,
            rows: flowsheet,
            farmasiUrl: `/encounters/${props.encounterId}/farmasi`,
            bundlesUrl: `/encounters/${props.encounterId}/bundles`,
        },
    },
    {
        name: 'Observasi/TelemetryVitalsCards.vue',
        component: 'TelemetryVitalsCards',
        props: {
            latest: (props.telemetry && props.telemetry.latest) || null,
            deltas: (props.telemetry && props.telemetry.deltas) || {},
            updatedAt: (props.telemetry && props.telemetry.lastUpdatedAt) || null,
        },
    },
].filter((target) => !NAME_FILTER || target.name.toLowerCase().includes(NAME_FILTER.toLowerCase()));

/* ------------------------------------------------------------------- build */

async function buildSsrBundle() {
    const { build } = await import('vite');
    const vue = (await import('@vitejs/plugin-vue')).default;

    await build({
        root: ROOT,
        configFile: false,
        logLevel: 'error',
        mode: 'production',
        define: { 'process.env.NODE_ENV': JSON.stringify('production') },
        resolve: { alias: { '@': path.join(ROOT, 'resources', 'js') } },
        plugins: [
            {
                name: 'inertia-stub-alias',
                enforce: 'pre',
                resolveId(source) {
                    if (source === '@inertiajs/vue3') return STUB;

                    return null;
                },
            },
            vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
        ],
        build: {
            ssr: ENTRY,
            outDir: OUT_DIR,
            emptyOutDir: true,
            minify: false,
            reportCompressedSize: false,
            rollupOptions: { output: { entryFileNames: 'entry.mjs' } },
        },
    });

    return path.join(OUT_DIR, 'entry.mjs');
}

/* ------------------------------------------------------------------ render */

const FORBIDDEN = ['undefined', 'NaN', 'Invalid Date'];

function scan(html) {
    const found = [];

    for (const token of FORBIDDEN) {
        const index = html.indexOf(token);

        if (index >= 0) {
            found.push({ token, context: html.slice(Math.max(0, index - 70), index + 70) });
        }
    }

    return found;
}

/* ------------------------------------------------------------ RASS wrapper */

/**
 * Bukti toggle RASS.
 *
 * Prototipe (phase1/observasi.html:1489-1502) menampilkan #rassWrapper hanya
 * bila #inputKesadaran bernilai 'DPO', dan evaluates ulang saat 'change' serta
 * sekali saat init. Di sini showRass adalah computed atas form.kesadaran, jadi
 * yang diuji adalah APA YANG SEBENARNYA dirender untuk tiap nilai kesadaran,
 * bukan logikanya.
 *
 * Kasus yang diuji:
 *   A. observasi baru            -> consciousnessOptions default 'DPO'  -> tampil
 *   B. menyunting baris 'Alert'  -> 'Alert'                             -> tersembunyi
 *   C. menyunting baris 'DPO'    -> 'DPO'                               -> tampil
 *   D. re-evaluasi saat runtime  -> satu app instance, state，开 toggling
 *                                   open + row, dirender ulang tiga kali
 *
 * Selain itu isi 10 <option> RASS dibandingkan DENGAN TEKS PROTOTIPE, termasuk
 * pemisah U+0020 SPACE + U+2001 EM QUAD dan ejaan ECombitive / EVery Agitated.
 */

const RASS_EXPECTED_OPTIONS = [
    ['+4', '+4 \u2001ECombitive (Agresif, melawan ventilator)'],
    ['+3', '+3 \u2001EVery Agitated (Sangat gelisah)'],
    ['+2', '+2 \u2001EAgitated (Gelisah, gerakan aktif)'],
    ['+1', '+1 \u2001ERestless (Cemas/gerakan berlebih)'],
    ['0', '0 \u2001EAlert & Calm (Tenang, sadar penuh)'],
    ['-1', '-1 \u2001EDrowsy (Mengantuk, respons >10 detik)'],
    ['-2', '-2 \u2001ELight Sedation (Sedasi Ringan)'],
    ['-3', '-3 \u2001EModerate Sedation (Sedasi Sedang)'],
    ['-4', '-4 \u2001EDeep Sedation (Sedasi Dalam, respons nyeri)'],
    ['-5', '-5 \u2001EUnarousable (Tidak dapat dibangunkan)'],
];

const CONSCIOUSNESS_EXPECTED = [
    ['Alert', 'Alert (Sadar Penuh)'],
    ['Voice', 'Respon Suara (Voice)'],
    ['Pain', 'Respon Nyeri (Pain)'],
    ['Unresponsive', 'Unresponsive'],
    ['DPO', 'DPO (Dalam Pengaruh Obat / Sedasi)'],
];

function unescapeHtml(value) {
    return String(value)
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/&amp;/g, '&');
}

/** Atribut style inline pada #rassWrapper, persis seperti yang dikirim browser. */
function rassWrapperStyle(html) {
    const at = html.indexOf('id="rassWrapper"');

    if (at < 0) return null;
    const open = html.lastIndexOf('<div', at);
    const tag = html.slice(open, html.indexOf('>', at));
    const style = tag.match(/style="([^"]*)"/);

    return style ? style[1] : '';
}

function rassWrapperHidden(html) {
    const style = rassWrapperStyle(html);

    if (style === null) return 'MISSING';
    return /display:\s*none/.test(style) ? 'hidden' : 'visible';
}

/** Pasangan value/teks dari satu <select>, urut seperti di markup. */
function selectOptions(html, id) {
    const at = html.indexOf('id="' + id + '"');

    if (at < 0) return null;
    const end = html.indexOf('</select>', at);

    if (end < 0) return null;
    const block = html.slice(at, end);
    const out = [];
    const re = /<option([^>]*)>([\s\S]*?)<\/option>/g;
    let m;
    while ((m = re.exec(block)) !== null) {
        const value = (m[1].match(/value="([^"]*)"/) || [])[1];
        out.push([value === undefined ? null : unescapeHtml(value), unescapeHtml(m[2].replace(/<!--[\s\S]*?-->/g, ''))]);
    }
    return out;
}

function snippetAround(html, needle, before, after) {
    const at = html.indexOf(needle);

    if (at < 0) return '(tidak ditemukan) ' + needle;
    return html.slice(Math.max(0, at - before), at + after).replace(/\s+/g, ' ');
}

async function renderModal(components, component, props) {
    const { createSSRApp, h } = await import('vue');
    const { renderToString } = await import('vue/server-renderer');
    const app = createSSRApp({ render: () => h(component, props) });

    app.config.warnHandler = () => {};

    const context = {};
    const html = await renderToString(app, context);
    const teleports = Object.values(context.teleports || {})
        .flatMap((chunk) => (Array.isArray(chunk) ? chunk : [chunk]))
        .join('');

    return html + teleports;
}

async function checkRass(components, modalBase, flowRow) {
    const out = [];
    let failures = 0;
    const component = components.ModalFormulirObservasiEWS;

    if (!component) {
        out.push('FAIL  ModalFormulirObservasiEWS tidak ada di bundle');

        return { failures: 1, out };
    }

    const rowFor = (kesadaran) => Object.assign({}, flowRow || {}, { date: '2026-09-07', time: '12:00', kesadaran });

    const cases = [
        { id: 'A observasi baru (default)', props: { ...modalBase, open: true, row: null }, expect: 'visible' },
        { id: "B menyunting baris 'Alert'", props: { ...modalBase, open: true, row: rowFor('Alert') }, expect: 'hidden' },
        { id: "C menyunting baris 'DPO'", props: { ...modalBase, open: true, row: rowFor('DPO') }, expect: 'visible' },
    ];

    const rendered = {};

    for (const testCase of cases) {
        const html = await renderModal(components, component, testCase.props);
        const state = rassWrapperHidden(html);
        const ok = state === testCase.expect;
        if (!ok) failures++;
        rendered[testCase.id] = { html, state };
        out.push((ok ? 'OK  ' : 'FAIL') + '  RASS ' + testCase.id + '  -> #rassWrapper ' + state + ' (harapan: ' + testCase.expect + ')');
        out.push('        ' + snippetAround(html, 'id="rassWrapper"', 30, 110));
    }

    /* --- D: re-evaluasi runtime, satu app instance, prop reaktif --- */
    {
        const { createSSRApp, h, nextTick, ref } = await import('vue');
        const { renderToString } = await import('vue/server-renderer');
        const open = ref(true);
        const row = ref(rowFor('DPO'));
        const app = createSSRApp({ render: () => h(component, Object.assign({}, modalBase, { open: open.value, row: row.value })) });

        app.config.warnHandler = () => {};

        const render = async () => {
            const context = {};
            const html = await renderToString(app, context);
            const teleports = Object.values(context.teleports || {})
                .flatMap((chunk) => (Array.isArray(chunk) ? chunk : [chunk]))
                .join('');

            return html + teleports;
        };

        const seen = [rassWrapperHidden(await render())];
        open.value = false;
        await nextTick();
        open.value = true;
        row.value = rowFor('Alert');
        await nextTick();
        seen.push(rassWrapperHidden(await render()));
        row.value = rowFor('DPO');
        await nextTick();
        seen.push(rassWrapperHidden(await render()));

        const ok = seen[0] === 'visible' && seen[1] === 'hidden' && seen[2] === 'visible';
        if (!ok) failures++;
        out.push((ok ? 'OK  ' : 'FAIL') + '  RASS D re-evaluasi runtime pada satu app instance  -> ' + seen.join(' -> '));
    }

    /* --- 10 opsi RASS, teks persis prototipe --- */
    {
        const html = rendered['A observasi baru (default)'].html;
        const options = selectOptions(html, 'inputRASS');
        const ok = options !== null
            && options.length === RASS_EXPECTED_OPTIONS.length
            && RASS_EXPECTED_OPTIONS.every((pair, index) => options[index] && options[index][0] === pair[0] && options[index][1] === pair[1]);
        if (!ok) failures++;
        out.push((ok ? 'OK  ' : 'FAIL') + '  10 opsi RASS pada #inputRASS nilai + teks persis prototipe');
        out.push('        ' + (options || []).map((o) => JSON.stringify(o)).join('\n        '));
    }

    /* --- opsi Tingkat Kesadaran: nilai harus kunci enum, bukan label --- */
    {
        const html = rendered['A observasi baru (default)'].html;
        const options = selectOptions(html, 'inputKesadaran');
        const ok = options !== null
            && options.length === CONSCIOUSNESS_EXPECTED.length
            && CONSCIOUSNESS_EXPECTED.every((pair, index) => options[index] && options[index][0] === pair[0] && options[index][1] === pair[1]);
        if (!ok) failures++;
        out.push((ok ? 'OK  ' : 'FAIL') + '  #inputKesadaran: 5 nilai enum sebagai value, 5 label sebagai teks');
        out.push('        ' + (options || []).map((o) => JSON.stringify(o)).join('\n        '));
    }

    /* --- subtree RASS benar-benar ada di dalam modal yang di-teleport --- */
    {
        const html = rendered['A observasi baru (default)'].html;
        const at = html.indexOf('data-purpose="modal-backdrop"');
        const rassAt = html.indexOf('id="rassWrapper"');
        const ok = at >= 0 && rassAt > at;
        if (!ok) failures++;
        out.push((ok ? 'OK  ' : 'FAIL') + '  #rassWrapper berada DI DALAM modal yang di-teleport (backdrop @' + at + ', rassWrapper @' + rassAt + ')');
    }

    return { failures, out };
}
/* --------------------------------- label sumbu X (TrendChart) ----------- */

/**
 * Pengukuran label sumbu X pada hasil render TrendChart.vue.
 *
 * Yang diperiksa bukan logika penipisan, melainkan HASIL yang benar-benar
 * dirender: setiap <text> sumbu X dibaca lagi dari HTML (atribut x,
 * text-anchor, font-size, font-family), lebarnya dihitung dari advance
 * font monospace yang dipakai tag itu sendiri, lalu kotak tinta tiap label
 * dibandingkan satu sama lain dan terhadap viewBox. Tidak ada konstanta
 * panjang label yang ditulis ulang di sini, jadi panjang label terbaca
 * apa adanya dari teks yang dirender (format `d/m H:i` ikut terukur).
 *
 * Kasus:
 *   A. 24 titik EWS, label dari `label` tiap titik (jalur Observasi)
 *   B. 24 titik dengan xLabels eksplisit (jalur Penunjang / Bundles)
 *   C. lebar ICU 1280
 *   D. lebar ICU 1720
 *   E. satu titik saja (kasus degenerat)
 *   F. 12 label pada chart sempit: penipisan sampai habis
 *   G. label 40 karakter pada lebar minimum, tidak ada penipisan lagi
 *      -> yang diuji adalah penurunan gradasinya (degrade)
 *   H-J. tiga panel nyata: Observasi, Bundles, Penunjang
 *
 * Untuk TrendChart langsung dipakai showGrid:false + dashedGuide:false +
 * thresholds:[] supaya satu-satunya <text> di SVG adalah label sumbu X.
 * Untuk panel nyata selector-nya lebih longgar (xAxisLabelFilter), jadi
 * kedua jalur diukur.
 */

const TREND_MIN_GAP = 8;   /* px, sama dengan X_AXIS_MIN_GAP di komponen */
const MONO_ADVANCE = 0.6;  /* advance font monospace ~ 0.6em */
const FONT_ASCENT = 0.8;
const FONT_DESCENT = 0.3;
const TREND_EPS = 0.01;

function trendPad2(value) {
    return String(value).padStart(2, '0');
}

function trendSvgs(html) {
    const found = [];
    const re = /<svg\b[\s\S]*?<\/svg>/g;
    let match;

    while ((match = re.exec(html)) !== null) found.push(match[0]);

    return found;
}

function trendViewBox(html) {
    const found = html.match(/viewBox="([-0-9.eE\s]+)"/);

    if (!found) return { x: 0, y: 0, w: 0, h: 0 };

    const parts = found[1].trim().split(/\s+/).map(Number);

    return { x: parts[0], y: parts[1], w: parts[2], h: parts[3] };
}

function trendTextNodes(html) {
    const nodes = [];
    const re = /<text\b([^>]*)>([\s\S]*?)<\/text>/g;
    let match;

    while ((match = re.exec(html)) !== null) {
        const attrs = match[1];
        const pick = (name) => {
            const found = attrs.match(new RegExp('\\s' + name + '="([^"]*)"'));

            return found ? found[1] : null;
        };

        nodes.push({
            x: Number(pick('x')),
            y: Number(pick('y')),
            anchor: pick('text-anchor'),
            fontSize: Number(pick('font-size')),
            fontFamily: pick('font-family'),
            fontWeight: pick('font-weight'),
            text: match[2].replace(/<!--[\s\S]*?-->/g, '').replace(/<[^>]*>/g, '').trim(),
        });
    }

    return nodes;
}

/** Label sumbu X pada panel nyata: font axis, tanpa font-weight, teks bukan angka. */
function xAxisLabelFilter(node) {
    if (!node.fontFamily || !/JetBrains Mono/i.test(node.fontFamily)) return false;
    if (node.fontWeight) return false;

    return !/^[-0-9.,]+$/.test(node.text);
}

function measureTrendSvg(html, select) {
    const viewBox = trendViewBox(html);
    const nodes = trendTextNodes(html);
    const ticks = nodes.filter(select);

    const labels = ticks.map((tick) => {
        const advance = tick.fontSize * MONO_ADVANCE;
        const width = tick.text.length * advance;
        const left = tick.anchor === 'start' ? tick.x : (tick.anchor === 'end' ? tick.x - width : tick.x - width / 2);

        return {
            text: tick.text,
            anchor: tick.anchor,
            fontSize: tick.fontSize,
            fontFamily: tick.fontFamily,
            x: tick.x,
            y: tick.y,
            advance,
            width,
            left,
            right: left + width,
            top: tick.y - tick.fontSize * FONT_ASCENT,
            bottom: tick.y + tick.fontSize * FONT_DESCENT,
        };
    });

    const gaps = labels.slice(1).map((label, index) => ({
        from: labels[index],
        to: label,
        gap: Number((label.left - labels[index].right).toFixed(2)),
    }));

    return {
        viewBox,
        labels,
        gaps,
        totalNodes: nodes.length,
        badFont: ticks.filter((tick) => tick.fontSize !== 9 || !/JetBrains Mono/i.test(tick.fontFamily)),
        overlap: gaps.filter((entry) => entry.gap < -TREND_EPS),
        tight: gaps.filter((entry) => entry.gap >= -TREND_EPS && entry.gap < TREND_MIN_GAP),
        outX: labels.filter((label) => label.left < viewBox.x - TREND_EPS || label.right > viewBox.x + viewBox.w + TREND_EPS),
        outY: labels.filter((label) => label.top < viewBox.y - TREND_EPS || label.bottom > viewBox.y + viewBox.h + TREND_EPS),
        minGap: gaps.length ? Math.min(...gaps.map((entry) => entry.gap)) : null,
    };
}

function reportTrend(measured, testCase, prefix) {
    const viewBox = measured.viewBox;
    const labels = measured.labels;
    const lines = [];
    const n = (value) => (Number.isFinite(value) ? Number(value).toFixed(1) : '-');
    const p = (value, size) => String(value).padStart(size);
    const fonts = [...new Set(labels.map((label) => label.fontSize + 'px ' + (label.fontFamily || '?')))].join(', ');
    let failures = 0;
    let assertions = 0;

    /** Satu asersi: dihitung baik lulus maupun gagal, supaya ringkasan jujur. */
    const check = (ok, message) => {
        assertions += 1;

        if (!ok) {
            failures += 1;
            lines.push(`        FAIL ${prefix} ${message}`);
        }
    };

    lines.push(`        ${prefix} viewBox 0 0 ${n(viewBox.w)} x ${n(viewBox.h)} | ${labels.length} dari ${measured.totalNodes} <text> adalah label sumbu X | font: ${fonts || '-'} | jarak antar label: ${measured.minGap === null ? 'n/a (satu label)' : 'min ' + n(measured.minGap) + 'px'}`);

    labels.forEach((label, index) => {
        const gap = index === 0 ? null : measured.gaps[index - 1].gap;

        lines.push(`          ${prefix} [${p(index, 2)}] x=${p(n(label.x), 7)} anchor=${p(label.anchor, 6)} w=${p(n(label.width), 5)} tinta_x [${p(n(label.left), 7)} .. ${p(n(label.right), 7)}] y=${p(n(label.y), 6)} tinta_y [${p(n(label.top), 6)} .. ${p(n(label.bottom), 6)}] ke_kiri=${p(gap === null ? '-' : n(gap), 6)} ${JSON.stringify(label.text)}`);
    });

    check(labels.length > 0, 'tidak ada label sumbu X sama sekali');
    check(measured.badFont.length === 0, `${measured.badFont.length} label tidak memakai font sumbu X (font-size 9 + JetBrains Mono)`);

    if (testCase.degrade) {
        check(labels.length === testCase.expectLabels, `penurunan gradasi: diharapkan ${testCase.expectLabels} label, dapat ${labels.length}`);
        check(labels.every((label, index) => index === 0 || label.x > labels[index - 1].x), 'posisi label tidak naik monoton');

        lines.push(`        ${prefix} note: ${measured.outX.length} label meluber keluar viewBox secara horizontal dan ${measured.outY.length} secara vertikal; pada lebar ${n(viewBox.w)} dengan label selebar ini tidak ada penipisan yang bisa membuat muat, jadi graphic engine harus menampilkan sebagai-is`);

        if (!failures) lines.push(`        ${prefix} OK   menurunkan diri ke ${labels.length} label (pertama + terakhir), posisi tetap monoton`);
    } else {
        check(measured.overlap.length === 0, `${measured.overlap.length} pasangan label bertumpuk: ${measured.overlap.slice(0, 8).map((entry) => JSON.stringify(entry.from.text) + ' -> ' + JSON.stringify(entry.to.text) + ' menimpa ' + n(Math.abs(entry.gap)) + 'px').join(' | ')}`);
        check(measured.tight.length === 0, `${measured.tight.length} pasangan label berjarak < ${TREND_MIN_GAP}px: ${measured.tight.slice(0, 8).map((entry) => JSON.stringify(entry.from.text) + ' -> ' + JSON.stringify(entry.to.text) + ' ' + n(entry.gap) + 'px').join(' | ')}`);
        check(measured.outX.length === 0, `${measured.outX.length} label melewati batas kiri/kanan viewBox: ${measured.outX.map((label) => JSON.stringify(label.text) + ' [' + n(label.left) + '..' + n(label.right) + ']').join(' | ')}`);
        check(measured.outY.length === 0, `${measured.outY.length} label melewati batas atas/bawah viewBox: ${measured.outY.map((label) => JSON.stringify(label.text) + ' y[' + n(label.top) + '..' + n(label.bottom) + '] vs 0..' + n(viewBox.h)).join(' | ')}`);

        if (!failures) lines.push(`        ${prefix} OK   tidak ada tumpang tindih, semua jarak >= ${TREND_MIN_GAP}px, seluruh kotak tinta di dalam viewBox 0 0 ${n(viewBox.w)} x ${n(viewBox.h)}`);
    }

    return { failures, assertions, lines };
}
/** Payload tren EWS 24 jam: label `d/m H:i` seperti yang dikirim server. */
function trendEwsPayload() {
    const labels = [];

    for (let i = 0; i < 24; i++) labels.push(`06/09 ${trendPad2((8 + i) % 24)}:00`);

    return {
        labels,
        points: labels.map((label, index) => ({ x: index, y: (index * 3) % 9, label, meta: 'Low' })),
    };
}

function trendDirectCases() {
    const { labels, points } = trendEwsPayload();
    const narrow = labels.slice(0, 12);
    const huge = [
        '06/09/2026 08:00 IST - observasi ke-1',
        '06/09/2026 09:00 IST - observasi ke-2',
        '06/09/2026 10:00 IST - observasi ke-3',
        '06/09/2026 11:00 IST - observasi ke-4',
        '06/09/2026 12:00 IST - observasi ke-5',
        '06/09/2026 13:00 IST - observasi ke-6',
    ];
    const base = { showGrid: false, dashedGuide: false, thresholds: [], showLegend: false, yMin: 0, yMax: 18, height: 230 };
    const ews = (list) => [{ name: 'Skor EWS', color: '#dc2626', points: list.map((label, index) => ({ x: index, y: (index * 3) % 9, label })) }];

    return [
        {
            id: 'A. 24 titik EWS, label diambil dari `label` tiap titik (jalur Observasi)',
            props: { ...base, series: [{ name: 'Skor EWS', color: '#dc2626', points }] },
        },
        {
            id: 'B. 24 titik dengan xLabels eksplisit (jalur Penunjang / Bundles)',
            props: {
                ...base,
                series: [{ name: 'Hemoglobin (g/dL)', color: '#dc2626', points: points.map((point) => ({ x: point.x, y: point.y })) }],
                xLabels: labels,
            },
        },
        {
            id: 'C. lebar ICU 1280',
            props: { ...base, width: 1280, series: [{ name: 'Skor EWS', points }], xLabels: labels },
        },
        {
            id: 'D. lebar ICU 1720',
            props: { ...base, width: 1720, series: [{ name: 'Skor EWS', points }], xLabels: labels },
        },
        {
            id: 'E. satu titik saja (kasus degenerat)',
            props: { ...base, series: [{ name: 'Skor EWS', points: [points[0]] }] },
        },
        {
            id: 'F. 12 label pada chart sempit 240: penipisan sampai habis',
            props: { ...base, width: 240, series: ews(narrow) },
        },
        {
            id: 'G. 6 label 40 karakter pada lebar minimum 200: tidak bisa ditipiskan lagi',
            props: { ...base, width: 200, series: ews(huge) },
            degrade: true,
            expectLabels: 2,
        },
    ];
}

/** Halaman fixture yang prop-nya dibutuhkan tiga panel tren. */
function trendPageProps(prefix) {
    const key = Object.keys(pages).find((name) => name.startsWith(prefix + '|'));

    if (!key) return {};

    const page = pages[key];
    const first = Object.values(page.variants || {})[0];

    return first ? first.props : page.props;
}

async function checkTrendChartLabels(components) {
    const lines = [];
    let failures = 0;
    let checks = 0;
    const trendChart = components.TrendChart;

    if (!trendChart) {
        lines.push('FAIL  TrendChart tidak ada di bundle');

        return { failures: failures + 1, checks: 1, lines };
    }

    for (const testCase of trendDirectCases()) {
        const html = await renderModal(components, trendChart, testCase.props);
        const svgs = trendSvgs(html).map((svg) => measureTrendSvg(svg, () => true));

        if (svgs.length !== 1) {
            failures += 1;
            checks += 1;
            lines.push(`FAIL  label sumbu X ${testCase.id} -- diharapkan tepat 1 <svg>, dapat ${svgs.length}`);
            continue;
        }

        const report = reportTrend(svgs[0], testCase, '');

        failures += report.failures;
        checks += report.assertions;
        lines.push(`${report.failures ? 'FAIL' : 'OK  '}  label sumbu X ${testCase.id}`);
        lines.push(...report.lines);
    }

    const observasiProps = trendPageProps('observasi');
    const bundlesProps = trendPageProps('bundles');
    const penunjangProps = trendPageProps('penunjang');

    const panelCases = [
        {
            id: 'H. Observasi/EwsTrendPanel.vue - tren skor EWS + tren hemodinamik (payload seed asli)',
            component: 'EwsTrendPanel',
            props: {
                trend: observasiProps.ewsTrend || {},
                rows: observasiProps.flowsheet || [],
                scale: (observasiProps.reference && observasiProps.reference.ews) || {},
            },
        },
        {
            id: 'I. Bundles/BundleTrendChart.vue - tiga garis 0-100% (payload seed asli)',
            component: 'BundleTrendChart',
            props: { history: (bundlesProps.history || []).slice(0, 7) },
        },
        {
            id: 'J. Penunjang - tren hasil lab (payload seed asli, dirender langsung ke TrendChart)',
            component: 'TrendChart',
            /*
             * Penunjang/TrendPanel.vue meneruskan `trend.points[].label`
             * (ClinicalFormat::chartLabel, d/m H:i) sebagai prop xLabels ke
             * TrendChart yang sama. Prop itu dirender di sini langsung supaya
             * hasil SVG yang diukur sama persis dengan yang dilihat di
             * Penunjang, tanpa bergantung pada file di direktori lain.
             */
            select: () => true,
            props: () => {
                const trend = penunjangProps.trend || {};
                const source = Array.isArray(trend.points) ? trend.points : [];
                const unit = trend.unit;

                return {
                    series: [{
                        name: trend.label || '',
                        color: '#dc2626',
                        points: source.map((point, index) => ({ x: index, y: Number(point.value), label: point.label })),
                    }],
                    xLabels: source.map((point) => point.label),
                    yMin: typeof trend.min === 'number' ? trend.min : 0,
                    yMax: typeof trend.max === 'number' ? trend.max : 100,
                    thresholds: [],
                    yUnit: unit || '',
                    showLegend: false,
                    showGrid: false,
                    area: true,
                };
            },
        },
    ];

    for (const panel of panelCases) {
        const target = components[panel.component];

        if (!target) {
            failures += 1;
            checks += 1;
            lines.push(`FAIL  label sumbu X ${panel.id} -- ${panel.component} tidak ada di bundle`);
            continue;
        }

        const panelProps = typeof panel.props === 'function' ? panel.props() : panel.props;
        const select = panel.select || xAxisLabelFilter;
        const html = await renderModal(components, target, panelProps);
        const measured = trendSvgs(html).map((svg) => measureTrendSvg(svg, select)).filter((entry) => entry.labels.length > 0);

        if (!measured.length) {
            failures += 1;
            checks += 1;
            lines.push(`FAIL  label sumbu X ${panel.id} -- tidak ada satu pun SVG dengan label sumbu X`);
            continue;
        }

        let panelFailures = 0;
        const panelLines = [];

        measured.forEach((entry, index) => {
            const prefix = `svg#${index + 1}/${measured.length}`;
            const report = reportTrend(entry, {}, prefix);

            panelFailures += report.failures;
            panelLines.push(...report.lines);
            checks += report.assertions;
        });

        failures += panelFailures;
        lines.push(`${panelFailures ? 'FAIL' : 'OK  '}  label sumbu X ${panel.id}`);
        lines.push(...panelLines);
    }

    return { failures, checks, lines };
}

async function main() {
    const bundlePath = await buildSsrBundle();

    const { components } = await import(pathToFileURL(bundlePath).href);
    const { createSSRApp, h } = await import('vue');
    const { renderToString } = await import('vue/server-renderer');

    const results = [];
    let failures = 0;

    for (const target of targets) {
        const component = components[target.component];

        try {
            if (!component) throw new Error(`komponen "${target.component}" tidak ada di bundle`);

            const app = createSSRApp({ render: () => h(component, target.props) });

            app.config.warnHandler = () => {};

            const context = {};
            const html = await renderToString(app, context);
            const teleports = Object.values(context.teleports || {})
                .flatMap((chunk) => (Array.isArray(chunk) ? chunk : [chunk]))
                .join('');
            const combined = html + teleports;
            const found = scan(combined);

            if (found.length > 0) {
                failures++;
                results.push({ ok: false, name: target.name, note: found.map((f) => `${f.token} -> ...${f.context}...`).join(' || ') });
                continue;
            }

            if (DUMP_DIR) {
                const file = path.join(DUMP_DIR, `${target.name.replace(/[^a-z0-9]+/gi, '-')}.html`);

                fs.mkdirSync(DUMP_DIR, { recursive: true });
                fs.writeFileSync(file, combined, 'utf8');
            }

            results.push({ ok: true, name: target.name, note: `${combined.length} byte HTML, 0 undefined/NaN` });
        } catch (error) {
            failures++;
            // Stack ikut dicetak: "Cannot read properties of null" tanpa lokasi
            // tidak bisa ditindaklanjuti, dan alat ini tugasnya justru
            // menangkap bug yang lolos dari build.
            const detail = error && error.stack ? String(error.stack).split('\n').slice(0, 6).join('\n        ') : String(error);

            results.push({ ok: false, name: target.name, note: detail });
        }
    }

    const rass = await checkRass(components, modalBase, flowsheet[0] || null);
    const trend = await checkTrendChartLabels(components);

    if (!QUIET) {
        for (const result of results) {
            process.stdout.write(`${result.ok ? 'OK  ' : 'FAIL'}  ${result.name}\n        ${result.note}\n`);
        }
    }

    process.stdout.write(
        `\ncheck-render: ${results.length} file diperiksa, ${results.length - failures} sukses, ${failures} gagal, `
        + `0 temuan undefined/NaN/Invalid Date pada file yang sukses`
        + ` | toggle RASS: ${7 - rass.failures}/7 asersi lulus`
        + ` | label sumbu X TrendChart: ${trend.checks - trend.failures}/${trend.checks} asersi lulus\n`,
    );

    if (!QUIET) for (const line of rass.out) process.stdout.write(`${line}\n`);

    if (!QUIET) for (const line of trend.lines) process.stdout.write(`${line}\n`);

    process.exit(failures > 0 || rass.failures > 0 || trend.failures > 0 ? 1 : 0);
}

main().catch((error) => {
    process.stderr.write(`check-render gagal: ${error && error.stack ? error.stack : error}\n`);
    process.exit(2);
});