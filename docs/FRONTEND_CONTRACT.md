# FRONTEND CONTRACT — SIMRS RSP Rotinsulu / Modul Monitoring Kritis Terpadu ICU

Dokumen ini adalah **kontrak yang dikodekan verbatim** oleh empat developer
halaman (Pasien, Profil, CPPT, Penunjang, Observasi, Farmasi, Bundles).
Semua yang tertulis di sini sudah **diverifikasi berjalan** terhadap aplikasi
yang sedang di-build (Laravel 12 + Inertia 2 + Vue 3, sqlite lokal).

- Semua service ada di `app/Services/` dan **TIDAK BOLEH diubah**.
- Semua komponen ada di `resources/js/Components/` + `resources/js/Layouts/`.
- Rute klinik **belum ada**; developer rute harus menambahkannya dengan nama
  persis seperti tabel §1.2, atau mengubah `resources/js/router.js` dan
  memberi tahu wave berikutnya.
- Tidak ada npm package tambahan. Tanpa TypeScript. Tanpa library chart/icon
  (grafik = `TrendChart.vue` SVG murni, ikon = FontAwesome 6.4 via CDN).

---

## 1. ROUTING

### 1.1 Named route yang SUDAH ADA (hasil `php artisan route:list`)

| name | method | URI | middleware | controller |
|---|---|---|---|---|
| *( unnamed )* | GET | `/` | — | closure di `routes/web.php:36` -> **tidak punya halaman**: `redirect()->route('pasien')` bila login, `redirect()->route('login')` bila tamu (keduanya 302) |
| `login` | GET | `/login` | `guest` | `LoginController@create` (Blade, **bukan** SPA) |
| `login.store` | POST | `/login` | `guest`, `throttle:login` | `LoginController@store` |
| `logout` | POST | `/logout` | `auth` | `LoginController@destroy` |
| `logout.get` | GET | `/logout` | `auth` | `LoginController@destroy` (alias; dipakai untuk testing) |
| `storage.local` | PUT | `/storage/{path}` | — | framework |
| `storage.local.upload` | PUT | `/storage/{path}` | — | framework |
| *( unnamed )* | GET | `/up` | — | health check |

Detail perilaku:

- `guest` middleware = `RedirectIfAuthenticated` -> kalau sudah login,
  `GET /login` mengembalikan **302 ke `/`**.
- `throttle:login` = `Limit::perMinute(5)` dengan key
  `login|{lowercase username}|{ip}` (lihat `app/Providers/AppServiceProvider.php`).
  Percobaan ke-6 dalam satu menit -> **429** + `Retry-After`, redirect ke
  `/login` dengan flash `error` =
  `"Terlalu banyak percobaan masuk. Silakan coba lagi dalam satu menit."`
- `redirectGuestsTo(fn () => route('login'))` diset di `bootstrap/app.php`.
- Halaman 403 / 404 dirender Blade `errors/403` / `errors/404`, status ikut
  403/404. Permintaan JSON mendapat `{"message": "..."}`.

### 1.2 Named route yang WAJIB DITAMBAHKAN oleh wave integrasi

Nama **WAJIB persis** seperti tabel ini karena `resources/js/router.js`
sudah mengasumsikannya:

| name | method | URI | param | route halaman |
|---|---|---|---|---|
| `pasien` | GET | `/pasien` | — | `Pages/Pasien.vue` |
| `profil` | GET | `/encounters/{encounter}/profil` | `encounter` | `Pages/Profil.vue` |
| `cppt` | GET | `/encounters/{encounter}/cppt` | `encounter` | `Pages/Cppt.vue` |
| `penunjang` | GET | `/encounters/{encounter}/penunjang` | `encounter` | `Pages/Penunjang.vue` |
| `farmasi` | GET | `/encounters/{encounter}/farmasi` | `encounter` | `Pages/Farmasi.vue` |
| `observasi` | GET | `/encounters/{encounter}/observasi` | `encounter` | `Pages/Observasi.vue` |
| `bundles` | GET | `/encounters/{encounter}/bundles` | `encounter` | `Pages/Bundles.vue` |

Bentuk yang setara di `routes/web.php`:

```php
Route::middleware('auth')->group(function () {
    Route::get('/pasien', PasienController::class)->name('pasien');

    Route::get('/encounters/{encounter}/profil',    ProfilController::class)->name('profil');
    Route::get('/encounters/{encounter}/cppt',      CpptController::class)->name('cppt');
    Route::get('/encounters/{encounter}/penunjang', PenunjangController::class)->name('penunjang');
    Route::get('/encounters/{encounter}/farmasi',   FarmasiController::class)->name('farmasi');
    Route::get('/encounters/{encounter}/observasi', ObservasiController::class)->name('observasi');
    Route::get('/encounters/{encounter}/bundles',   BundlesController::class)->name('bundles');
});
```

> **CATATAN TENTANG `{encounter}`** — ClinicalLayout memakai
> `url(tab.route, { encounter: props.encounterId })`, dan nilainya adalah
> **string** `encounters.encounter_id` (contoh
> `enc-159853-icu-20260906`), **BUKAN** primary key numerik.
> Binding harus mengembalikan objek tersebut, mis.
> `Route::get('/encounters/{encounter}/profil', ...)->scopeBindings()`
> dengan `getRouteKeyName() === 'encounter_id'`, atau eksplisit
> `->defaults('encounter', fn ($e) => $e->encounter_id)`.
>middleware: lihat `docs/AUTHORIZATION.md` untuk alias `role:` per modul.

### 1.3 Middleware `role`

Alias `role` sudah terdaftar (`bootstrap/app.php` -> `RequireRole`).
Pakai `->middleware('role:perawat,bidan,dokter')` atau `'role:dokter'`.
Role yang tidak dikenal diabaikan; daftar kosong / tidak cocok -> `abort(403)`.
Belum login -> `AuthenticationException` -> redirect `route('login')`.
Sudah login, role salah -> `abort(403, 'Anda tidak memiliki akses ke modul ini.')`.
Matriks lengkap: `docs/AUTHORIZATION.md`.

### 1.4 `resources/js/router.js` — API publik

Tidak ada `ziggy/js`; tabel route di `router.js` adalah cermin manual
`routes/web.php`. Kalau nama route server berubah, **hanya file ini** boleh
diubah.

| export | signature | keterangan |
|---|---|---|
| `ROUTES` | `object` | `{ name: { method, path } }`; `path` memakai placeholder `{encounter}` ala Laravel |
| `CLINICAL_TABS` | `Array<ClinicalTab>` | 6 tab modul, lihat §8 |
| `origin()` | `() => string` | `window.location.origin` atau `''` di luar browser |
| `currentPath()` | `() => string` | pathname + search + hash address bar; `'/'` bila tidak ada window |
| `currentPathname()` | `() => string` | pathname tanpa query/hash |
| `url(name, params = {}, options = {})` | `(string, object, {absolute?: boolean}) => string` | isi placeholder `{...}` dari `params`, sisa key `params` jadi query string (array -> `key[]`). Nilai `null`/`undefined`/`''` dibuang. `options.absolute === true` menambah `origin()`. |
| `path(name, params = {})` | `(string, object) => string` | `url()` tanpa origin — untuk `<form action>` |
| `method(name)` | `(string) => 'GET'\|'POST'` | `'GET'` bila nama tidak dikenal |
| `active(name)` | `(string) => boolean` | cocokkan pathname sekarang dengan template route; `{encounter}` -> `[^/]+` |
| `tabFor(key)` | `(string) => ClinicalTab\|null` | cari `CLINICAL_TABS` by `key` |
| `activeRouteName()` | `() => string\|null` | route modul yang sedang aktif, mis. `'observasi'` |
| `tabUrl(key, encounterId)` | `(string, string\|number) => string` | `url(tab.route, { encounter })`; `'#'` bila key tidak dikenal |
| `default` | `object` | `{ ROUTES, CLINICAL_TABS, url, path, method, active, origin, currentPath, currentPathname, tabFor, activeRouteName, tabUrl }` |

Contoh:

```js
import { url, path, active, CLINICAL_TABS } from '@/router';
url('pasien')                                  // "/pasien"
url('observasi', { encounter: 'enc-159853-icu-20260906' })
// "/encounters/enc-159853-icu-20260906/observasi"
url('farmasi', { encounter: id, category: 'Antibiotik', q: 'meropenem' })
// "/encounters/.../farmasi?category=Antibiotik&q=meropenem"
url('bundles', { encounter: id }, { absolute: true })  // "http://host/encounters/.../bundles"
active('observasi')                            // true bila pathname sedang cocok
```

> Nama route yang tidak dikenal -> `url()` mengembalikan `'#'` dan, di mode
> dev saja, menulis warning ke console. Tidak melempar error.

---

## 2. INERTIA SHARED PROPS

Semua halaman membacanya lewat `usePage().props`.

```php
// app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return [
        ...parent::share($request),

        'auth' => [
            'user' => fn () => $this->user($request->user()),
        ],
        'appName' => config('app.name'),

        /*
         * Token CSRF untuk <form method="post"> biasa (mis. tombol
         * "Keluar" di ClinicalLayout). Halaman Inertia tidak pernah
         * merender @csrf seperti Blade, sedangkan token SESSION ikut
         * di-regenerate setiap kali session()->regenerate() dipanggil -
         * termasuk pada POST /login. Tanpa prop ini, logout dari
         * ClinicalLayout selalu berakhir 419 TokenMismatch.
         */
        'csrfToken' => fn () => $request->session()->token(),

        /*
         * Dipakai top nav. Tab modul klinik sengaja tidak diletakkan di
         * sini karena komponen layout klinik dimiliki developer lain.
         */
        'nav' => [
            'backUrl' => '/pasien',
            'backLabel' => 'Daftar Pasien',
        ],

        'flash' => [
            'success' => fn () => $request->session()->get('success'),
            'error' => fn () => $request->session()->get('error'),
        ],
    ];
}
```

Kunci yang benar-benar tiba di `props` (diverifikasi lewat `data-page`):

| key | tipe | isi |
|---|---|---|
| `errors` | `object` | dari `parent::share()` Inertia. `{}` bila tidak ada error validasi. Setelah `ValidationException`, berisi `{ field: "pesan" }` untuk field yang gagal. |
| `auth` | `object` | `{ user: User \| null }` |
| `auth.user` | `object\|null` | `{ id, name, username, specialty, role, roleLabel, initials }`. **`roleLabel`, `specialty`, `initials` boleh `null`.** `user` = `null` bila belum login. |
| `appName` | `string` | `"SIMRS RSP Rotinsulu"` (`config('app.name')`) |
| `csrfToken` | `string` | token CSRF session, **selalu 40 char**. Wajib disalin ke `<input type="hidden" name="_token">` untuk setiap `<form method="post">` biasa. |
| `nav` | `object` | `{ backUrl: '/pasien', backLabel: 'Daftar Pasien' }` |
| `flash` | `object` | `{ success: string\|null, error: string\|null }` |

Contoh `auth.user` nyata (perawat, seed lokal):

```json
{"id":1,"name":"Ns. Tri Handayani","username":"perawat","specialty":"Perawat ICU","role":"perawat","roleLabel":"Perawat","initials":"NH"}
```

> **PENTING — `initials` berbeda di dua tempat.**
> `HandleInertiaRequests::initials()` memecah `name` dengan spasi saja dan
> mengambil huruf pertama + huruf terakhir:
> `"Ns. Tri Handayani"` -> **`"NH"`** (mengambil "N" dari gelar "Ns.").
> `useFormatting.initials()` MELEWATI gelar: `"Ns. Tri Handayani"` -> **`"TH"`**.
> Untuk avatar **user** selalu pakai `props.auth.user.initials` (authoritative).
> `initials()` dari `useFormatting` hanya untuk avatar pasien yang dihitung
> di sisi client.

### 2.1 Pola POST yang benar di halaman Inertia

Form biasa (logout, konfirmasi hapus):

```vue
<script setup>
import { usePage } from '@inertiajs/vue3';
import { path } from '@/router';
const page = usePage();
</script>

<template>
  <form method="post" :action="path('logout')">
    <input type="hidden" name="_token" :value="page.props.csrfToken" />
    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
  </form>
</template>
```

Atau lebih baik untuk form besar: `useForm()` dari Inertia
(`import { useForm } from '@inertiajs/vue3'`) yang menambah header
`X-XSRF-TOKEN` sendiri. **Jangan** mengambil token dari HTML halaman login —
`Illuminate\Session\Store::regenerate()` me-rotate token setiap kali session ID
di-regenerate (termasuk setiap `POST /login` yang sukses), jadi token lama basi.

---

## 3. KOMPONEN — KONTRAK LENGKAP

Semua memakai `<script setup>`, alias import **`@` -> `resources/js`**
(ditetapkan di `vite.config.js`). Impor relatif `../Components/X.vue` juga
berfungsi.

> Props bertanda **WAJIB** tidak punya default; halaman WAJIB mengirimnya atau
> Vue memunculkan warning "Missing required prop".

### 3.1 `ClinicalLayout.vue` — `resources/js/Layouts/ClinicalLayout.vue`

| prop | type | required | default |
|---|---|---|---|
| `title` | `String` | tidak | `''` — dipasang ke `<Head :title>`; `''` -> `undefined` (judul default Inertia) |
| `subtitle` | `String` | tidak | `''` — baris versi; `''` -> `"SIMRS EMR v4.8 - Modul Monitoring Kritis Terpadu"` |
| `activeTab` | `String` | tidak | `''` — salah satu `profil｜cppt｜penunjang｜farmasi｜observasi｜bundles`; `''`/nilai asing = tidak ada tab aktif |
| `admissionCompletion` | `object` | tidak | `{ asmed: true, nursingCare: true, diagnosis: true, procedure: true }` - empat boolean dari `Encounter::getCompletionAttribute()`, dibaca strip tab admisi di dalam formulir observasi. Tidak ada di halaman selain Observasi. |
| `encounterId` | `[String, Number]` | tidak | `null` — `null`/`undefined`/`''` menyembunyikan link "Daftar Pasien" dan membuat tab modul tidak bisa diklik |
| `wide` | `Boolean` | tidak | `true` — `true` -> `max-w-[1720px]`, `false` -> `max-w-7xl` |
| `printable` | `Boolean` | tidak | `true` — `true` -> konten dibungkus `id="print-area"` (syarat `@media print`), `false` -> `id="print-area-content"` |

| slot | isi |
|---|---|
| `default` | isi halaman (kartu, tabel, grafik) |
| `status` | baris paling atas area konten — taruh `<PatientHeaderBanner>` di sini. **Harus** di sini (bukan `default`) supaya banner menempel di atas. |
| `actions` | tombol/menu di kanan top bar, **sebelum** area user |

**EMITS: tidak ada.**

Yang dirender otomatis: top bar (`data-page-region="topbar"`), tab bar
(`data-page-region="module-tabs"`, 6 `<a data-tab="{key}">`), blok flash
(`data-page-region="flash"`, auto-hilang 4 detik untuk `success`),
`<main data-page-region="content">`, footer, dan `<ToastHost />`.
Tidak perlu memasang `ToastHost` sendiri di halaman.

`ClinicalLayout` memasang `<Head :title="title">`. Halaman boleh juga memasang
`<Head title="...">`; yang dirender **terakhir** menang, dan slot halaman
dirender setelah layout, jadi **judul halaman menang**.

### 3.2 `PatientHeaderBanner.vue`

| prop | type | required | default |
|---|---|---|---|
| `patient` | `Object` | tidak | `null` — harus persis 12 kunci `getBannerPayload()` (§6.1). `null`/`{}` -> semua field tampil `-`. |
| `moduleStatus` | `String` | tidak | `''` — teks kotak status. Slot `status` selalu menang. |
| `statusLabel` | `String` | tidak | `'Status Modul'` |
| `statusTone` | `String` | tidak | `'teal'` — validator: `slate｜sky｜emerald｜teal｜amber｜orange｜red｜rose｜purple｜indigo｜yellow｜hospital` |
| `alertNote` | `String` | tidak | `'DNR: Tidak • Fall Risk: Tinggi'` — baris kecil di bawah blok alergi; `''` disembunyikan |

| slot | isi |
|---|---|
| `status` | override **seluruh** isi kotak status (menang atas `moduleStatus`) |
| `actions` | tombol di baris abu-abu kanan (mis. `PrintButton`) |
| `default` | sisipan di baris abu-abu, setelah blok diagnosa |

**EMITS: tidak ada.**

Kotak status disembunyikan bila `moduleStatus` kosong **dan** slot `status` tidak
diisi. Teks "Alergi" diberi gaya merah hanya bila isinya bukan "tidak ada".
Menempelkan atribut `data-patient="*"` pada 11 elemen (lihat §6.1) sehingga
mudah diuji dengan selector.

### 3.3 `SectionCard.vue`

| prop | type | required | default |
|---|---|---|---|
| `title` | `String` | **WAJIB** | — |
| `subtitle` | `String` | tidak | `''` |
| `icon` | `String` | tidak | `''` — lihat catatan ikon di §3.15 |
| `tone` | `String` | tidak | `'slate'` — validator 12 tone |
| `noPrint` | `Boolean` | tidak | `false` — menambah `.no-print` pada `<section>` |
| `padded` | `Boolean` | tidak | `true` — `false` = header & body tanpa padding |

Slots: `default` (body), `actions` (kanan header, selalu diberi `.no-print`),
`footer` (baris bawah, hanya dirender bila slot diisi).

**EMITS: tidak ada.** Header hanya dirender bila ada `title`/`icon`/slot
`actions`; karena `title` wajib, header selalu ada.

### 3.4 `StatCard.vue`

| prop | type | required | default |
|---|---|---|---|
| `label` | `[String, Number]` | **WAJIB** | — (diberi `uppercase` + `tracking-wider` oleh CSS) |
| `value` | `[String, Number]` | **WAJIB** | — |
| `sub` | `String` | tidak | `''` — baris kecil di bawah nilai |
| `detail` | `String` | tidak | `''` — dipakai sebagai atribut `title` tooltip pada kartu |
| `icon` | `String` | tidak | `''` — lihat §3.15 |
| `tone` | `String` | tidak | `'sky'` — validator 12 tone |
| `mono` | `Boolean` | tidak | `false` — `font-mono` pada nilai |
| `pulse` | `Boolean` | tidak | `false` — animasi denyut (`.pulse-live`) pada nilai |

Slots: `footer` (baris tambahan di dalam blok `sub`; blok itu sendiri hanya
dirender bila `sub` **atau** slot `footer` ada).

**EMITS: tidak ada.** Nilai `null` dirender sebagai string kosong oleh Vue —
jika ingin tampil `-`, kirim `"-"` dari server (semua field `*Label` sudah
begitu) atau gunakan `dash()`.

### 3.5 `EwsBadge.vue`

| prop | type | required | default |
|---|---|---|---|
| `total` | `Number` | tidak | `null` — `null`/NaN -> tampil `-` |
| `risk` | `String` | tidak | `''` — `low｜medium｜high｜emergency｜none` atau `''`/asing |
| `label` | `String` | tidak | `''` — `''` -> `EWS_RISK_LABELS[risk]`; `risk` tak dikenal -> `riskFrom(total)` |
| `size` | `String` | tidak | `'md'` — validator `sm｜md｜lg` |

Slots: `suffix` (teks kecil sesudah label, mis. `/18`).

**EMITS: tidak ada.** `risk` tak dikenal / kosong -> level diturunkan dari
`total` memakai ambang `EWS_ESCALATION` (0-2 low, 3-4 medium, 5-6 high,
>=7 emergency). Atribut `title` selalu diisi `` `EWS ${total} (${label})` ``.

### 3.6 `BarMeter.vue`

| prop | type | required | default |
|---|---|---|---|
| `label` | `String` | **WAJIB** | — |
| `value` | `Number` | tidak | `0` — nilai absolut (mis. mL) |
| `max` | `Number` | tidak | `null` — bila `percent` null, dipakai sebagai denominator |
| `percent` | `Number` | tidak | `null` — 0-100; bila null **dan** `max > 0`, dihitung `value/max*100` |
| `tone` | `String` | tidak | `'sky'` — nama tone, atau `'auto'` untuk ambang `percentTone()` |
| `format` | `Function` | tidak | `null` — `(value, percent) => string` untuk kolom kanan. Default `"1.200 mL (42%)"` |

Slots: tidak ada. **EMITS: tidak ada.**
Bar selalu punya sisa min 2 % bila `percent > 0` (agar nilai kecil terlihat);
`percent <= 0` -> lebar `0%`. Pengecualian di dalam `format()` ditangkap dan
ditampilkan sebagai `-`.

### 3.7 `ComplianceBar.vue`

| prop | type | required | default |
|---|---|---|---|
| `percent` | `Number` | tidak | `0` — 0-100; di luar 100 tetap bar penuh, teks menampilkan aslinya |
| `tone` | `String` | tidak | `'auto'` — `'auto'` pakai ambang 100/80/50; selain itu harus salah satu dari 12 tone |
| `height` | `Number` | tidak | `8` — tinggi track dalam px (min 2) |
| `showLabel` | `Boolean` | tidak | `true` |
| `labelText` | `String` | tidak | `''` — teks sebelum persen, mis. `"VAP"` |
| `track` | `Boolean` | tidak | `true` — `false` = track transparan |

Slots: tidak ada. **EMITS: tidak ada.**
`role="progressbar"` + `aria-valuenow/min/max`. Bar min 2 % bila `percent > 0`.

### 3.8 `TrendChart.vue` — SVG murni, tanpa library

| prop | type | required | default |
|---|---|---|---|
| `series` | `Array` | tidak | `() => []` — `[{ name, color?, points: [{ x, y, label?, meta? }] }]`. `x` boleh **index** `0..n-1` (paling umum) **atau** nilai nyata (epoch ms / angka) — sumbu X dihitung dari min..max semua titik. `color` default = palet internal (`sky, emerald, amber, purple, red, teal, orange, indigo`) menurut urutan seri. |
| `yMax` | `Number` | tidak | `100` — batas atas sumbu Y |
| `yMin` | `Number` | tidak | `0` |
| `thresholds` | `Array` | tidak | `() => []` — `[{ y, label?, color? }]` garis target; default color `#f87171` |
| `xLabels` | `Array` | tidak | `() => []` — bila kosong, diambil dari `label` titik seri pertama |
| `yUnit` | `String` | tidak | `''` — satuan, tampil di tooltip |
| `height` | `Number` | tidak | `220` — tinggi viewBox |
| `width` | `Number` | tidak | `760` — lebar viewBox |
| `area` | `Boolean` | tidak | `false` — isi di bawah garis tiap seri (`fill-opacity 0.14`) |
| `dashedGuide` | `Boolean` | tidak | `true` — garis target putus-putus |
| `showLegend` | `Boolean` | tidak | `true` — legenda (hanya dirender bila `series.length > 1`) |
| `showGrid` | `Boolean` | tidak | `true` — garis kisi + label sumbu Y |
| `emptyMessage` | `String` | tidak | `'Belum ada data tren.'` |

Slots: tidak ada. **EMITS: tidak ada.**
Aman untuk 0 titik dan 1 titik (satu titik ditaruh di tengah). Aman terhadap
`x`/`y` bukan angka (titik dibuang). `defineExpose({ hoverIndex, uniqueX })`.

```vue
<TrendChart
  :series="ewsTrend.points.map((p, i) => ({ x: i, y: p.total, label: p.label, meta: p.riskLabel }))"
  :y-max="ewsTrend.max" :y-min="0"
  :thresholds="[{ y: 7, label: 'Emergency', color: '#dc2626' }]"
  area
/>
```
### 3.9 `DataTableWrap.vue`

| prop | type | required | default |
|---|---|---|---|
| `sticky` | `Boolean` | tidak | `true` — header menempel (`table-sticky` + `overflow-y-auto`) |
| `maxHeight` | `[Number, String]` | tidak | `null` — `Number` = px, `String` = nilai CSS bebas (`'60vh'`, `'calc(100vh-220px)'`) |
| `tableClass` | `String` | tidak | `'table'` — kelas pada `<table>` (digabung `table-sticky` bila `sticky`) |
| `class` | `String` | tidak | `''` — kelas tambahan pada wrapper |

> Prop bernama `class` memang dideklarasikan; Vue membaca deklarasi prop lebih
> dulu daripada attribute, jadi `:class="..."` **tidak** menjadi fallthrough
> attr tetapi masuk ke prop `class`.

Slots: **hanya** `default`, isinya `<caption>?`, `<thead>?`, `<tbody>`,
`<tfoot>?`. **JANGAN** menaruh `<table>` di dalam slot (menghasilkan tabel
bersarang). **EMITS: tidak ada.**

### 3.10 `FilterBar.vue`

| prop | type | required | default |
|---|---|---|---|
| `search` | `String` | tidak | `''` — untuk `v-model:search` |
| `searchPlaceholder` | `String` | tidak | `'Cari...'` — juga dipakai sebagai `aria-label` |
| `resultCount` | `Number` | tidak | `null` — `null` -> badge jumlah tidak dirender |
| `title` | `String` | tidak | `''` |
| `icon` | `String` | tidak | `''` — lihat §3.15 |
| `showRefresh` | `Boolean` | tidak | `true` |
| `refreshLabel` | `String` | tidak | `'Refresh'` |
| `refreshing` | `Boolean` | tidak | `false` — memutar ikon refresh |
| `countLabel` | `String` | tidak | `'hasil'` |

| emit | payload | kapan |
|---|---|---|
| `update:search` | `String` | tiap ketikan di kotak pencarian |
| `refresh` | — | tombol Refresh diklik |

Slots: `default` (dropdown/filter lain, diletakkan **sebelum** kotak pencarian),
`actions` (setelah kotak pencarian), `subtitle` (di bawah `title`).

**Komponen ini tidak merender tombol Cetak** — taruh `<PrintButton />` di slot
`actions`.

### 3.11 `Modal.vue`

| prop | type | required | default |
|---|---|---|---|
| `open` | `Boolean` | tidak | `false` |
| `title` | `String` | tidak | `''` |
| `subtitle` | `String` | tidak | `''` |
| `icon` | `String` | tidak | `'fa-solid fa-file-medical'` |
| `size` | `String` | tidak | `'lg'` — validator `sm｜md｜lg｜xl` -> `max-w-md｜max-w-2xl｜max-w-4xl｜max-w-6xl` |
| `closeOnBackdrop` | `Boolean` | tidak | `true` |
| `closeOnEscape` | `Boolean` | tidak | `true` |
| `bodyClass` | `String` | tidak | `'p-5 sm:p-6 max-h-[75vh] overflow-y-auto'` |

| emit | payload | kapan |
|---|---|---|
| `close` | — | X / backdrop / Escape |

> **Komponen TIDAK menutup dirinya sendiri.** Halaman yang memutuskan:
> `:open="formOpen" @close="formOpen = false"`.

Slots: `default` (isi), `header` (ganti seluruh header; default-nya menerima
slot `title` dan `subtitle`), `title` (di dalam header default), `footer`
(sticky di bawah, hanya dirender bila slot diisi).

Teleport ke `<body>`, focus trap, Escape, kunci scroll body, pengembalian fokus
ke pemicu. `role="dialog" aria-modal="true"`. Catatan SSR: `Teleport` ke
`<body>` tidak menghasilkan markup saat SSR, jadi konten modal tidak bisa
diverifikasi lewat `renderToString`; di aplikasi ini Inertia merender di
browser, jadi tidak masalah.

### 3.12 `EmptyState.vue`

| prop | type | required | default |
|---|---|---|---|
| `icon` | `String` | tidak | `'fa-inbox'` — nama kelas FontAwesome solids |
| `title` | `String` | **WAJIB** | — |
| `message` | `String` | tidak | `''` — `''` -> paragraf tidak dirender |

Slots: `default` (isi tambahan, opsional). **EMITS: tidak ada.**

### 3.13 `LoadingOverlay.vue`

| prop | type | required | default |
|---|---|---|---|
| `show` | `Boolean` | tidak | `false` |
| `label` | `String` | tidak | `'Memproses...'` |
| `message` | `String` | tidak | `''` — baris kedua |
| `inline` | `Boolean` | tidak | `false` — `true` = `absolute inset-0` (hanya menutupi elemen terluar), `false` = `fixed inset-0` |

Slots: `default` (konten di dalam panel). **EMITS: tidak ada.**
Untuk mode `inline`, elemen terluar **harus** punya `position` non-`static`.

### 3.14 `ToastHost.vue` dan `PrintButton.vue`

`ToastHost.vue`

| prop | type | required | default |
|---|---|---|---|
| `limit` | `Number` | tidak | `TOAST_LIMIT` (= 4) |

Slots: tidak ada. **EMITS: tidak ada.**
Sudah terpasang di `ClinicalLayout` — **jangan pasang lagi**. Sumber data
`composables/useToast.js`.

`PrintButton.vue`

| prop | type | required | default |
|---|---|---|---|
| `label` | `String` | tidak | `'Cetak'` |
| `icon` | `String` | tidak | `'fa-solid fa-print'` — dipakai apa adanya (lihat §3.15) |
| `tone` | `String` | tidak | `'secondary'` — validator `primary｜secondary｜dark｜success` |
| `size` | `String` | tidak | `''` — validator `''｜'sm'` |

Slots: tidak ada. **EMITS: tidak ada.**
Memakai `window.print()`. Hanya ada artinya bila `ClinicalLayout` diberi
`printable` (default `true`) -> konten dibungkus `id="print-area"`.

### 3.15 Catatan penting soal prop `icon`

`StatCard`, `SectionCard`, dan `FilterBar` menghitung
`iconClass = raw.includes('fa-') ? raw : 'fa-solid ' + raw`.

Artinya: prefix `fa-solid` **hanya** ditambahkan bila nilai tidak mengandung
substring `fa-` sama sekali. `icon="fa-pills"` **tidak** menjadi
`fa-solid fa-pills` (tetap `fa-pills`, yang pada FontAwesome 6 sudah solid-only,
jadi tetap tampil). `icon="fa-solid fa-print"` dipakai apa adanya.
`icon="heart"` (tanpa `fa-`) menjadi `fa-solid heart` — yang **tidak valid**;
gunakan nama FA lengkap. `PrintButton` memakai `icon` apa adanya, tanpa
penambahan prefix.

---

## 4. COMPOSABLES & HELPER

### 4.1 `resources/js/composables/useFormatting.js`

Cermin JS dari `app/Services/Support/ClinicalFormat.php`.

**Aturan utama: field server yang berakhiran `*Label` sudah siap tampil
(`d/m/Y`, `H:i`, `'-'` untuk kosong) — jangan format ulang.**
Fungsi di bawah hanya untuk nilai yang DIHITUNG di sisi client.

`export const EMPTY = '-'` (identik `ClinicalFormat::EMPTY`).

| export | signature | hasil |
|---|---|---|
| `dash` | `(value) => string` | `null`/`''`/`'-'` -> `'-'`, selain itu `String(value).trim()` |
| `isBlank` | `(value) => boolean` | `true` untuk `null`/`undefined`/`''`/whitespace/`'-'` |
| `text` | `(value, fallback = EMPTY) => string` | `String(value).trim()` atau `fallback` |
| `parse` | `(value) => Date \| null` | menerima `Date`, epoch ms, ISO 8601 (dengan/d tanpa offset), `'Y-m-d'`, `'Y-m-d H:i(:s)'`, `'Y-m-dTH:i(:s)'`, `'H:i(:s)'`. Tanggal tanpa offset dibaca sebagai **lokal** (bukan UTC). Gagal -> `null`, tidak melempar. |
| `formatDate` | `(value) => string \| null` | `d/m/Y` |
| `formatTime` | `(value) => string \| null` | `H:i` |
| `formatDateTime` | `(value) => string \| null` | `d/m/Y H:i` |
| `formatDateID` | `(value) => string \| null` | `dd-MM-yyyy` (pakai `-`; beda dari `formatDate`) |
| `chartLabel` | `(value) => string` | `d/m H:i`, `'-'` bila gagal — WAJIB sama dengan server |
| `dateInputValue` | `(value) => string` | `'Y-m-d'` untuk `<input type="date">`, `''` bila gagal |
| `timeInputValue` | `(value) => string` | `'H:i'` untuk `<input type="time">`, `''` bila gagal |
| `dateTimeLabel` | `(value) => string` | `d/m/Y H:i` atau `'-'` |
| `dateLabel` | `(value) => string` | `d/m/Y` atau `'-'` |
| `timeLabel` | `(value) => string` | `H:i` atau `'-'` |
| `formatNumber` | `(value, decimals = 0) => string` | gaya Indonesia `1.234,56`; `null`/`''` -> `'-'` |
| `signed` | `(value, decimals = 0) => string` | `+180 / -120 / 0`; `null` -> `'-'` |
| `formatVolume` | `(value, unit = 'mL', decimals = 0) => string` | `1.234 mL` |
| `formatPercent` | `(value, decimals = 0) => string` | `85%` |
| `numeric` | `(value) => number \| null` | string kosong/whitespace **tidak** dianggap 0 (beda dari `Number('')`) |
| `integer` | `(value) => number \| null` | `Math.round(numeric(value))` |
| `relativeTime` | `(value, now = new Date()) => string` | `< 60 dtk` "baru saja", `< 60 mnt` "N menit lalu", `< 24 jam` "N jam lalu", `< 30 hari` "N hari lalu", lain `formatDateID`, gagal -> `'-'` |
| `initials` | `(name) => string` | maks 2 huruf, **melewati gelar** (`dr/dra/drs/ir/irs/prof/s/sa/st/se/ns/nst/bd/bdr/tn/ny/h/hj/hij/ibu/bpk`). Gagal -> `'-'` |
| `ageYears` | `(birthDate, now = new Date()) => number \| null` | |
| `ageMonths` | `(birthDate, now = new Date()) => number \| null` | |
| `ageFrom` | `(birthDate, now = new Date()) => string` | `"70 tahun"` / `"8 bulan"` / `'-'` |
| `demographicsFrom` | `(birthDate, sexLabel, now = new Date()) => string` | `"Laki-Laki · 70 tahun"` |
| `splitTokens` | `(value) => string[]` | pecah `[,;/\|]+` atau kata `"dan"`, buang token kosong/"tidak ada"/"n/a"/"tdk ada"/"unknown" |
| `hasNoAllergy` | `(value) => boolean` | `true` untuk `tidak ada｜n/a｜tdk ada｜unknown｜-` |
| `daysBetweenInclusive` | `(from, to = new Date()) => number \| null` | `12/09 - 10/09 = 3` |
| `hospitalDayLabel` | `(admittedAt, now = new Date()) => string` | `"Hari rawat ke-3"` |
| `clamp` | `(value, min, max) => number \| null` | |
| `formatting` | `object` | objek yang sama, di-bind ke nama |
| `useFormatting()` | `() => object` | mengembalikan `formatting` |
| `default` | `object` | `formatting` |

### 4.2 `resources/js/composables/useToast.js`

Store toast global (module-scoped `reactive([])`), maks 4 toast.

| export | bentuk |
|---|---|
| `TOAST_LIMIT` | `4` |
| `TOAST_DEFAULT_DURATION` | `4000` |
| `toastItems` | `reactive([])` — item: `{ id: 'toast-1', message, title, type, duration }` |
| `push(message, options?)` | tambah toast, **return `id`**. `options` boleh `number` (durasi) atau `{ duration?, type?, title? }` |
| `dismiss(id)` | buang satu toast |
| `clear()` | buang semua toast |
| `toast` | objek: `{ items, readOnlyItems, push, dismiss, clear, limit, defaultDuration, success, error, info, warning }` |
| `useToast()` | `() => toast` |
| `default` | `toast` |

```js
import { toast } from '@/composables/useToast';
toast.success('Observasi EWS tersimpan.');
toast.error('Gagal menyimpan observasi.');
toast.info('Memuat data bundle...', { duration: 1500 });
toast.warning('Hasil kultur belum keluar.', { title: 'Mikrobiologi' });
```

### 4.3 `resources/js/tone.js`

`export const TONE = { ... }` — 12 tone: `slate, sky, emerald, teal, amber,
orange, red, rose, purple, indigo, yellow, hospital`.
Tiap tone punya kunci:

| kunci | makna |
|---|---|
| `accent` | border kiri 4px kartu statistik |
| `icon` | warna ikon header kartu |
| `value` | warna angka besar |
| `fill` | warna batang solid |
| `track` | warna track batang |
| `soft` | background chip/badge |
| `softText` | teks chip/badge |
| `softBorder` | border chip/badge |
| `text` | teks aksen ringan |
| `deep` | background kotak status gelap (banner) |
| `deepBorder` | border kotak status gelap |
| `deepLabel` | warna teks label kotak status |
| `deepValue` | warna teks nilai kotak status |
| `hex` | warna SVG stroke (`TrendChart`) |

Nilai persis (hasil dump `import { TONE }`):

| tone | `accent` | `deep` | `deepBorder` | `deepLabel` | `deepValue` | `icon` | `value` | `fill` | `soft` | `softText` | `softBorder` | `text` | `hex` |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `slate` | `border-l-slate-400` | `bg-slate-800/90` | `border-slate-600` | `text-slate-400` | `text-slate-100` | `text-slate-500` | `text-slate-900` | `bg-slate-500` | `bg-slate-100` | `text-slate-700` | `border-slate-200` | `text-slate-600` | `#64748b` |
| `sky` | `border-l-sky-500` | `bg-sky-950/60` | `border-sky-500/50` | `text-sky-300` | `text-sky-100` | `text-sky-500` | `text-sky-700` | `bg-sky-500` | `bg-sky-100` | `text-sky-700` | `border-sky-200` | `text-sky-600` | `#0284c7` |
| `emerald` | `border-l-emerald-500` | `bg-emerald-950/60` | `border-emerald-500/50` | `text-emerald-300` | `text-emerald-100` | `text-emerald-500` | `text-emerald-600` | `bg-emerald-500` | `bg-emerald-100` | `text-emerald-700` | `border-emerald-200` | `text-emerald-600` | `#10b981` |
| `teal` | `border-l-teal-500` | `bg-teal-950/60` | `border-teal-500/50` | `text-teal-300` | `text-teal-100` | `text-teal-500` | `text-teal-700` | `bg-teal-500` | `bg-teal-100` | `text-teal-700` | `border-teal-200` | `text-teal-600` | `#14b8a6` |
| `amber` | `border-l-amber-500` | `bg-amber-950/50` | `border-amber-500/50` | `text-amber-300` | `text-amber-100` | `text-amber-500` | `text-amber-800` | `bg-amber-500` | `bg-amber-100` | `text-amber-800` | `border-amber-200` | `text-amber-600` | `#f59e0b` |
| `orange` | `border-l-orange-500` | `bg-orange-950/50` | `border-orange-500/50` | `text-orange-300` | `text-orange-100` | `text-orange-500` | `text-orange-600` | `bg-orange-500` | `bg-orange-100` | `text-orange-800` | `border-orange-200` | `text-orange-600` | `#f97316` |
| `red` | `border-l-red-500` | `bg-red-950/50` | `border-red-400/40` | `text-red-300` | `text-red-100` | `text-red-500` | `text-red-600` | `bg-red-500` | `bg-red-100` | `text-red-700` | `border-red-200` | `text-red-600` | `#ef4444` |
| `rose` | `border-l-rose-500` | `bg-rose-950/50` | `border-rose-500/50` | `text-rose-300` | `text-rose-100` | `text-rose-500` | `text-rose-600` | `bg-rose-500` | `bg-rose-100` | `text-rose-700` | `border-rose-200` | `text-rose-600` | `#e11d48` |
| `purple` | `border-l-purple-500` | `bg-purple-950/60` | `border-purple-500/50` | `text-purple-300` | `text-purple-100` | `text-purple-500` | `text-purple-700` | `bg-purple-500` | `bg-purple-100` | `text-purple-700` | `border-purple-200` | `text-purple-600` | `#7c3aed` |
| `indigo` | `border-l-indigo-500` | `bg-indigo-950/60` | `border-indigo-500/50` | `text-indigo-300` | `text-indigo-100` | `text-indigo-500` | `text-indigo-700` | `bg-indigo-500` | `bg-indigo-100` | `text-indigo-700` | `border-indigo-200` | `text-indigo-600` | `#6366f1` |
| `yellow` | `border-l-yellow-500` | `bg-yellow-950/50` | `border-yellow-500/50` | `text-yellow-300` | `text-yellow-100` | `text-yellow-500` | `text-yellow-600` | `bg-yellow-500` | `bg-yellow-100` | `text-yellow-800` | `border-yellow-200` | `text-yellow-600` | `#eab308` |
| `hospital` | `border-l-hospital-500` | `bg-hospital-800` | `border-hospital-500/40` | `text-hospital-100` | `text-white` | `text-hospital-500` | `text-hospital-700` | `bg-hospital-500` | `bg-hospital-100` | `text-hospital-700` | `border-hospital-100` | `text-hospital-600` | `#0284c7` |

`track` selalu `'bg-slate-100'` untuk semua tone.
Fungsi & konstanta lain di `tone.js`:

| export | nilai |
|---|---|
| `DEFAULT_TONE` | `'slate'` |
| `tone(name)` | `TONE[name]` lowercase/trim, fallback `TONE.slate`; non-string -> `TONE.slate` |
| `TONE_NAMES` | `Object.keys(TONE)` |
| `EWS_BADGE_CLASSES` | `{ emergency: ['bg-red-600','text-white','border-red-700'], high: ['bg-red-100','text-red-800','border-red-200'], medium: ['bg-amber-100','text-amber-800','border-amber-200'], low: ['bg-emerald-100','text-emerald-800','border-emerald-200'] }` |
| `EWS_BADGE_FALLBACK` | `['bg-slate-100','text-slate-700','border-slate-200']` |
| `EWS_ESCALATION` | `[{min:0,max:2,level:'low',label:'Low'}, {min:3,max:4,level:'medium',label:'Medium'}, {min:5,max:6,level:'high',label:'High'}, {min:7,max:null,level:'emergency',label:'Emergency'}]` |
| `EWS_RISK_LABELS` | `{ low:'Low', medium:'Medium', high:'High', emergency:'Emergency', none:'-' }` |
| `EWS_RISK_LONG_LABELS` | `{ low:'Low · stabil', medium:'Sedang · observasi rutin', high:'Tinggi · perlu-awasi', emergency:'Emergensi · intervensi segera', none:'-' }` |
| `EWS_MAX_TOTAL` | `18` |
| `riskFrom(total)` | `total -> 'low'｜'medium'｜'high'｜'emergency'`; bukan angka -> `'low'` |
| `ewsBadgeClasses(level)` | `{ class, bg, text, border }` |
| `riskBadgeLabel(level)` | label pendek; tak dikenal -> `'-'` |
| `riskLongLabel(level)` | label panjang; tak dikenal -> `'-'` |
| `percentTone(percent)` | `{ tone, text, chip, fill }` — `>=100` `emerald`, `>=80` `sky`, `>=50` `amber`, selain itu `red`. `chip` = `'bg-emerald-100 text-emerald-700 border border-emerald-200'` (dst), `text` = `'text-emerald-600'`, `fill` = `'bg-emerald-500'` |
| `BUNDLE_TONE` | `{ vap:{key:'vap',short:'VAP',accent:'border-sky-500',chip:'bg-sky-100 text-sky-700',border:'border-sky-200',text:'text-sky-700',line:'#0284c7'}, clabsi:{key:'clabsi',short:'CLABSI',accent:'border-purple-500',chip:'bg-purple-100 text-purple-700',border:'border-purple-200',text:'text-purple-700',line:'#7c3aed'}, cauti:{key:'cauti',short:'CAUTI',accent:'border-amber-500',chip:'bg-amber-100 text-amber-700',border:'border-amber-200',text:'text-amber-700',line:'#f59e0b'} }` |
| `bundleTone(groupId)` | `BUNDLE_TONE[groupId]`, fallback `BUNDLE_TONE.vap` |
| `MEDICATION_TONE` | kunci = **label** kategori: `'Inotropik / Vasopressor'` (purple), `'Sedasi & Analgesia'` (indigo), `'Antibiotik'` (emerald), `'Cairan & Elektrolit'` (sky), `'Obat Systemic'` (amber), `Lainnya` (slate); tiap nilai `{ tone, chip, fill }` |
| `MEDICATION_CATEGORY_ORDER` | `['Inotropik / Vasopressor','Sedasi & Analgesia','Antibiotik','Cairan & Elektrolit','Obat Systemic','Lainnya']` |
| `medicationTone(category)` | `MEDICATION_TONE[category]`, fallback `MEDICATION_TONE.Lainnya` |
| `bundleAnswerChip(answer)` | `'Ya'` -> `{ text:'Ya', icon:'fa-solid fa-check', class:'text-emerald-600' }`; `'Tidak'` -> `{ text:'Tidak', icon:'fa-solid fa-xmark', class:'text-red-600' }`; selain itu -> `{ text:'-', icon:'fa-solid fa-minus', class:'text-slate-400' }` |
| `EMPTY_DASH` | `'-'` |
| `default` | objek berisi semua di atas |

> **Skala EWS adalah BRITISH EWS 4 TINGKAT, bukan MEWS 3-tingkat.**
> Jangan "memperbaiki"-nya ke MEWS. `0-2 low, 3-4 medium, 5-6 high, >=7
> emergency`, maksimum 18. Ada **celah band** pada `Temp` (35.0-35.1,
> 36.0-36.1, 38.0-38.1, 41.0-41.1) yang sengaja menghasilkan skor 0 — ini
> perilaku yang benar, bukan bug.

### 4.4 `resources/js/Components/uiKitClasses.js`

Sentinel konten Tailwind. **Tidak di-import ke mana pun** (tidak masuk bundel
runtime, hanya dibaca scanner Tailwind). **JANGAN HAPUS.**

| export | nilai |
|---|---|
| `UI_KIT_CLASSES` | `'card card-header card-title card-body card-footer panel panel-title btn btn-primary btn-secondary btn-ghost btn-danger btn-success btn-sm btn-icon input select textarea label field field-hint input-error badge table thead th td table-compact row-hover table-sticky tabs tab-link tab-link-active stat-value mono divider link chip scroll-x empty-block anim-fade anim-pop anim-slide anim-grow-x anim-spin print-border pulse-live no-print'` |
| `UI_KIT_TONES` | `'slate sky emerald teal amber orange red rose purple indigo yellow hospital'` |
| `UI_KIT_EWS_COLORS` | `'text-ewsCritical bg-ewsCritical border-ewsCritical text-ewsWarning bg-ewsWarning border-ewsWarning text-ewsNormal bg-ewsNormal border-ewsNormal'` |

Kalau menambah kelas baru ke `@layer components` di `resources/css/app.css`,
tambahkan namanya juga ke `UI_KIT_CLASSES`, atau kelas itu tidak akan ikut
build (Tailwind tree-shake `@layer components`).

---

## 5. CSS — `@layer components` (verbatim dari `resources/css/app.css`)

Kelas di luar blok ini (`.pulse-live`, `@keyframes livePulse`, `@keyframes
ew*`, blok `@media print`) juga ada di file yang sama, dan ikut ditulis ulang
di bawah.

```css
@layer components {
  /* ---------- Card (header + body + footer) ---------- */
  .card {
    @apply overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm;
  }
  .card-header {
    @apply flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3;
  }
  .card-title {
    @apply flex items-center gap-2 text-sm font-bold text-slate-900;
  }
  .card-body {
    @apply p-4;
  }
  .card-footer {
    @apply flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-white px-4 py-2.5 text-xs text-slate-500;
  }

  /* ---------- Panel (tanpa chrome, isi saja) ---------- */
  .panel {
    @apply rounded-xl border border-slate-200 bg-white p-4 shadow-sm;
  }
  .panel-title {
    @apply text-sm font-bold text-slate-900;
  }

  /* ---------- Button ---------- */
  .btn {
    @apply inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md border border-transparent px-3 py-2 text-xs font-semibold shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50;
  }
  .btn-primary {
    @apply bg-sky-600 text-white hover:bg-sky-700 focus:ring-sky-500;
  }
  .btn-secondary {
    @apply border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus:ring-slate-400;
  }
  .btn-ghost {
    @apply bg-transparent text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:ring-slate-300;
  }
  .btn-danger {
    @apply bg-red-600 text-white hover:bg-red-700 focus:ring-red-500;
  }
  .btn-success {
    @apply bg-emerald-600 text-white hover:bg-emerald-700 focus:ring-emerald-500;
  }
  .btn-sm {
    @apply px-2.5 py-1.5 text-[11px];
  }
  .btn-icon {
    @apply h-8 w-8 rounded-lg p-0;
  }

  /* ---------- Form ---------- */
  .input {
    @apply block w-full rounded-lg border border-slate-300 bg-white text-xs text-slate-800 shadow-sm placeholder:text-slate-400 focus:border-sky-500 focus:ring-sky-500;
  }
  .select {
    @apply block w-full rounded-lg border border-slate-300 bg-white pr-9 text-xs font-medium text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500;
  }
  .textarea {
    @apply block w-full rounded-lg border border-slate-300 bg-white text-xs leading-relaxed text-slate-800 shadow-sm placeholder:text-slate-400 focus:border-sky-500 focus:ring-sky-500;
  }
  .label {
    @apply mb-1 block text-xs font-semibold text-slate-700;
  }
  .field {
    @apply w-full;
  }
  .field-hint {
    @apply mt-1 text-[11px] text-slate-500;
  }
  .input-error {
    @apply border-red-400 text-red-700 placeholder:text-red-300 focus:border-red-500 focus:ring-red-500;
  }

  /* ---------- Table ---------- */
  .table {
    @apply w-full border-collapse text-left text-xs text-slate-700;
  }
  .table thead {
    @apply border-b border-slate-200 bg-slate-100 text-[11px] font-semibold uppercase tracking-wider text-slate-600;
  }
  .table tbody {
    @apply divide-y divide-slate-200;
  }
  .table tfoot {
    @apply border-t border-slate-200 bg-slate-50;
  }
  .thead {
    @apply bg-slate-100 text-[11px] font-semibold uppercase tracking-wider text-slate-600;
  }
  .th {
    @apply px-3 py-3 text-left font-semibold;
  }
  .td {
    @apply px-3 py-3 align-middle;
  }
  .table-compact .th {
    @apply px-2.5 py-2;
  }
  .table-compact .td {
    @apply px-2.5 py-2;
  }
  .row-hover tbody tr {
    @apply transition;
  }
  .row-hover tbody tr:hover {
    @apply bg-slate-50;
  }
  .table-sticky thead th {
    @apply sticky top-0 z-10 bg-slate-100;
  }

  /* ---------- Tabs (tab internal panel, bukan tab modul utama) ---------- */
  .tabs {
    @apply flex flex-wrap items-center gap-1;
  }
  .tab-link {
    @apply rounded-lg px-3.5 py-2 text-xs font-bold text-slate-500 transition hover:bg-slate-100 hover:text-slate-800;
  }
  .tab-link-active {
    @apply bg-sky-600 text-white shadow-sm hover:bg-sky-700 hover:text-white;
  }

  /* ---------- Tipografi utilitas ---------- */
  .stat-value {
    @apply font-mono text-2xl font-black text-slate-900;
  }
  .mono {
    @apply font-mono;
  }
  .divider {
    @apply h-3.5 w-px bg-slate-300;
  }
  .link {
    @apply font-semibold text-sky-700 underline-offset-2 hover:text-slate-900 hover:underline;
  }
  .chip {
    @apply inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold;
  }
  .badge {
    @apply inline-flex items-center gap-1 rounded border px-2 py-0.5 text-[11px] font-bold;
  }
  .scroll-x {
    @apply overflow-x-auto overflow-y-hidden;
  }

  /* ---------- Blok kosong (state "belum ada data") ---------- */
  .empty-block {
    @apply rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-xs text-slate-500;
  }

  /* ---------- Animasi (lihat keyframes di bawah file) ---------- */
  .anim-fade {
    animation: ewFadeIn 0.15s ease-out both;
  }
  .anim-pop {
    animation: ewPopIn 0.16s ease-out both;
  }
  .anim-slide {
    animation: ewSlideIn 0.18s ease-out both;
  }
  .anim-grow-x {
    animation: ewGrowX 0.5s ease-out both;
    transform-origin: left center;
  }
  .anim-spin {
    animation: ewSpin 0.7s linear infinite;
  }
}
```

Di luar blok itu, di file yang sama:

```css
.pulse-live { animation: livePulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
@keyframes livePulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.96); } }

@media print {
  body * { visibility: hidden; }
  #print-area, #print-area * { visibility: visible; }
  #print-area { position: absolute; left: 0; top: 0; width: 100%; }
  .no-print { display: none !important; }
}

@keyframes ewFadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes ewPopIn  { from { opacity: 0; transform: translateY(8px) scale(0.98); } to { opacity: 1; transform: none; } }
@keyframes ewSlideIn{ from { opacity: 0; transform: translateX(16px); } to { opacity: 1; transform: none; } }
@keyframes ewGrowX { from { transform: scaleX(0); } to { transform: scaleX(1); } }
@keyframes ewSpin  { to { transform: rotate(360deg); } }

@media print {
  body { background: #ffffff !important; }
  .print-border { border: 1px solid #cbd5e1 !important; box-shadow: none !important; }
  .table thead, .thead { background: #f1f5f9 !important; }
}
```

Token warna kustom (`tailwind.config.js`): `hospital-{50,100,500,600,700,800}`,
`ewsCritical`, `ewsWarning`, `ewsNormal`. Font: `font-sans` = Inter,
`font-mono` = JetBrains Mono. Plugin: `@tailwindcss/forms`,
`@tailwindcss/container-queries`.

> `tailwind.config.js` `content` =
> `['./storage/framework/views/*.php', './resources/views/**/*.blade.php', './resources/js/**/*.{vue,js}']`.
> Kontrak ini tidak boleh diubah oleh developer halaman. Kalau butuh kelas
> `@layer components` yang belum ter-build, daftarkan di
> `Components/uiKitClasses.js` (§4.4).

---

## 6. KONTRAK DATA SERVICE -> HALAMAN

> Konvensi global (`ClinicalFormat`):
> - Nilai mentah dikirim apa adanya pada field biasa (`recordedAt` = ISO 8601
>   dengan offset, `date` = `Y-m-d`, `time` = `H:i`).
> - Field `*Label` sudah siap tampil (`d/m/Y`, `H:i`, `'-'` untuk kosong).
> - Label sumbu grafik: `d/m H:i` (`chartLabel`).
> - Semua nilai date/time = **string** setelah serialisasi Inertia. Tidak ada
>   objek Carbon, tidak ada model Eloquent — kecuali dua method yang ditandai
>   di §7.
> - Hampir semua `string` boleh `null`. Tabel di bawah menyebutkannya.

### 6.1 `AdmissionService::getBannerPayload(Encounter $encounter): array`

**Tepat 12 kunci** — persis 12 atribut `data-patient="*"` di phase1. **Jangan
tambah / hapus** tanpa menyinkronkan `PatientHeaderBanner.vue`.
Semua nilai `string`, tidak pernah `null` (sudah di-`dash()`).

| # | key | contoh nyata |
|---|---|---|
| 1 | `name` | `"OO JAENAL"` |
| 2 | `initials` | `"OJ"` |
| 3 | `demographics` | `"Laki-Laki · 70 tahun"` |
| 4 | `mrn` | `"159853"` |
| 5 | `payment` | `"BPJS PBI"` (nilai enum mentah) |
| 6 | `unitBed` | `"Ruang Nusa Indah / Bed 02"` |
| 7 | `admission` | `"06/09/2026 08:30"` |
| 8 | `dpjp` | `"dr. Rangga Saputra, Sp.An-TI, Subsp.T.I.(K)"` |
| 9 | `allergies` | `"Ciprofloxacin"` |
| 10 | `diagnoses` | `"Syok septik ec HAP, ARDS mild-moderate, AKI stage 2"` |
| 11 | `latestObsTimestamp` | `"07/09/2026 12:00"` |
| 12 | `latestObsRecordedBy` | `"dr. Rangga Saputra"` |

### 6.2 `AdmissionService::listPatients(array $filters = []): array<int, array>`

Filter: `search` (alias `q`) -> nama/MRM/patient_id partial; `unit`;
`status` (default `aktif`; `''` atau `'all'` = semua status). Default order:
risiko tertinggi -> EWS tertinggi -> observasi terbaru. `[]` bila tidak ada
episode.

| key | tipe | null? | contoh |
|---|---|---|---|
| `encounterId` | string | tidak | `"enc-163901-hcu-20260912"` |
| `patientId` | string | tidak | `"patient-163901"` |
| `mrn` | string | tidak (`'-'`) | `"163901"` |
| `name` | string | tidak (`'-'`) | `"RATNA SARI DEWI"` |
| `initials` | string | tidak (`'??'`) | `"RS"` |
| `sex` | string | **boleh null** | `"Perempuan"` |
| `sexLabel` | string | tidak (`'-'`) | `"Perempuan"` |
| `age` | int | **boleh null** | `36` |
| `demographics` | string | tidak (`'-'`) | `"Perempuan · 36 tahun"` |
| `payment` | string | **boleh null** | `"BPJS Non PBI"` |
| `paymentLabel` | string | tidak (`'-'`) | `"BPJS Non PBI"` |
| `unit` | string | tidak (`'-'`) | `"HCU Anggrek"` |
| `bed` | string | tidak (`'-'`) | `"Bed 06"` |
| `unitBed` | string | **boleh null** | `"HCU Anggrek / Bed 06"` |
| `admittedAt` | string (ISO) | tidak | `"2026-09-12T06:50:00+00:00"` |
| `admittedAtLabel` | string | tidak | `"12/09/2026 06:50"` |
| `losDays` | int | tidak | `19` |
| `dpjp` | string | tidak (`'-'`) | `"dr. Maya Lestari, Sp.PD"` |
| `status` | string | **boleh null** | `"aktif"` |
| `statusLabel` | string | tidak (`'-'`) | `"Aktif"` |
| `diagnosisSummary` | string | tidak (`'-'`) | `"Preeklamsia berat, Anemia ringan"` |
| `diagnosisCodes` | string[] | tidak (`[]`) | `["O14.1"]` |
| `allergies` | string | tidak (`'-'`) | `"Latex"` |
| `allergyAlert` | string | **boleh null** | `"Latex"` |
| `hasAllergies` | bool | tidak | `true` |
| `latestObsAt` | string (ISO) | **boleh null** | `"2026-09-13T10:00:00+00:00"` |
| `latestObsAtLabel` | string | tidak | `"13/09/2026 10:00"` |
| `latestObsBy` | string | tidak (`'-'`) | `"Bd. Sari Wulandari"` |
| `latestEws` | int | **boleh null** | `0` |
| `latestRisk` | string | tidak | `"low"` (juga `"none"`) |
| `latestRiskLabel` | string | tidak (`'-'`) | `"Low"` |
| `latestRiskTone` | string | tidak | `"bg-emerald-100 text-emerald-800 border-emerald-200"` |
| `badgeClasses` | object | tidak | `{ class, bg, text, border }` |
| `atRisk` | bool | tidak | `false` (`risk` = `high`/`emergency`) |
| `medicationCount` | int | tidak | `37` |
| `completion` | object | tidak | `{ asmed: bool, nursingCare: bool, diagnosis: bool, procedure: bool }` |
| `completionCount` | int | tidak | `2` |

### 6.3 `AdmissionService::getProfile(Encounter): array`

| key | bentuk |
|---|---|
| `patient` | `patientId, mrn, name, initials, sex (null), sexLabel, age (null), demographics, birthDate ('Y-m-d'｜null), birthDateLabel, address (null), phone (null), bloodType ('-'), allergies, hasAllergies (bool), payment (null), paymentLabel` |
| `encounter` | `encounterId, unit, bed, unitBed (null), admittedAt, admittedAtLabel, dischargedAt (ISO｜**null**), losDays, attendingPhysician, dpjp, status (null), statusLabel, diagnosisSummary, allergyAlert (null), notes (null)` |
| `diagnoses` | `Array<{ id:int, type:string｜null, typeLabel:string, text:string, code:string｜null, isPrimary:bool, author:string｜null }>` |
| `procedures` | `Array<{ id:int, name:string, code:string｜null, performedAt:'Y-m-d'｜null, performedAtLabel, operator:string }>` |
| `asmed` | **`null`** atau `{ id, complaint, history, physicalExam, vitals (string, sudah di-`implode`), plan, examiner, examinedAt, examinedAtLabel, hasPlan:bool }` |
| `nursingCare` | **`null`** atau `{ id, assessment, problems, interventions, nurse, shift｜null, shiftLabel, recordedAt, recordedAtLabel }` |
| `medicalNoteCount` | int |
| `completion` | `{ asmed, nursingCare, diagnosis, procedure }` (bool) |
| `observationCount` | int |
| `medicationCount` | int |
| `careTeam` | `Array<{ key:'dpjp'｜'lastNurse'｜'lastExaminedBy', label, name }>` — selalu 3 |
| `devices` | sama dengan `BundleService::getDeviceSummary()` — **8 baris** (§6.7) |
| `latestObservation` | baris flowsheet (`null` bila belum ada observasi) — §6.4 |

### 6.3b `AdmissionService::getCppt(Encounter): array`

Halaman CPPT: enam kartu statistik + daftar catatan progres.
`notes` diurutkan dari yang **paling lama** (urutan default relasi
`Encounter::medicalNotes`, yaitu `noted_at` lalu `order_number`) karena
kronologi progres dokumen yang ditandatangani memang dibaca berurutan.

| key | bentuk |
|---|---|
| `stats` | object 9 kunci (lihat tabel di bawah) |
| `notes` | `Array<Note>` — `[]` bila belum ada CPPT |

`stats`:

| key | tipe | null? | contoh |
|---|---|---|---|
| `asmed` | string `'Ada'` | **boleh null** | `"Ada"` (null bila ASMED belum diisi) |
| `asmedDetail` | string | tidak | `"dr. Rangga Saputra, Sp.An-TI - 06/09/2026 09:10"` / `"belum diisi"` |
| `nursingCare` | string `'Ada'` | **boleh null** | `"Ada"` |
| `nursingCareDetail` | string | tidak | `"Shift Pagi - 06/09/2026 09:30"` / `"belum diisi"` |
| `diagnosis` | int | tidak | `3` |
| `procedure` | int | tidak | `2` |
| `ews` | int | **boleh null** | `0` (null bila belum ada observasi) |
| `ewsDetail` | string | tidak | `"07/09/2026 12:00"` / `"belum ada observasi"` |
| `observation` | int | tidak | `10` |

`Note`:

| key | tipe | null? | contoh |
|---|---|---|---|
| `id` | int | tidak | `1` |
| `order` | int | tidak | `1` |
| `authorName` | string | tidak (`'-'`) | `"dr. Rangga Saputra, Sp.An-TI, Subsp.T.I.(K)"` |
| `authorRole` | string | **boleh null** | `"dokter"` |
| `authorRoleLabel` | string | **boleh null** | `"Dokter"` |
| `authorSpecialty` | string | **boleh null** | `"Konsultan Intensif"` |
| `noteType` | string | **boleh null** | `"CPPT Awal"` |
| `subjective` | string | **boleh null** | `"Sesak napas dan penurunan kesadaran sejak 1 hari. ..."` |
| `objective` | string | **boleh null** | `"GCS E3-Vt-M5, akral dingin, ..."` |
| `assessment` | string | **boleh null** | `"Syok septik ec HAP dengan ARDS mild-moderate dan AKI stage 2. ..."` |
| `plan` | string | **boleh null** | `"Intubasi ETT 7.5 dengan ventilator PSIMV, ..."` |
| `notedAt` | ISO | **boleh null** | `"2026-09-06T09:10:00+00:00"` |
| `notedAtLabel` | string | tidak | `"06/09/2026 09:10"` |

> Enam kartu statistik untuk UI: `asmed`, `nursingCare`, `diagnosis`,
> `procedure`, `ews`, `observation` — masing-masing dengan baris detail
> (`asmedDetail`, `nursingCareDetail`, `ewsDetail`).

### 6.4 `ObservationService::getFlowsheet(Encounter, array $filters = []): array<int, FlowRow>`

`FlowRow` = hasil `rowFrom()`. **TERBARU DI ATAS.** `[]` bila tidak ada.
Filter: `dateFrom`/`date_from`, `dateTo`/`date_to` (`Y-m-d`), `risk`/`ews_risk`
(string tunggal atau array), `hasBundle` (bool), `medication` (nama obat
partial), `search` (catatan / petugas / nama obat).

| key | tipe | null? | contoh |
|---|---|---|---|
| `id` | int | tidak | `10` |
| `date` | `'Y-m-d'` | **boleh null** | `"2026-09-07"` |
| `time` | `'H:i'` | **boleh null** | `"12:00"` |
| `recordedAt` | ISO | **boleh null** | `"2026-09-07T12:00:00+00:00"` |
| `recordedAtLabel` | string | tidak | `"07/09/2026 12:00"` |
| `dateLabel` | string | tidak | `"07/09/2026"` |
| `timeLabel` | string | tidak | `"12:00"` |
| `sys` | int | **boleh null** | `114` |
| `dia` | int | **boleh null** | `68` |
| `map` | int | **boleh null** | `83` (dihitung server bila kosong) |
| `bpLabel` | string | tidak | `"114/68"` |
| `hr` | int | **boleh null** | `84` |
| `rhythm` | string | **boleh null** | `"Sinus Ritme"` |
| `rr` | int | **boleh null** | `16` |
| `breathType` | string | **boleh null** | `"PSIMV (FiO2 30%)"` |
| `suhu` | float | **boleh null** | `37` |
| `spo2` | int | **boleh null** | `98` |
| `o2Support` | string | **boleh null** | `"Ventilator PSIMV"` |
| `kesadaran` | string | tidak | `"DPO"` (bisa `"DPO (RASS -2)"`) |
| `gcs` | int | **boleh null** | `10` |
| `gcsText` | string | **boleh null** | `"E3-Vt-M5"` (verbatim `observations.gcs_text`; jatuh ke angka `gcs` bila bentuk lama) |
| `rass` | int | **boleh null** | `-2` (kolom integer; fallback ke `ventilator_settings.rass`) |
| `weightKg` | float | **boleh null** | `72.5` |
| `bpMethod` | string | **boleh null** | `"IBP"` (`NIBP`/`IBP`) |
| `bloodGlucose` | int | **boleh null** | `142` |
| `respiratoryProblem` | string | **boleh null** | `"Tidak"` (`Ya`/`Tidak`) |
| `transfusionType` | string | **boleh null** | `"PRC"` (`PRC`/`FFP`/`TC`) |
| `transfusionVolume` | float | **boleh null** | `350` |
| `parenteralVolume` | float | **boleh null** | `500` |
| `enteralVolume` | float | **boleh null** | `250` |
| `urineVolume` | float | **boleh null** | `400` |
| `drainVolume` | float | **boleh null** | `80` |
| `iwlVolume` | float | **boleh null** | `45` |
| `nursingAction` | string | **boleh null** | `"Reposisikan miring kanan / miring kiri per 2 jam"` |
| `ventilator` | string | **boleh null** | `"ventilator\\nmode PSIMV\\n..."` |
| `intake` | float | **boleh null** | `320` |
| `output` | float | **boleh null** | `200` |
| `fluidBalance` | float | **boleh null** | `120` |
| `ewsTotal` | int | tidak | `0` (0-18) |
| `ewsRisk` | string | tidak | `"low"` |
| `riskLabel` | string | tidak | `"Low"` |
| `riskTone` | string | tidak | `"bg-emerald-100 …"` |
| `badgeClasses` | object | tidak | `{ class, bg, text, border }` |
| `recordedBy` | string | tidak (`'-'`) | `"dr. Rangga Saputra"` |
| `status` | string | **boleh null** | `"final"` |
| `medicationCount` | int | tidak | `4` |
| `medicationNames` | string[] | tidak | `["Norepinefrin 4 mg / 50 mL", …]` |
| `hasBundle` | bool | tidak | `true` |
| `bundlePercent` | int | tidak | `100` (0-100, pembagi = item **yang dijawab**) |
| `notes` | string | **boleh null** | `"Rencana: monitoring …"` |

### 6.5 `ObservationService::getTelemetry(Encounter): array`

| key | bentuk |
|---|---|
| `latest` | `FlowRow｜null` (§6.4) |
| `previous` | `FlowRow｜null` |
| `deltas` | `{ hr:int, sbp:int, spo2:int, suhu:float, rr:int }` — **semua 0** bila `latest` atau `previous` null. `sbp` = selisih `sys` (bukan `map`). |
| `atRiskCount` | int (jumlah observasi `high` + `emergency` sepanjang episode) |
| `lastUpdatedAt` | ISO｜**null** |
| `lastUpdatedBy` | string｜**null** |

Nilai nyata: `{ "hr": -2, "sbp": 2, "spo2": 1, "suhu": 0.1, "rr": 0 }`,
`atRiskCount: 2`.

### 6.6 `ObservationService::getEwsTrend(Encounter, int $limit = 24): array`

| key | bentuk |
|---|---|
| `points` | `Array<{ at: ISO, label: 'd/m H:i', total: int, risk: string, riskLabel: string }>` — **LAMA ke BARU** (beda dari `getFlowsheet`!) |
| `max` | int, selalu `18` (plafon skala, **bukan** maksimum data) |
| `avg` | float, `0.0` bila `points` kosong |

### 6.7 `BundleService`

**`getSummary(Encounter): array`**

| key | bentuk |
|---|---|
| `groups` | **object dengan 3 kunci tetap `vap`, `clabsi`, `cauti`** (selalu lengkap walau tidak dijawab). Tiap nilai: `{ group, label, deviceCode, deviceLabel, deviceDays:int｜**null**, itemCount:int, answered:int, compliant:int, percent:float, tone:'emerald'｜'sky'｜'amber'｜'red', items: object }` |
| `groups.<g>.items` | object `item_key -> { key, label, answer:'ya'｜'tidak'｜**null**, answerLabel:'Ya'｜'Tidak'｜'-', compliant:bool, note:string｜**null** }` |
| `overall` | `{ group:'overall', groupLabel:'Seluruh Bundle', itemCount, answered, compliant, percent:float, tone }` — `itemCount` = 13 |
| `latestAt` | ISO｜**null** |
| `latestAtLabel` | `'d/m/Y H:i'`｜**null** |

**`getHistory(Encounter, int $limit = 7): Array<{ observationId:int, at:ISO, label:'d/m H:i', vap:float, clabsi:float, cauti:float, overall:float }>`**
— **TERBARU DI ATAS**; balik dengan `array_reverse` untuk menggambar garis.
Nilai per grup = persen pada observasi itu, `0.0` bila grup tidak dijawab.

**`getGaps(Encounter, int $limit = 12): Array<{ group, groupLabel, itemKey, itemLabel, answer:'tidak', answerLabel:'Tidak', observationAt:ISO, observationAtLabel, deviceLabel, deviceDays:int｜**null** }>`**
— satu baris per `item_key`, observasi **TERBARU** yang menjawab "Tidak".
`[]` bila tidak ada gap.

**`getDeviceSummary(Encounter): Array`** — **selalu 8 baris** (seluruh katalog
`config('hai.device_catalog')`), urut `is_active` desc lalu `start_date`:

| key | tipe | null? | contoh |
|---|---|---|---|
| `code` | string | tidak | `"ett"` |
| `label` | string | tidak | `"Endotracheal Tube / Tracheostomy Tube"` |
| `startDate` | `'Y-m-d'` | **boleh null** | `"2026-09-06"` |
| `startDateLabel` | string | tidak | `"06/09/2026"` |
| `days` | int | **boleh null** | `2` (inklusif +1 vs tanggal observasi terakhir) |
| `isActive` | bool | tidak | `true` |
| `needsReview` | bool | tidak | `false` (`isActive && days >= reviewAfterDays`) |
| `reviewAfterDays` | int | tidak | `7` (`config('hai.device_review_after_days')`) |
| `bundleGroup` | string | **boleh null** | `"vap"` |
| `bundleLabel` | string | **boleh null** | `"VAP Bundle (Ventilator)"` |

**`tone(float $percent): string`** — port `percentTone()`:
`>=100 'emerald'`, `>=80 'sky'`, `>=50 'amber'`, else `'red'`.

### 6.8 `MedicationRecapService` (9 method)

Semua baris **TERBARU DI ATAS**, kecuali `getFluidBalance()` (`rows` LAMA->BARU).

| method | return |
|---|---|
| `getAdministrations(Encounter, array $filters = [])` | `Array<MedicationRow>`; filter: `category`/`kategori` (string atau array), `search`/`q`, `dateFrom`, `dateTo` |
| `getSummary(Encounter)` | `{ total:int, uniqueDrugs:int, totalVolume:float, activeDrips:int, categoryCount:int, lastGivenAt:ISO｜**null**, lastGivenAtLabel｜**null**, lastGivenBy:string｜**null** }` |
| `getActiveDrips(Encounter)` | `Array<MedicationRow>` — satu baris per nama obat (case-insensitive) pada kategori `Inotropik / Vasopressor` + `Sedasi & Analgesia`, baris terbaru menang |
| `getFluidBalance(Encounter, int $hours = 24)` | `{ hours:int, intake:float, output:float, balance:float, cumulativeBalance:float, rows:Array<{at:ISO, label:'d/m H:i', intake:float, output:float, balance:float}> }` |
| `getCategoryDistribution(Encounter)` | `Array<{ category, categoryLabel, volume:float, percent:float, count:int }>` — **selalu 6** kategori, termasuk yang 0 |
| `getAllergyConflicts(Encounter)` | `{ hasConflict:bool, allergies:string[], conflicts:Array<{ id:int, name, matchedAllergy, rows:MedicationRow[] }> }` |
| `getTherapyPlanChecks(Encounter)` | `Array<{ key, label, inPlan:bool, evidence:string｜**null** }>` — 4 baris |
| `getFormularium(Encounter)` | `Array<{ name, dose, category, categoryLabel, always:bool, inPlan:bool, givenCount:int, realizationLabel }>` |
| `inferCategory(?string $name): string` (**static**) | kategori obat dari nama; pola dari `config('formularium.category_patterns')`; default `Lainnya` |

`MedicationRow`:

| key | tipe | null? | contoh |
|---|---|---|---|
| `id` | int | tidak | `43` |
| `observationId` | int | tidak | `10` |
| `observationDate` | `'Y-m-d'` | **boleh null** | `"2026-09-07"` |
| `observationTime` | `'H:i'` | **boleh null** | `"12:00"` |
| `recordedAt` | ISO | **boleh null** | `"2026-09-07T12:00:00+00:00"` |
| `recordedAtLabel` | string | tidak | `"07/09/2026 12:00"` |
| `name` | string | tidak | `"Dextrose 5%"` |
| `dose` | string | tidak (`'-'`) | `"Maintenance 40 mL/jam"` |
| `category` | string | tidak | `"Cairan & Elektrolit"` |
| `categoryLabel` | string | tidak | `"Cairan & Elektrolit"` |
| `volume` | float | tidak | `500` |
| `status` | string | tidak (`'-'`) | `"diberikan"` |
| `route` | string | **boleh null** | `"IV"` |
| `recordedBy` | string | tidak (`'-'`) | `"dr. Rangga Saputra"` |

> **`getFluidBalance()` memakai `now()` sebagai acuan**, jadi untuk encounter
> historis (tanggal observasi sudah lewat) `intake`/`output`/`balance` = `0` dan
> `rows` = `[]`, sedangkan `cumulativeBalance` tetap terisi (total seluruh
> episode). Ini perilaku yang benar, bukan bug. `cumulativeBalance` itulah yang
> ditampilkan sebagai "kumulatif selama perawatan".

`getCategoryDistribution().percent` = persen terhadap total 6 kategori, dan
pembulatan dikoreksi pada baris terakhir supaya jumlah tepat 100.

### 6.9 `SupportService` (10 method)

| method | return |
|---|---|
| `getGroup(Encounter, SupportGroup\|string $group)` | `Array<SupportRow>` untuk satu kelompok (`'lab'`, `'blood'`, `'micro'`, `'rad'`) |
| `getAll(Encounter)` | `{ lab: SupportRow[], blood: SupportRow[], micro: SupportRow[], rad: SupportRow[] }` — **AGD tidak termasuk** |
| `getAbgRecords(Encounter)` | `Array<AbgRow>` — **TERBARU DI ATAS** |
| `getLatestAbg(Encounter)` | `AbgRow｜**null**` |
| `getSummary(Encounter)` | `{ labCount:int, abnormalCount:int, culturePending:int, radiologyCount:int, abgPh:float｜**null**, abgAt:ISO｜**null**, abgAtLabel｜**null**, los:int }` |
| `getPending(Encounter)` | `Array<{ id, key, label, value, at:ISO｜**null**, atLabel, daysWaiting:int｜**null**, by }>` — kultur `micro` yang `numeric_value` null dan nilainya salah satu marker `pending｜menunggu｜diproses｜belum｜pending lab｜-` |
| `getAbnormal(Encounter)` | `Array<{ key, label, value, numericValue:float｜**null**, unit:string｜**null**, reference, flag, flagLabel, resultedAt:ISO｜**null**, resultedAtLabel }>` — hanya `lab` + `blood`, hasil terbaru per nama item, **kritis didahulukan** |
| `getTrend(Encounter, string $group, ?string $key = null, int $limit = 20)` | `{ key, label, unit:string｜**null**, points:Array<{at,label,value:float}>, min:float｜**null**, max:float｜**null** }` — `points` **LAMA ke BARU**; `key` null -> item bawaan kelompok (`leukosit` untuk `lab`, `kreatinin` untuk `blood`); bila tidak ada default -> `{ key:'', label:'', unit:null, points:[], min:null, max:null }`; `min`/`max` dilebarkan sampai memuat batas rentang referensi |
| `saveSupportResult(Encounter, string $group, array $data, User)` | **MODEL** `SupportResult` — §7 |
| `deleteSupportResult(Encounter, string $group, string $key): bool` | `true` bila ada baris terhapus (selalu hasil **TERBARU**) |

Public method lain: `deleteAbg(Encounter, int $id): bool` (§7).

`SupportRow`:

| key | tipe | null? | contoh |
|---|---|---|---|
| `key` | string | tidak | `"leukosit"` |
| `label` | string | tidak | `"Leukosit"` |
| `value` | string | tidak (`'-'`) | `"18.4"` |
| `numericValue` | float | **boleh null** | `18.4` |
| `unit` | string | **boleh null** | `"10^3/uL"` |
| `flag` | string | tidak | `"high"` — `critical｜high｜low｜normal｜none`, plus nilai mentah kolom DB (`pending`, `selesai`) bila item tak punya rentang referensi |
| `flagLabel` | string | tidak | `"Tinggi"` / `"Kritis"` / `"Rendah"` / `"Normal"` / `'-'` |
| `reference` | string | tidak | `"4 - 11 10^3/uL"` |
| `resultedAt` | ISO | **boleh null** | `"2026-09-06T09:45:00+00:00"` |
| `resultedAtLabel` | string | tidak | `"06/09/2026 09:45"` |
| `by` | string | tidak (`'-'`) | `"Dra. Sari Wulandari"` |

> Baris hanya muncul bila **ada** hasilnya; panjang daftar isi tetap
> ditentukan katalog (`ReferenceRange::catalog()`).

`AbgRow`:

| key | tipe | null? | contoh |
|---|---|---|---|
| `id` | int | tidak | `3` |
| `ph` | float | tidak | `7.4` |
| `pco2` | float | tidak | `37` |
| `po2` | float | tidak | `95` |
| `hco3` | float | tidak | `24` |
| `be` | float | **boleh null** | `0.1` |
| `sao2` | float | **boleh null** | `97` |
| `fio2` | float | **boleh null** | `40` |
| `method` | string | tidak (`'-'`) | `"Arteri"` |
| `measuredAt` | ISO | **boleh null** | `"2026-09-07T06:00:00+00:00"` |
| `measuredAtLabel` | string | tidak | `"07/09/2026 06:00"` |
| `by` | string | tidak (`'-'`) | `"Dra. Sari Wulandari"` |
| `abnormal` | bool | tidak | `false` |
| `flags` | `Array<{ key, label, value:float, flag, flagLabel }>` | tidak (`[]`) | urut tetap `ph, pco2, po2, hco3, be, sao2` |

### 6.10 `EwsScoringService` (dipakai halaman observasi bila perlu hitung ulang)

| method | return |
|---|---|
| `calculate(array $vitals): array` | `{ scores: {RR,HR,SBP,SpO2,Temp,Kesadaran:int}, total:int, risk:string, riskLabel:string, level:int(1-4), maxTotal:int, components:{ param -> { label, value, score, band, inGap } } }` |
| `scoreParameter(string $parameter, mixed $value): int` | `0` bila kosong / jatuh celah band |
| `totalFrom(array $scores): int` | jumlah, null = 0 |
| `riskFrom(int $total): string` | `'low'｜'medium'｜'high'｜'emergency'` |
| `riskLabel(string $risk): string` | `'Low'｜'Medium'｜'High'｜'Emergency'｜'-'` |
| `riskTone(string $risk): string` | string kelas gabungan |
| `badgeClasses(int $total, string $risk): array` | `{ class, bg, text, border }` |
| `levelFrom(string $risk): int` | `1`-`4`, `0` bila unknown/`none` |
| `maxTotal(): int` | `18` |
| `consciousnessFromInput(mixed $value): ?ConsciousnessLevel` | terima `"DPO (RASS -2)"` |
| `const RISK_NONE` | `'none'` |

Alias input: `RR: rr｜respirasi｜freq`; `HR: hr｜nadi｜pulse`;
`SBP: sys｜sbp｜sistolik｜map_sistolik`; `SpO2: spo2｜sp_o2｜saturasi`;
`Temp: suhu｜temp｜temperatur｜temperature`;
`Kesadaran: kesadaran｜kesadarankey｜awareness｜consciousness`.
Matching bersifat case-insensitive sebagai jaring pengaman.

### 6.11 `ClinicalFormat` & `ReferenceRange` (helper — jangan dipanggil dari halaman)

`ClinicalFormat`: `EMPTY='-'`, `dash`, `date` (`d/m/Y`), `time` (`H:i`),
`dateTime` (`d/m/Y H:i`), `chartLabel` (`d/m H:i`), `dateLabel`, `timeLabel`,
`dateTimeLabel`, `iso` (ISO 8601 + offset), `number` (`1.234,56`),
`signed` (`+180/-120/0`), `numeric`, `integer`, `planFragment`, `splitTokens`,
`parse`.

`ReferenceRange`: `LEVEL_CRITICAL='critical'`, `LEVEL_HIGH='high'`,
`LEVEL_LOW='low'`, `LEVEL_NORMAL='normal'`, `LEVEL_NONE='none'`,
`ABNORMAL_LEVELS=[critical, high, low]`, plus `lab()`, `catalog()`, `abg()`,
`trendTargets()`, `defaultTrendKey($group)`, `reference($key)`, `meta($key)`,
`catalogPosition($group, $key)`, `classify($value, $ref)` -> `{ level, label }`,
`abgFlags($values)`, `referenceLabel($key)`, `unit($key)`, `decimals($key)`.

Katalog (`ReferenceRange::catalog()`) — urutan menentukan urutan baris tabel:

- `lab`: `leukosit` Leukosit/Hematologi, `hgb` Hemoglobin/Hematologi,
  `pjk` Platelet/Hematologi, `lpf` Lembuk LPF/Hematologi, `crp` CRP/Inflamasi,
  `prokalcitonin` Prokalcitonin/Inflamasi
- `blood`: `laktat` Laktat/Gas Darah, `kreatinin` Kreatinin/Kimia,
  `bun` BUN/Kimia, `natrem` Na/Elektrolit, `kalium` K/Elektrolit,
  `glukosa` Gula Darah/Kimia, `albumin` Albumin/Kimia,
  `bilirubin` Bilirubin/Fungsi Hati
- `micro`: `kultur_darah`, `kultur_sputum`, `kultur_urine` (Mikrobiologi)

Item yang tidak ada di katalog tetap dikembalikan `meta()` dengan `panel: '-'`
dan `group: ''`, serta `catalogPosition() = 999` (muncul paling akhir).

---

## 7. WRITE PATH (MUTASI)

> **Semua method baca mengembalikan array. DUA method tulis berikut
> mengembalikan MODEL ELOQUENT — controller WAJIB mengonversinya sebelum
> dikirim ke Inertia.**

| method | input | return | catatan |
|---|---|---|---|
| `ObservationService::save(Encounter, array $data, User): array` | §7.1 | `{ observation: FlowRow, created: bool, duplicated: bool }` | **upsert** pada `(encounter_id, observation_date, observation_time)`. `duplicated === !created`. Slot jam = idempotency key. Semua penulisan dalam SATU `DB::transaction()`. Skor EWS **dihitung ulang di server** — jangan kirim skor dari client. `MAP` dihitung server bila kosong (`dia + (sys-dia)/3`, hanya bila `sys >= dia > 0`). |
| `ObservationService::delete(Encounter, string $date, string $time): bool` | `date` `Y-m-d`, `time` `H:i` atau `H:i:s` | `true` bila ada baris terhapus | Baris anak cascade; `encounters.latest_observation_at` dihitung ulang. |
| `SupportService::saveSupportResult(Encounter, string $group, array $data, User): SupportResult` | §7.2 | **MODEL** | `$group` ∈ `lab｜blood｜micro｜rad`. `lab`/`blood` WAJIB angka; `micro`/`rad` teks bebas WAJIB (`numeric_value` boleh null). |
| `SupportService::saveAbg(Encounter, array $data, User): AbgResult` | §7.3 | **MODEL** | `ph` & `pco2` WAJIB; `po2`/`hco3` default `0` bila kosong. |
| `SupportService::deleteSupportResult(Encounter, string $group, string $key): bool` | | `true` bila terhapus | Selalu result **TERBARU** untuk key itu. |
| `SupportService::deleteAbg(Encounter, int $id): bool` | id harus milik encounter ini | `true` bila terhapus | |

> **ValidationException** dilempar oleh `save()`, `saveSupportResult()`, dan
> `saveAbg()`. Di controller: biarkan lempar (Laravel mengembalikan 422 +
> `props.errors`) **atau** tangkap dan `->withErrors($e->errors)`.
> Pesan validasi sudah bahasa Indonesia.

### 7.1 `ObservationService::save()` — bentuk input

Diterima **snake_case Laravel ATAU nama phase1** (yang dipakai duluan menang).

| field (dipilih) | alias | wajib | keterangan |
|---|---|---|---|
| `observationDate` | `observation_date`, `date` | **ya** | `Y-m-d`; error `observation_date` = "Tanggal observasi wajib diisi." |
| `observationTime` | `observation_time`, `time` | **ya** | `HH:MM`; error `observation_time` = "Jam observasi wajib diisi (format HH:MM)." |
| `sys` | `sistolik` | **ya** | rentang 20-350 |
| `dia` | `diastolik` | **ya** | rentang 5-250 |
| `hr` | `nadi` | **ya** | rentang 10-300 |
| `rr` | `respirasi` | **ya** | rentang 0-100 |
| `spo2` | `saturasi` | **ya** | rentang 20-100 |
| `suhu` | `temp`, `temperatur` | **ya** | rentang 20-50 |
| `kesadaran` | `consciousness` | **ya** | `ConsciousnessLevel` |
| `map` | `mapValue`, `map_value` | tidak | rentang 5-300; dihitung server bila kosong |
| `gcs` | | tidak | **TEKS** (maks 50 karakter) - `#inputGCS` pada formulir prototipe, mis. `"E3-Vt-M5"`. Bila isinya bukan angka, verbatim-nya disimpan di kolom `gcs_text` dan kolom integer `gcs` dibiarkan `null`; bila angka bulat 3-15, angka itu juga yang mengisi `gcs` supaya baris lama tetap terbaca. |
| `gcsText` | `gcs_text` | tidak | bentuk eksplisit dari uraian verbatim di atas |
| `rass` | | tidak | integer -4..4. Disimpan ke kolom `rass` **dan** `ventilator_settings.rass` (keduanya sinkron). |
| `weight` | `weightKg`, `weight_kg` | tidak | rentang 0-500, kolom `weight_kg` |
| `bloodGlucose` | `blood_glucose`, `glucose` | tidak | rentang 0-2 000, kolom `blood_glucose` |
| `bpMethod` | `bp_method`, `bloodPressureMethod`, `blood_pressure_method` | tidak | enum `NIBP`/`IBP` |
| `respiratoryProblem` | `respiratory_problem`, `lungIssue`, `lung_issue` | tidak | enum `Ya`/`Tidak` |
| `transfusionType` | `transfusion_type` | tidak | enum `PRC`/`FFP`/`TC` |
| `transfusionVolume` | `transfusion_volume` | tidak | rentang 0 - 1 000 000 |
| `parenteral` | `parenteralVolume`, `parenteral_volume` | tidak | rentang 0 - 1 000 000 |
| `enteral` | `enteralVolume`, `enteral_volume` | tidak | rentang 0 - 1 000 000 |
| `urine` | `urineVolume`, `urine_volume` | tidak | rentang 0 - 1 000 000 |
| `drain` | `drainVolume`, `drain_volume` | tidak | rentang 0 - 1 000 000 |
| `iwl` | `iwlVolume`, `iwl_volume` | tidak | rentang 0 - 1 000 000. Bila kosong, dihitung `round(15 x weight / 24)` dari `weight`. |
| `nursingAction` | `nursing_action` | tidak | maks 255 karakter; nilainya adalah TEKS label opsi (`Tindakan Keperawatan` pada formulir prototipe) |
| `intake` | | tidak | rentang 0 - 1 000 000 |
| `output` | | tidak | rentang 0 - 1 000 000 |
| `rhythm` | | tidak | |
| `breathType` | `breath_type` | tidak | |
| `o2Support` | `o2_support` | tidak | |
| `ventilatorSettings` | `ventilator_settings` | tidak | array |
| `notes` | | tidak | |
| `status` | | tidak | default `'final'` |
| `recordedBy` | `recorded_by` | tidak | default `$user->name` |
| | | | **Sintesis baris cairan (server, di dalam `syncMedications()`):** `transfusionType` + `transfusionVolume > 0` -> `Transfusi <TYPE>` / `<vol> mL` / `Obat Systemic` / indikasi `Transfusi komponen darah`; `parenteral > 0` -> `Cairan Parenteral` / `Cairan & Elektrolit` / `Infus parenteral`; `enteral > 0` -> `Cairan Enteral` / `Cairan & Elektrolit` / `Pemberian enteral`. Ketiganya ditambahkan SETELAH baris yang dikirim klien, jadi modul Farmasi tetap melihat baris cairan walau formulir hanya mengirim baris koreksi. Nama/dosis/kategori/indikasi/status disalin dari `buildMedicationEntries()` phase1. |
| `medications` | `medicines`, `drugs` | tidak | array of `{ name (WAJIB), dose, category, volume, route, status, sort_order }`. **`category` di-infer dari `name` bila kosong.** Baris tanpa `name` **dilewati** (tidak error). `dose` default `'-'`, `volume` default `0`, `status` default `'diberikan'`. **Delete-then-insert** (urutan = urutan ketikan). |
| `bundles` | `bundleAnswers`, `bundle_answers` | tidak | **peta** `{ vap_1: 'Ya', … }` **atau** **array** `[{ key, answer, note }]`. `answer` dinormalisasi (`Ya`/`Tidak`, case-insensitive). Item dengan key tak dikenal atau answer null **dilewati**. |
| `replaceBundles` | `replace_bundles`, `bundlesReplace` | tidak | bool; `true` berarti item yang tidak ada di payload ikut dihapus |
| `devices` | `invasiveDevices`, `invasive_devices` | tidak | array of `{ key｜device_code, present｜is_active, startDate, label, note }`. `key` harus ada di `DeviceCode`. `present:true` -> buat bila belum ada (`start_date` default = tanggal observasi) atau update bila sudah aktif; `present:false` -> tutup baris aktif dengan `end_date`. `label` default `DeviceCode::label()`. |

`observation` yang dikembalikan adalah baris flowsheet dengan bentuk **persis
sama** dengan `getFlowsheet()`, jadi bisa langsung disisipkan tanpa rebuild.

### 7.2 `SupportService::saveSupportResult()`

```php
$result = $support->saveSupportResult($encounter, 'lab', [
    'key'        => 'leukosit',   // atau 'result_key'  (WAJIB, non-kosong)
    'value'      => '18.4',       // WAJIB. lab/blood harus angka; micro/rad teks bebas
    'unit'       => '10^3/uL',    // opsional, default dari ReferenceRange
    'flag'       => 'high',       // opsional, default dari classify()
    'reference'  => '4 - 11 ...', // opsional, default dari ReferenceRange
    'resultedAt' => '2026-09-07T09:30:00+00:00', // alias: resulted_at, at; default now()
    'by'         => 'dr. ...',    // alias: created_by; default $user->name
], $request->user());
```

`numeric_value` diisi otomatis dari `value` bila angka. Group tak dikenal ->
`ValidationException` pada field `group` ("Kelompok penunjang tidak dikenal.").
`value` kosong/tidak angka pada `lab`/`blood` -> "Nilai harus berupa angka."

### 7.3 `SupportService::saveAbg()`

```php
$abg = $support->saveAbg($encounter, [
    'ph' => 7.4,        // WAJIB 5-9
    'pco2' => 37,       // WAJIB 10-150
    'po2' => 95,        // opsional 10-700, default 0
    'hco3' => 24,       // opsional 3-60,  default 0
    'be' => 0.1,        // opsional -35..35
    'sao2' => 97,       // opsional 30-100
    'fio2' => 40,       // opsional 21-100
    'method' => 'Arteri',           // default 'Arteri'
    'measuredAt' => '2026-09-07T06:00:00+00:00', // alias measured_at, at; default now()
    'by' => 'dr. ...',               // alias created_by; default $user->name
], $request->user());
```

Field juga dibaca dari kapital (`PH`, `PCO2`, ...). Nilai di luar rentang ->
`ValidationException` "... di luar rentang wajar (min - max)."

### 7.4 Contoh controller (pola yang diharapkan)

```php
public function store(Request $request, Encounter $encounter)
{
    $data = $request->validate([ /* ... */ ]);

    $result = $this->observations->save($encounter, $data, $request->user());

    return back()->with('success', $result['duplicated']
        ? 'Slot jam tersebut diperbarui.'
        : 'Observasi EWS tersimpan.');
}
```

---

## 8. ENAM TAB KLINIS

`CLINICAL_TABS` (dari `resources/js/router.js`; `ClinicalLayout` merendernya
apa adanya). `ClinicalTab` = `{ key, label, short, icon, tone, iconClass, route }`.

| # | `key` | `label` | `short` | `icon` | `tone` | `iconClass` | `route` | URL dengan `encounter = enc-159853-icu-20260906` |
|---|---|---|---|---|---|---|---|---|
| 1 | `profil` | `Profil & Ringkasan` | `Profil` | `fa-id-card` | `sky` | `text-sky-400` | `profil` | `/encounters/enc-159853-icu-20260906/profil` |
| 2 | `cppt` | `Medis & CPPT` | `CPPT` | `fa-user-doctor` | `emerald` | `text-emerald-400` | `cppt` | `/encounters/enc-159853-icu-20260906/cppt` |
| 3 | `penunjang` | `Penunjang & AGD` | `Penunjang` | `fa-flask-vial` | `purple` | `text-purple-400` | `penunjang` | `/encounters/enc-159853-icu-20260906/penunjang` |
| 4 | `farmasi` | `Farmasi & Drip Inotropik` | `Farmasi` | `fa-pills` | `amber` | `text-amber-400` | `farmasi` | `/encounters/enc-159853-icu-20260906/farmasi` |
| 5 | `observasi` | `Observasi EWS & Hemodinamik` | `Observasi` | `fa-chart-line` | `yellow` | `text-yellow-300` | `observasi` | `/encounters/enc-159853-icu-20260906/observasi` |
| 6 | `bundles` | `Bundle HAIs (VAP/CLABSI/CAUTI)` | `Bundle HAIs` | `fa-shield-virus` | `teal` | `text-teal-400` | `bundles` | `/encounters/enc-159853-icu-20260906/bundles` |

`icon` dirender sebagai `<i class="fa-solid" :class="[tab.icon, tab.iconClass]">`
— sudah mengandung `fa-solid` di markup, jadi `icon` **tidak** perlu prefix
`fa-solid`.

Halaman pasif `activeTab` = `''` (mis. daftar pasien) — tidak ada tab aktif.
Markup tiap tab: `<a class="..." :aria-current="tab.isActive ? 'page' : undefined"
:data-tab="tab.key" :data-active="...">`.

**Pasangan halaman <-> tab** (WAJIB, dipakai `activeTab`):

| halaman | `activeTab` | `encounterId` |
|---|---|---|
| `Pasien.vue` | `''` | `null` |
| `Profil.vue` | `'profil'` | `props.encounter.encounterId` |
| `CppT.vue` | `'cppt'` | sama |
| `Penunjang.vue` | `'penunjang'` | sama |
| `Farmasi.vue` | `'farmasi'` | sama |
| `Observasi.vue` | `'observasi'` | sama |
| `Bundles.vue` | `'bundles'` | sama |

Kerangka halaman (WAJIB konsisten):

```vue
<script setup>
import ClinicalLayout from '@/Layouts/ClinicalLayout.vue';
import PatientHeaderBanner from '@/Components/PatientHeaderBanner.vue';
</script>

<template>
  <ClinicalLayout title="Observasi EWS & Hemodinamik" active-tab="observasi" :encounter-id="encounterId">
    <template #status>
      <PatientHeaderBanner :patient="banner" module-status="82% - 9/11 butir" status-tone="teal" />
    </template>

    <!-- kartu, tabel, grafik -->
  </ClinicalLayout>
</template>
```

---

## 9. TIGA PERSONA

Dari `App\Enums\UserRole` (hanya tiga nilai yang ada) + `UserSeeder`:

| username | password | `auth.user.id` | `auth.user.name` | `role` | `roleLabel` | `specialty` | `initials` |
|---|---|---|---|---|---|---|---|
| `perawat` | `perawat123` | `1` | `Ns. Tri Handayani` | `perawat` | `Perawat` | `Perawat ICU` | `NH` |
| `bidan` | `bidan123` | `2` | `Bd. Sari Wulandari` | `bidan` | `Bidan` | `Bidan` | `SW` |
| `dokter` | `dokter123` | `3` | `dr. Rangga Saputra` | `dokter` | `Dokter` | `Konsultan Intensif` | `RS` |

> `roleLabel` = `UserRole::label()` -> `Perawat` / `Bidan` / `Dokter`
> (**bukan** "Perawat ICU"; itu `specialty`).
> `initials` = `HandleInertiaRequests::initials()` (lihat catatan §2).

### 9.1 Matriks otorisasi (dari `docs/AUTHORIZATION.md`)

| Kemampuan | Perawat | Bidan | Dokter |
|---|:--:|:--:|:--:|
| Daftar / cari pasien | Ya | Ya | Ya |
| Lihat encounter aktif dan riwayat tas | Ya | Ya | Ya |
| **Input observasi vital + Skor EWS** | Ya | Ya | Ya |
| Isi bundel asuhan (serial, vom, GCS, pupils) | Ya | Ya | Ya |
| **Asuhan keperawatan** | Ya | Ya | Ya |
| **Administrasi obat** (dosis, rute, waktu) | Ya | Ya | Ya |
| Rekap dosis harian | Ya | Ya | Ya |
| Device invasive / ABG / penunjang | Ya | Ya | Ya |
| **ASMED** (anamnesis, pemeriksaan fisik) | - | - | Ya |
| **Diagnosis** | - | - | Ya |
| **Prosedur / tindakan** | - | - | Ya |
| **CPPT** | - | - | Ya |
| Tutup encounter (discharge) | - | - | Ya |
| Edit rekam medis yang sudah ditutup | - | - | Ya |

Semua peran **membaca** apa pun yang dibutuhkan untuk konteks shift-nya. Yang
dibatasi adalah **menulis**, bukan membaca. Tidak ada peran admin.

```php
// boleh semua petugas
->middleware('role:perawat,bidan,dokter');
// khusus dokter
->middleware('role:dokter');
```

### 9.2 Nilai enum lain yang muncul di payload

- `EncounterStatus`: `aktif` + status lain; `label()` berbahasa Indonesia.
- `Sex` / `PaymentType`: `value` sudah berupa teks Indonesia
  (`"Laki-Laki"`, `"Perempuan"`, `"BPJS PBI"`, ...) sehingga `sex` dan `sexLabel`
  sering identik.
- `NursingShift`: `"Pagi"`, ...
- `BundleAnswer`: `value` `"ya"` / `"tidak"` (kecil), `label()` `"Ya"` / `"Tidak"`.
- `DeviceCode`: `ett`, `cvc`, `dc`, ... dengan `label()` dan `bundleGroup()`.
- `MedicationCategory`: 6 nilai; `label()` = kunci label yang juga dipakai
  `MEDICATION_TONE`.

---

## 10. YANG SUDAH DIVERIFIKASIKAN

- `npm run build` hijau.
- 8 pemeriksaan auth via `curl.exe` + cookie jar: 200 / 302 / 429 sesuai §1.1.
- `data-page` Inertia berisi `errors, auth, appName, csrfToken, nav, flash`
  dan `auth.user` berisi `role`, `roleLabel`, `username`, `initials`.
- Seluruh 16 komponen + `ClinicalLayout` di-mount via `createSSRApp` +
  `renderToString` dengan payload nyata dari database:
  **50/50 kasus tanpa error**, termasuk kasus `null` / `{}` / edge-case.
- Semua kelas Tailwind yang dipakai layout & komponen ada di CSS hasil build
  (termasuk `max-w-[1720px]`, `bg-slate-800/80`, `pulse-live`,
  `text-ewsCritical`, `hospital-700`, `no-print`).
- `php -l` bersih di 84 file; `vendor\bin\pint.bat --test app routes bootstrap`
  bersih kecuali `bootstrap/cache/*.php` (file generated Laravel, bukan sumber).
- Tidak ada `alert(` / `prompt(` / `confirm(` di `resources/js/`.
- `package.json` tidak berubah; tidak ada library chart/icon di `node_modules`.
