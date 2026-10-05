/**
 * router.js - SATU-SATUNYA tempat URL dibangun di seluruh front-end.
 *
 * Paket `ziggy/js` TIDAK terpasang, jadi tabel named route di bawah adalah
 * cermin manual dari routes/web.php. Kalau nama route di server berubah,
 * HANYA file ini yang boleh diubah.
 *
 * ---------------------------------------------------------------------------
 * NAMA ROUTE YANG DIASUMSIKAN ADA DI LARAVEL (developer route WAJIB match):
 *
 *   name              method  URI                                      param
 *   ----------------  ------  ---------------------------------------  ---------
 *   login             GET     /login                                  -
 *   login.store       POST    /login                                  -
 *   logout            POST    /logout                                 -
 *   pasien            GET     /pasien                                 -
 *   profil            GET     /encounters/{encounter}/profil          encounter
 *   cppt              GET     /encounters/{encounter}/cppt            encounter
 *   penunjang         GET     /encounters/{encounter}/penunjang       encounter
 *   farmasi           GET     /encounters/{encounter}/farmasi         encounter
 *   observasi         GET     /encounters/{encounter}/observasi       encounter
 *   bundles           GET     /encounters/{encounter}/bundles         encounter
 *
 * Bentuk yang setara di routes/web.php (nilai name WAJIB persis seperti tabel):
 *
 *   Route::get('/login',  ...)->name('login');
 *   Route::post('/login', ...)->name('login.store');
 *   Route::post('/logout', ...)->name('logout');
 *   Route::get('/pasien', ...)->name('pasien');
 *
 *   Route::get('/encounters/{encounter}/profil',    ...)->name('profil');
 *   Route::get('/encounters/{encounter}/cppt',      ...)->name('cppt');
 *   Route::get('/encounters/{encounter}/penunjang', ...)->name('penunjang');
 *   Route::get('/encounters/{encounter}/farmasi',    ...)->name('farmasi');
 *   Route::get('/encounters/{encounter}/observasi',  ...)->name('observasi');
 *   Route::get('/encounters/{encounter}/bundles',    ...)->name('bundles');
 *
 * Catatan: navigasi Inertia (router.visit / <Link href>) selalu GET, jadi kolom
 * `method` hanya dokumentasi + dipakai helper `method()`. Logout memakai
 * <form method="post"> ke url('logout').
 * -----------------------------------------------------------------------
 */

/**
 * Definisi route. Path memakai placeholder `{encounter}` ala Laravel.
 * Key WAJIB sama persis dengan nama route Laravel.
 */
export const ROUTES = {
    login: { method: 'GET', path: '/login' },
    'login.store': { method: 'POST', path: '/login' },
    logout: { method: 'POST', path: '/logout' },
    pasien: { method: 'GET', path: '/pasien' },
    profil: { method: 'GET', path: '/encounters/{encounter}/profil' },
    cppt: { method: 'GET', path: '/encounters/{encounter}/cppt' },
    penunjang: { method: 'GET', path: '/encounters/{encounter}/penunjang' },
    farmasi: { method: 'GET', path: '/encounters/{encounter}/farmasi' },
    observasi: { method: 'GET', path: '/encounters/{encounter}/observasi' },
    bundles: { method: 'GET', path: '/encounters/{encounter}/bundles' },
};

/**
 * Enam tab modul klinis. TABEL INI ADALAH SUMBER KEBENARAN untuk urutan,
 * label, ikon, dan warna tab - ClinicalLayout merendernya apa adanya.
 *
 * `tone` menentukan warna ikon (kelas teks pada phase1):
 *   profil    -> text-sky-400
 *   cppt      -> text-emerald-400
 *   penunjang -> text-purple-400
 *   farmasi   -> text-amber-400
 *   observasi -> text-yellow-300
 *   bundles   -> text-teal-400
 */
export const CLINICAL_TABS = [
    {
        key: 'profil',
        label: 'Profil & Ringkasan',
        short: 'Profil',
        icon: 'fa-id-card',
        tone: 'sky',
        iconClass: 'text-sky-400',
        route: 'profil',
    },
    {
        key: 'cppt',
        label: 'Medis & CPPT',
        short: 'CPPT',
        icon: 'fa-user-doctor',
        tone: 'emerald',
        iconClass: 'text-emerald-400',
        route: 'cppt',
    },
    {
        key: 'penunjang',
        label: 'Penunjang & AGD',
        short: 'Penunjang',
        icon: 'fa-flask-vial',
        tone: 'purple',
        iconClass: 'text-purple-400',
        route: 'penunjang',
    },
    {
        key: 'farmasi',
        label: 'Farmasi & Drip Inotropik',
        short: 'Farmasi',
        icon: 'fa-pills',
        tone: 'amber',
        iconClass: 'text-amber-400',
        route: 'farmasi',
    },
    {
        key: 'observasi',
        label: 'Observasi EWS & Hemodinamik',
        short: 'Observasi',
        icon: 'fa-chart-line',
        tone: 'yellow',
        iconClass: 'text-yellow-300',
        route: 'observasi',
    },
    {
        key: 'bundles',
        label: 'Bundle HAIs (VAP/CLABSI/CAUTI)',
        short: 'Bundle HAIs',
        icon: 'fa-shield-virus',
        tone: 'teal',
        iconClass: 'text-teal-400',
        route: 'bundles',
    },
];

/** Host aplikasi, mis. "http://127.0.0.1:8000". */
export function origin() {
    if (typeof window === 'undefined' || !window.location) return '';
    return window.location.origin || '';
}

/** Pathname + search + hash dari address bar saat ini. */
export function currentPath() {
    if (typeof window === 'undefined' || !window.location) return '/';
    return window.location.pathname || '/';
}

/** Buang query string dari pathname sekarang. */
export function currentPathname() {
    const path = currentPath();
    const cut = path.search(/[?#]/);
    return cut === -1 ? path : path.slice(0, cut);
}

/** Normalisasi trailing slash supaya /pasien/ == /pasien. */
function normalize(path) {
    if (!path) return '/';
    if (path.length > 1 && path.endsWith('/')) return path.replace(/\/+$/, '') || '/';
    return path;
}

/**
 * Susun query string dari sisa `params`.
 * Placeholder route (mis. `{encounter}`) tidak ikut jadi query.
 * Nilai null / undefined / string kosong dibuang.
 */
function buildQuery(params, consumed) {
    if (!params || typeof params !== 'object') return '';

    const search = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (consumed.has(key)) continue;
        if (value === null || value === undefined || value === '') continue;
        if (Array.isArray(value)) {
            value.forEach((entry) => {
                if (entry !== null && entry !== undefined && entry !== '') {
                    search.append(`${key}[]`, String(entry));
                }
            });
            continue;
        }
        search.append(key, String(value));
    }

    const query = search.toString();

    return query ? `?${query}` : '';
}

/** Placeholder yang muncul pada template path, mis. ["encounter"]. */
function placeholdersOf(path) {
    const found = path.match(/\{[^{}]+\}/g) || [];
    return found.map((token) => token.slice(1, -1));
}

/**
 * URL absolut untuk sebuah named route.
 *
 * @param  {string} name              Nama route, mis. 'observasi'
 * @param  {object} [params]          Param path (encounter) + query bebas
 * @param  {object} [options]
 * @param  {boolean} [options.absolute=false]  Awali dengan window.location.origin
 * @returns {string}
 */
export function url(name, params = {}, options = {}) {
    const route = ROUTES[name];

    if (!route) {
        // Jangan diam-diam menghasilkan URL yang salah: development aid.
        if (import.meta.env && import.meta.env.DEV) {
            // eslint-disable-next-line no-console
            console.warn(`[router] Unknown route name: "${name}". Add it to resources/js/router.js ROUTES.`);
        }
        return '#';
    }

    const consumed = new Set(placeholdersOf(route.path));
    let path = route.path;

    for (const key of consumed) {
        const value = params ? params[key] : undefined;
        path = path.replace(`{${key}}`, encodeURIComponent(value === null || value === undefined ? '' : String(value)));
    }

    const query = buildQuery(params, consumed);
    const absolute = options && options.absolute ? `${origin()}` : '';

    return `${absolute}${normalize(path)}${query}`;
}

/** URL relatif saja (tanpa origin) - berguna untuk <form action>. */
export function path(name, params = {}) {
    return url(name, params, { absolute: false });
}

/** Metode HTTP sebuah route ('GET' | 'POST'). */
export function method(name) {
    const route = ROUTES[name];
    return route ? route.method : 'GET';
}

/**
 * Apakah sedang berada di route ini?
 *
 * Cocokkan pathname sekarang dengan template route sebagai regex
 * (`{encounter}` -> `[^/]+`), sehingga tidak perlu tahu id encounter-nya.
 *
 * @param  {string} name
 * @returns {boolean}
 */
export function active(name) {
    const route = ROUTES[name];

    if (!route) return false;

    const pattern = normalize(route.path).replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\\\{[^{}]+\\\}/g, '[^/]+');

    return new RegExp(`^${pattern}$`).test(normalize(currentPathname()));
}

/** Tab ClinicalLayout berdasarkan kunci activeTab. */
export function tabFor(key) {
    return CLINICAL_TABS.find((tab) => tab.key === key) || null;
}

/** Nama route modul yang sedang aktif, mis. 'observasi'. */
export function activeRouteName() {
    return CLINICAL_TABS.find((tab) => active(tab.route))?.route || null;
}

/** URL tab modul untuk encounter tertentu. */
export function tabUrl(key, encounterId) {
    const tab = tabFor(key);
    return tab ? url(tab.route, { encounter: encounterId }) : '#';
}

export default {
    ROUTES,
    CLINICAL_TABS,
    url,
    path,
    method,
    active,
    origin,
    currentPath,
    currentPathname,
    tabFor,
    activeRouteName,
    tabUrl,
};