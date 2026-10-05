/**
 * scripts/fixtures/inertia-stub.mjs
 *
 * Stub minimal untuk `@inertiajs/vue3`, dipakai HANYA oleh
 * scripts/check-render.mjs lewat alias Vite. Build aplikasi tidak pernah
 * menyentuh file ini.
 *
 * Kenapa perlu: useForm() dari @inertiajs/vue3 memanggil usePage() di dalam
 * setup() untuk membaca props.errors. usePage() melempar error kalau tidak ada
 * Inertia app yang aktif, sehingga tidak ada satu pun komponen yang memakai
 * useForm (halaman Observasi dan formulirnya) yang bisa di-mount di luar
 * browser.
 *
 * Bentuknya sengaja meniru implementasi asli: `useForm()` asli mengembalikan
 * `reactive({ ... })` yang membungkus refs, sehingga `form.processing` dan
 * `form.errors` terbaca sebagai nilai biasa di template. Stub yang memakai
 * plain object berisi ref akan memicu peringatan "Invalid prop: type check
 * failed" yang BISANYA bukan bug aplikasi.
 *
 * Yang TIDAK di-stub: Vue. Reaktivitas di sini asli, sehingga alur
 * defaults/reset/transform/error pada formulir tetap ikut teruji.
 */
import { reactive, ref } from 'vue';

/** State global yang minimal untuk halaman tanpa Inertia app. */
const page = reactive({
    props: {
        errors: {},
        auth: { user: null },
        appName: 'SIMRS RSP Rotinsulu',
        csrfToken: '',
        nav: { backUrl: '/pasien', backLabel: 'Daftar Pasien' },
        flash: { success: null, error: null },
    },
    url: '/',
    component: 'Observasi',
    version: 'check-render',
});

export function usePage() {
    return page;
}

export const router = {
    get() {},
    post() {},
    put() {},
    patch() {},
    delete() {},
    reload() {},
    visit() {},
    get current() {
        return { url: page.url, params: {}, component: page.component, props: page.props };
    },
};

/**
 * Head() tidak merender apa pun tanpa Inertia, jadi komponen dibungkus di
 * dalam Fragment agar struktur DOM tidak berubah karena stub ini.
 */
export const Head = {
    setup() {
        return () => null;
    },
};

/** Nama milik Inertia yang tidak boleh terhapus oleh reset(). */
const FORM_RESERVED = new Set([
    'isDirty', 'errors', 'hasErrors', 'processing', 'progress', 'wasSuccessful',
    'recentlySuccessful', 'defaults', 'reset', 'clearErrors', 'setError',
    'transform', 'get', 'post', 'put', 'patch', 'delete', 'submit',
]);

export function useForm(initial = {}) {
    const defaults = { ...initial };
    const errors = reactive({});
    const processing = ref(false);
    const wasSuccessful = ref(false);
    const recentlySuccessful = ref(false);

    /** Submit palsu: hanya menandai sedang proses, tidak menembak jaringan. */
    const submit = () => {
        processing.value = true;

        return Promise.resolve();
    };

    /*
     * Bentuknya meniru `useForm()` asli: data form disebar langsung ke objek
     * reactive, BUKAN dikunci di `.data`. Karena itu `form.sys` /
     * `form.observation_time` terbaca sebagai nilai biasa, persis di produksi.
     */
    const form = reactive({
        ...defaults,
        isDirty: false,
        errors,
        hasErrors: false,
        processing,
        progress: null,
        wasSuccessful,
        recentlySuccessful,

        defaults(next) {
            Object.assign(defaults, next);
        },

        reset(...fields) {
            if (fields.length === 0) {
                for (const key of Object.keys(form)) {
                    if (FORM_RESERVED.has(key)) continue;
                    delete form[key];
                }

                Object.assign(form, { ...defaults });

                return;
            }

            for (const field of fields) delete form[field];
        },

        clearErrors(...fields) {
            for (const key of Object.keys(errors)) delete errors[key];
            void fields;
        },

        setError(field, message) {
            errors[field] = message;
        },

        transform(callback) {
            Object.assign(form, callback({ ...form }));
        },

        get: submit,
        post: submit,
        put: submit,
        patch: submit,
        delete: submit,
        submit,
    });

    return form;
}

export default { usePage, router, Head, useForm };