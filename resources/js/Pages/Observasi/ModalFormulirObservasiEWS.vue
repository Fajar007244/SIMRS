<script setup>
/**
 * ModalFormulirObservasiEWS.vue - region ModalFormulirObservasiEWS pada
 * phase1/observasi.html (#observationModal), termasuk script
 * data-purpose="modal-and-calc-handlers" dan bagian ews-config yang dipakai
 * pratinjaunya.
 *
 * LIMA FIELD SET, urutan dan isi PERSIS seperti markup prototype:
 *   section-observasi-umum      Tanggal, Waktu, Berat badan, Tingkat Kesadaran,
 *                               RASS (hanya bila DPO), GCS (ETT)
 *   section-vitals              Metode TD, Sistolik, Diastolik, MAP, Nadi,
 *                               Irama Nadi, Respirasi, Tipe Napas, Suhu,
 *                               Gula Darah, SpO2, Gangguan Paru,
 *                               Alat Bantu Oksigen + Jenis Dukungan O2
 *   section-fluid-management    Transfusi, Cairan Transfusi, Parenteral,
 *                               Enteral, editor koreksi, Total Intake,
 *                               Output Cairan, Ringkasan Fluid Balance
 *   section-hai-bundles         2 kolom: ETT / VAP / CVC / Tubing / NGT di kiri,
 *                               Arteri / Dower / CAUTI / CLABSI / Infus /
 *                               Tindakan Lain di kanan
 *   section-notes-and-vent      Ventilasi (textarea) + Tindakan Keperawatan
 *                               (select) + kotak "Rencana Implementasi Lanjutan"
 *
 * TIDAK ADA SATU PUN atribut `required` di formulir ini, sama seperti
 * prototype. Semua numeric input memakai placeholder "Isi angka".
 *
 * PERILAKU YANG DIPINDAHKAN DARI PROTOTYPE
 *  - MAP = Diastolik + (Sistolik - Diastolik) / 3, dibulatkan. Syaratnya:
 *    sistolik > 0, diastolik > 0, dan sistolik >= diastolik. Rumus yang sama
 *    dipakai ObservationService::save(), jadi kolom MAP tidak pernah
 *    berbeda antara tampilan dan server.
 *  - IWL per jam = round(15 x berat badan / 24). Dipicu ulang setiap kali
 *    berat badan berubah, lalu memanggil recalculateFluids().
 *  - Total intake = transfusi + parenteral + enteral + jumlah volume koreksi.
 *  - Total output = urine + drain + IWL.
 *  - Baris koreksi yang benar-benar kosong tidak dikirim.
 *  - Tanggal perangkat invasif yang kosong mengikuti tanggal observasi
 *    (syncBundleDatesWithObservation()).
 *  - RASS hanya tampil saat tingkat kesadaran DPO (toggleRassVisibility()).
 *  - Kolom "Jenis Dukungan O2" hanya tampil saat Alat Bantu Oksigen = Ya, dan
 *    dikosongkan saat bukan Ya.
 *  - Tanggal + jam diisi otomatis dengan waktu lokal saat formulir dibuka.
 *  - Baris Minimal satu: menghapus baris terakhir hanya mengosongkannya
 *    (baris terakhir tidak pernah hilang).
 *
 * PERILAKU YANG SENGAJA BERUBAH DARI PROTOTYPE
 *  - Tidak ada dialog konfirmasi browser untuk slot jam yang sudah terisi.
 *    ObservationService::save() bersifat UPSERT dan mengembalikan flag
 *    `duplicated`, yang ditampilkan controller sebagai flash
 *    "Slot jam ini sudah ada, data diperbarui."
 *  - Skor EWS yang tampil adalah pratinjau sisi klien (EwsLivePreview.vue).
 *    Yang disimpan tetap hasil hitungan server, dan plakat "pratinjau saja"
 *    sengaja ditampilkan di bawahnya.
 *  - Kolom `kesadaran` yang dikirim adalah kunci enum `DPO`, bukan string
 *    tampilan `DPO (RASS -2)`. RASS dikirim terpisah pada field `rass`.
 *  - Validasi rentang dijalankan di sisi klien memakai kalimat Indonesia yang
 *    PERSIS sama dengan ObservationService::RANGE_MESSAGES, jadi pesan yang
 *    tampil sama dengan yang akan dilempar server. Server tetap memeriksa
 *    ulang; validasi klien hanya menghemat satu kali bolak-balik.
 *  - Kolom `status` tidak lagi punya kontrol di UI (tidak ada di prototype).
 *    Nilainya tetap dikirim mengikuti baris yang disunting, atau 'final'.
 *
 * PROPS
 *   open            Boolean
 *   row             Object|null  baris flowsheet yang disunting, null = baru
 *   bundleAnswers   Object       { item_key: 'Ya'|'Tidak' }
 *   devices         Array        8 baris BundleService::getDeviceSummary()
 *   patient         Object       banner pasien untuk strip identitas header
 *   reference       Object       WAJIB, katalog formulir
 *   storeUrl        String       WAJIB
 *   defaultDate     String       tanggal default untuk observasi baru
 *   encounterId     String       encounter_id untuk tautan silang modul
 *   admissionCompletion Object   { asmed, nursingCare, diagnosis, procedure }
 *                                status pengisian 4 modul lain pada strip
 *                                tab admisi (Encounter::completion)
 *
 * EMITS
 *   close        void
 *   saved        void
 *   headerScore  String  label kategori EWS uppercase untuk #ewsHeaderScore
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import MedicationRowsEditor from './MedicationRowsEditor.vue';
import EwsLivePreview from './EwsLivePreview.vue';
import { previewState, maxTotal, integer, numeric } from './ewsPreview';
import { inferCategory } from './medicationCategory';
import { bundleTone } from '@/tone';

const props = defineProps({
    open: { type: Boolean, default: false },
    row: { type: Object, default: null },
    bundleAnswers: { type: Object, default: () => ({}) },
    devices: { type: Array, default: () => [] },
    patient: { type: Object, default: null },
    reference: { type: Object, required: true },
    storeUrl: { type: String, required: true },
    defaultDate: { type: String, default: '' },
    encounterId: { type: String, default: '' },
    admissionCompletion: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved', 'headerScore']);

/* ---------------------------------------------------------------- konstanta */

const RHYTHM_OPTIONS = ['', 'Reguler', 'Sinus Reguler', 'Sinus Takikardia', 'Sinus Bradikardia', 'Aritmia'];
const BREATH_OPTIONS = ['', 'Spontan', 'Reguler', 'Dangkal', 'Dalam', 'Takipnea', 'Bradipnea', 'Dengan bantuan ventilator'];
const O2_SUPPORT_OPTIONS = ['', 'Nasal Kanul', 'Simple Mask', 'NRM', 'Venturi Mask', 'HFNC', 'Ventilator'];

/**
 * Label panjang untuk nilai `NRM`. phase1 memakai value="NRM" dengan teks
 * "Non-Rebreathing Mask (NRM)"; nilai itu yang tersimpan di observations
 * (supaya kolom o2_support tetap ringkas), sedangkan teks panjangnya hanya
 * untuk tampilan.
 */
const O2_SUPPORT_LABELS = { NRM: 'Non-Rebreathing Mask (NRM)' };

function o2SupportLabel(value) {
    return O2_SUPPORT_LABELS[value] || value;
}

const TRANSFUSION_OPTIONS = [
    { value: '', label: 'Pilih' },
    { value: 'PRC', label: 'Packed Red Cell (PRC)' },
    { value: 'FFP', label: 'Fresh Frozen Plasma (FFP)' },
    { value: 'TC', label: 'Thrombocyte Concentrate (TC)' },
];

/**
 * Tiga pilihan "Tindakan Keperawatan" pada prototype. <option> di sana tidak
 * punya atribut value, jadi nilainya adalah teks labelnya - itu yang disimpan
 * pada kolom `nursing_action`.
 */
const NURSING_ACTIONS = [
    'Suction ETT berkala & oral hygiene dg Chlorhexidine 0.2%',
    'Reposisikan miring kanan / miring kiri per 2 jam',
    'Aff Infus & Pasang Ulang Baru',
];

/** Kotak "Rencana Implementasi Lanjutan" - hanya baca, bukan input. */
const PLAN_SUGGESTIONS = [
    'Monitoring hemodinamik per jam',
    'Evaluasi balance cairan tiap 6 jam',
    'Kultur sputum ulang bila febris > 38.5 °C',
];

/**
 * Petunjuk (placeholder) textarea Ventilasi, teks sama persis dengan
 * prototype. BUKAN nilai tersimpan: field starts kosong dan teks ini hanya
 * tampil sebagai hint abu-abu sampai petugas mengetik.
 */
const VENT_PLACEHOLDER = 'ventilator\nmode PSIMV\nRR 6  PC 10\nPS 6\nPeep 6\nFiO2 40%';

/** Blok "MAINTENANCE (...)" pada prototype, urutan yang sama. */
const BUNDLE_BOXES = [
    { key: 'vap', title: 'MAINTENANCE (VAP BUNDLE)' },
    { key: 'cauti', title: 'MAINTENANCE (CAUTI BUNDLE)' },
    { key: 'clabsi', title: 'MAINTENANCE (CLABSI BUNDLE)' },
];

/**
 * Kiri: ETT, VAP, CVC, Tubing, NGT.
 * Kanan: Arteri, Dower, CAUTI, CLABSI, Infus, Tindakan Lain.
 */
const DEVICE_LAYOUT = {
    left: ['ett', 'cvc', 'ventTubing', 'ngt'],
    right: ['arterial', 'dc', 'infus', 'lain'],
};

const EMPTY_FLUID = '-';

/**
 * Richmond Agitation Sedation Scale, disalin dari blok #inputRASS phase1.
 *
 * Pemisah antara skor dan nama TIDAK hanya U+2001 EM QUAD: pada prototype
 * urutannya adalah <skor> + U+0020 SPACE + U+2001 EM QUAD + <nama>, misalnya
 * +4 SPACE EM QUAD ECombitive (...). Satu SPACE biasa di atas ikut, karena
 * tanpa itu label di layar tidak identik dengan prototipe.
 */
const RASS_SEP = ' \u2001';

const RASS_OPTIONS = [
    { value: '+4', label: `+4${RASS_SEP}ECombitive (Agresif, melawan ventilator)` },
    { value: '+3', label: `+3${RASS_SEP}EVery Agitated (Sangat gelisah)` },
    { value: '+2', label: `+2${RASS_SEP}EAgitated (Gelisah, gerakan aktif)` },
    { value: '+1', label: `+1${RASS_SEP}ERestless (Cemas/gerakan berlebih)` },
    { value: '0', label: `0${RASS_SEP}EAlert & Calm (Tenang, sadar penuh)` },
    { value: '-1', label: `-1${RASS_SEP}EDrowsy (Mengantuk, respons >10 detik)` },
    { value: '-2', label: `-2${RASS_SEP}ELight Sedation (Sedasi Ringan)` },
    { value: '-3', label: `-3${RASS_SEP}EModerate Sedation (Sedasi Sedang)` },
    { value: '-4', label: `-4${RASS_SEP}EDeep Sedation (Sedasi Dalam, respons nyeri)` },
    { value: '-5', label: `-5${RASS_SEP}EUnarousable (Tidak dapat dibangunkan)` },
];

/** rassDescMap phase1, disalin persis. */
const RASS_DESC = {
    '+4': 'Combative: melawan ventilator, mengeluarkan selang/alat',
    '+3': 'Very Agitated: menarik/memasang selang, agresif',
    '+2': 'Agitated: gerakan tak terarah, melawan ventilator',
    '+1': 'Restless: cemas, gerakan tidak aktif agresif',
    '0': 'Alert & Calm: sadar penuh dan tenang',
    '-1': 'Drowsy: sadar namun mengantuk, kontak mata >10 detik',
    '-2': 'Sedasi ringan: bangun singkat kontak mata saat diperintah (\u226410 detik)',
    '-3': 'Sedasi sedang: gerakan/buka mata saat diperintah, tanpa kontak mata',
    '-4': 'Sedasi dalam: tidak respons perintah, respons nyeri saja',
    '-5': 'Unarousable: tidak respons perintah maupun stimulasi nyeri',
};

/**
 * Strip tab admisi (#data-admission-tab pada prototype). Lima sel; empat
 * pertama membaca status pengisian dari `Encounter::getCompletionAttribute()`
 * lewat prop `admissionCompletion`, sel terakhir selalu statis.
 */
const ADMISSION_CELLS = [
    { key: 'asmed', label: 'ASMED', filled: 'Data Sudah Diisi', empty: 'Belum Diisi', active: true },
    {
        key: 'nursingCare',
        label: 'ASUHAN KEPERAWATAN / BIDAN',
        filled: '(Asuhan Keperawatan) Sudah Diisi',
        empty: 'Belum Diisi',
        active: false,
    },
    { key: 'diagnosis', label: 'DIAGNOSA', filled: 'Data Sudah Diisi', empty: 'Belum Diisi', active: false },
    { key: 'procedure', label: 'PROCEDURE', filled: 'Data Sudah Diisi', empty: 'Belum Diisi', active: false },
];

/**
 * Rentang sanity vital. Salinan PERSIS dari ObservationService::VITAL_RANGES
 * dan label dari ObservationService::RANGE_MESSAGES, supaya pesan yang tampil
 * di klien sama persis dengan yang akan dilempar server. Ubah ketiganya
 * bersama kalau batasnya berubah.
 */
const VITAL_RULES = {
    sys: { min: 20, max: 350, label: 'Tekanan sistolik' },
    dia: { min: 5, max: 250, label: 'Tekanan diastolik' },
    hr: { min: 10, max: 300, label: 'Nadi' },
    rr: { min: 0, max: 100, label: 'Frekuensi napas' },
    spo2: { min: 20, max: 100, label: 'Saturasi oksigen' },
    suhu: { min: 20, max: 50, label: 'Suhu tubuh' },
};

/* ------------------------------------------------------------------- form */

function pad2(value) {
    return String(value).padStart(2, '0');
}

/** nowTime() prototype: HH:MM waktu lokal. */
function nowTime() {
    const now = new Date();

    return `${pad2(now.getHours())}:${pad2(now.getMinutes())}`;
}

/** nowDate() prototype: YYYY-MM-DD waktu lokal. */
function nowDate() {
    const now = new Date();

    return `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}`;
}

function blankForm() {
    return {
        observation_date: props.defaultDate || nowDate(),
        observation_time: nowTime(),

        weight: '',
        bloodPressureMethod: 'IBP',
        sys: '',
        dia: '',
        hr: '',
        rhythm: '',
        rr: '',
        breathType: '',
        suhu: '',
        glucose: '',
        spo2: '',
        respiratoryProblem: 'Tidak',
        o2Enabled: 'Tidak',
        o2Support: '',

        kesadaran: 'DPO',
        rass: '-2',
        gcs: '',

        transfusionType: '',
        transfusionVolume: '',
        parenteral: '',
        enteral: '',
        medications: [{ name: '', dose: '', category: '', volume: '' }],

        urine: '',
        drain: '',

        ventilator: '',
        nursingAction: NURSING_ACTIONS[0],
    };
}

const form = useForm(blankForm());
const bundles = ref({});
const deviceRows = ref([]);
const replaceBundles = ref(true);

/** Status mengikuti baris yang disunting; tidak ada kontrol status di UI. */
const status = ref('final');

/**
 * Pesan validasi sisi klien. Deklarasi DI ATAS watcher penyemaian (`open`)
 * yang berjalan immediate, supaya tidak terkena temporal dead zone.
 */
const clientErrors = ref({});

const bundleItems = computed(() => (Array.isArray(props.reference?.bundleItems) ? props.reference.bundleItems : []));
const deviceCatalog = computed(() => (Array.isArray(props.reference?.deviceCatalog) ? props.reference.deviceCatalog : []));
const consciousnessOptions = computed(() => props.reference?.consciousnessLevels || {});

const bundleItemsByGroup = computed(() => {
    const grouped = { vap: [], cauti: [], clabsi: [] };

    for (const item of bundleItems.value) {
        if (grouped[item.group]) grouped[item.group].push(item);
    }

    return grouped;
});

const isEdit = computed(() => props.row !== null && props.row !== undefined);

/**
 * Kunci enum dari string tampilan. Kolom database hanya menyimpan kunci
 * kesadaran, sedangkan baris flowsheet sudah berbentuk display
 * ("DPO (RASS -2)"), jadi sufiks itu harus dibuang sebelum dikirim ulang.
 */
function consciousnessKey(label) {
    const text = String(label || '').trim();
    const match = text.match(/^([A-Za-z]+)/);

    if (!match) return '';

    const candidate = match[1];

    return Object.values(consciousnessOptions.value).includes(candidate) ? candidate : text;
}

/** Nilai RASS dari string tampilan "DPO (RASS -2)". */
function rassFromLabel(label) {
    const match = String(label || '').match(/\(RASS\s*([+-]?\d+)\)/);

    return match ? match[1] : '-2';
}

function deviceSeed() {
    const byCode = {};

    for (const device of Array.isArray(props.devices) ? props.devices : []) {
        byCode[device.code] = device;
    }

    return deviceCatalog.value.map((entry) => {
        const existing = byCode[entry.key];

        return {
            key: entry.key,
            label: entry.label,
            present: Boolean(existing && existing.isActive),
            startDate: existing && existing.startDate ? existing.startDate : '',
            note: entry.key === 'lain' ? 'Rawat luka ulkus dekubitus grade 1 sacrum' : '',
        };
    });
}

function bundleSeed() {
    const answers = {};
    const latest = props.bundleAnswers && typeof props.bundleAnswers === 'object' ? props.bundleAnswers : {};

    for (const item of bundleItems.value) {
        // Observasi baru: prototype memberi radio "Ya" sebagai pilihan awal
        // (atribut checked pada input pertama). Observasi yang disunting
        // memakai jawaban observasi terakhir yang dinilai.
        answers[item.key] = latest[item.key] || 'Ya';
    }

    return answers;
}

// `immediate: true` supaya deviceRows/bundles terisi sejak komponen di-mount,
// bukan hanya setelah `open` berubah. Tanpa itu, render pertama (atau 
// `open` sudah true sejak awal) akan membaca baris perangkat yang belum ada.
watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;

        const base = blankForm();

        if (props.row) {
            const row = props.row;

            form.defaults({
                ...base,
                observation_date: row.date || base.observation_date,
                observation_time: row.time || base.observation_time,
                weight: row.weightKg ?? '',
                bloodPressureMethod: row.bpMethod || 'IBP',
                sys: row.sys ?? '',
                dia: row.dia ?? '',
                hr: row.hr ?? '',
                rhythm: row.rhythm || '',
                rr: row.rr ?? '',
                breathType: row.breathType || '',
                suhu: row.suhu ?? '',
                glucose: row.bloodGlucose ?? '',
                spo2: row.spo2 ?? '',
                respiratoryProblem: row.respiratoryProblem || 'Tidak',
                o2Enabled: row.o2Support && row.o2Support !== 'Tidak' ? 'Ya' : 'Tidak',
                o2Support: row.o2Support && row.o2Support !== 'Tidak' ? row.o2Support : '',
                kesadaran: consciousnessKey(row.kesadaran),
                rass: row.rass === null || row.rass === undefined ? rassFromLabel(row.kesadaran) : String(row.rass),
                gcs: row.gcsText ?? (row.gcs === null || row.gcs === undefined ? '' : row.gcs),
                transfusionType: row.transfusionType || '',
                transfusionVolume: row.transfusionVolume ?? '',
                parenteral: row.parenteralVolume ?? '',
                enteral: row.enteralVolume ?? '',
                urine: row.urineVolume ?? '',
                drain: row.drainVolume ?? '',
                ventilator: row.ventilator || base.ventilator,
                nursingAction: row.nursingAction || base.nursingAction,
            });
            status.value = row.status || 'final';
        } else {
            form.defaults(base);
            status.value = 'final';
        }

        form.reset();
        form.clearErrors();
        bundles.value = bundleSeed();
        deviceRows.value = deviceSeed();
        replaceBundles.value = !isEdit.value;

        syncDeviceDates();
        clientErrors.value = {};
    },
    { immediate: true },
);

/* ------------------------------------------------------------- perhitungan */

/** MAP = Diastolik + (Sistolik - Diastolik) / 3, sama seperti server. */
const calculatedMap = computed(() => {
    const sys = numeric(form.sys);
    const dia = numeric(form.dia);

    if (sys === null || dia === null || sys <= 0 || dia <= 0 || sys < dia) return '';

    return String(Math.round(dia + (sys - dia) / 3));
});

/** IWL per jam = round(15 x berat badan / 24), seperti recalculateIWL() phase1. */
const iwl = computed(() => {
    const weight = numeric(form.weight);

    if (weight === null || weight <= 0) return 0;

    return Math.round((15 * weight) / 24);
});

const correctionVolume = computed(() =>
    (Array.isArray(form.medications) ? form.medications : []).reduce(
        (sum, row) => sum + (Number(row.volume) || 0),
        0,
    ),
);

/** recalculateFluids() phase1, untuk intake/output/balance. */
const totals = computed(() => {
    const transfusion = numeric(form.transfusionVolume) || 0;
    const parenteral = numeric(form.parenteral) || 0;
    const enteral = numeric(form.enteral) || 0;
    const intake = transfusion + parenteral + enteral + correctionVolume.value;

    const urine = numeric(form.urine) || 0;
    const drain = numeric(form.drain) || 0;
    const output = urine + drain + iwl.value;

    const diff = intake - output;
    const hasFluidData = intake > 0 || output > 0;

    return {
        transfusion,
        parenteral,
        enteral,
        intake,
        urine,
        drain,
        output,
        diff,
        hasFluidData,
        balanceLabel: hasFluidData ? (diff > 0 ? `+${diff}` : `${diff}`) : EMPTY_FLUID,
        intakeLabel: hasFluidData ? `${intake} mL` : EMPTY_FLUID,
        outputLabel: hasFluidData ? `${output} mL` : EMPTY_FLUID,
        balanceMlLabel: hasFluidData ? `${diff > 0 ? `+${diff}` : `${diff}`} mL` : EMPTY_FLUID,
    };
});

/**
 * Jendela 24 jam yang dipakai pada label ringkasan, mengikuti jam observasi
 * yang sedang diisi: berakhir pada jam observasi, mulai 24 jam sebelumnya.
 * phase1 menuliskan window statis "07:00 - 07:00"; di sini window ikut
 * bergerak mengikuti slot jam yang diisi supaya label tidak pernah bertentangan
 * dengan isi field.
 */
const fluidWindow = computed(() => {
    const match = String(form.observation_time || '').match(/^(\d{1,2}):(\d{2})$/);

    if (!match) return { start: EMPTY_FLUID, end: EMPTY_FLUID, label: `Balance 24 jam (${EMPTY_FLUID} - ${EMPTY_FLUID})` };

    const endMinutes = (Number(match[1]) * 60) + Number(match[2]);
    const start = (endMinutes - 1440 + 1440) % 1440;
    const startLabel = `${pad2(Math.floor(start / 60))}:${pad2(start % 60)}`;
    const endLabel = `${pad2(Number(match[1]))}:${match[2]}`;

    return {
        start: startLabel,
        end: endLabel,
        label: `Balance 24 jam (${startLabel} - ${endLabel})`,
    };
});

/** Pratinjau skor: band diambil langsung dari prop `reference`. */
const preview = computed(() =>
    previewState(
        {
            rr: form.rr,
            hr: form.hr,
            sys: form.sys,
            spo2: form.spo2,
            suhu: form.suhu,
            kesadaran: form.kesadaran,
        },
        props.reference?.ews?.parameters || {},
        props.reference?.ews?.escalation || [],
    ),
);

const scaleMax = computed(() => {
    const fromConfig = Number(props.reference?.ews?.max_total);

    return Number.isFinite(fromConfig) && fromConfig > 0 ? fromConfig : maxTotal();
});

/** RASS hanya relevan bila tingkat kesadaran DPO. */
const showRass = computed(() => form.kesadaran === 'DPO');
const rassDescription = computed(() => RASS_DESC[String(form.rass)] || '');

/** Jenis dukungan O2 hanya tampil saat Alat Bantu Oksigen = Ya. */
const showO2Support = computed(() => form.o2Enabled === 'Ya');

watch(
    () => form.o2Enabled,
    (value) => {
        if (value !== 'Ya') form.o2Support = '';
    },
);

const admissionCells = computed(() => {
    const completion = props.admissionCompletion && typeof props.admissionCompletion === 'object'
        ? props.admissionCompletion
        : {};

    return ADMISSION_CELLS.map((cell) => {
        const isFilled = completion[cell.key] === true;

        return {
            ...cell,
            isFilled,
            statusLabel: isFilled ? cell.filled : cell.empty,
            statusClass: isFilled ? 'text-emerald-200 font-normal' : 'text-amber-200 font-bold',
        };
    });
});

const errorFor = (field) => (form.errors && form.errors[field] ? String(form.errors[field]) : '');
const bundlesError = computed(() => (form.errors && form.errors.bundles ? String(form.errors.bundles) : ''));

const bundleAnswered = computed(() =>
    Object.values(bundles.value).filter((answer) => answer === 'Ya' || answer === 'Tidak').length,
);

const bundleCompliant = computed(() =>
    Object.values(bundles.value).filter((answer) => answer === 'Ya').length,
);

function deviceRowFor(key) {
    return deviceRows.value.find((row) => row.key === key) || null;
}

/**
 * Baris cadangan untuk kunci perangkat yang belum ada di `deviceRows`. Tanpa
 * ini template akan melempar "Cannot read properties of null" pada instant
 * pertama sebelum watcher selesai mengisi deviceRows - dan juga kalau
 * `reference.deviceCatalog` ternyata tidak memuat salah satu kunci tata letak.
 */
const blankDeviceRows = {};

function blankDeviceRow(key, entry) {
    if (!blankDeviceRows[key]) {
        blankDeviceRows[key] = {
            key,
            label: entry ? entry.label : key,
            present: false,
            startDate: '',
            note: '',
        };
    }

    return blankDeviceRows[key];
}

/**
 * `deviceFor()` SELALU mengembalikan row yang tidak null, supaya template aman
 * dipakai langsung (`:checked="deviceFor('ett').row.present"`). Baris sungguhan
 * dari `deviceRows` tetap dipakai bila ada, sehingga hasil edit-nya persisten.
 */
function deviceFor(key) {
    const entry = deviceCatalog.value.find((item) => item.key === key) || null;
    const row = deviceRowFor(key);

    return { entry, row: row || blankDeviceRow(key, entry) };
}

const leftDevices = computed(() => DEVICE_LAYOUT.left.map((key) => deviceFor(key)));
const rightDevices = computed(() => DEVICE_LAYOUT.right.map((key) => deviceFor(key)));

/**
 * syncBundleDatesWithObservation(): tanggal observasi baru diisi ke perangkat
 * yang tanggalnya masih kosong. Hanya untuk observasi BARU - menyunting slot
 * lama tidak boleh menggeser tanggal pemasangan.
 */
function syncDeviceDates() {
    const date = form.observation_date;

    if (!date || isEdit.value) return;

    deviceRows.value = deviceRows.value.map((row) => (row.startDate ? row : { ...row, startDate: date }));
}

watch(() => form.observation_date, syncDeviceDates);

function setDevicePresent(row, value) {
    if (row) row.present = value;
}

function setDeviceDate(row, value) {
    if (row) row.startDate = value;
}

function setDeviceNote(row, value) {
    if (row) row.note = value;
}

function groupBorder(group) {
    const palette = bundleTone(group);

    return palette.border;
}

/* ----------------------------------------------------- validasi sisi klien */


function rangeMessage(rule, value) {
    return `${rule.label} di luar rentang wajar (${rule.min} - ${rule.max}).`;
}

/**
 * Pemeriksaan bentuk payload yang sama persis dengan rules() di
 * ObservasiController. Pesannya TIDAK diarang ulang: kalimat untuk vital
 * disalin dari ObservationService::RANGE_MESSAGES.
 */
function buildClientErrors() {
    const errors = {};

    if (!String(form.observation_date || '').trim()) {
        errors.observation_date = 'Tanggal observasi wajib diisi.';
    }

    if (!/^\d{1,2}:\d{2}$/.test(String(form.observation_time || '').trim())) {
        errors.observation_time = 'Jam observasi wajib diisi (format HH:MM).';
    }

    for (const [field, rule] of Object.entries(VITAL_RULES)) {
        const raw = String(form[field] ?? '').trim();

        if (raw === '') {
            errors[field] = `${rule.label} wajib diisi.`;
            continue;
        }

        const value = numeric(raw);

        if (value === null) {
            errors[field] = `${rule.label} harus berupa angka.`;
            continue;
        }

        if (value < rule.min || value > rule.max) {
            errors[field] = rangeMessage(rule, value);
        }
    }

    if (!String(form.kesadaran || '').trim()) {
        errors.kesadaran = 'Tingkat kesadaran wajib diisi.';
    }

    return errors;
}

function messageFor(field) {
    if (clientErrors.value[field]) return clientErrors.value[field];

    return errorFor(field);
}

/* ------------------------------------------------------------------ submit */

/**
 * Baris koreksi yang dikirim: yang punya isi apa pun, dengan kategori hasil
 * inferensi regex yang sama dengan prototype (inferMedicationCategory).
 */
function medicationRows() {
    const rows = Array.isArray(form.medications) ? form.medications : [];

    return rows
        .map((row, index) => ({
            name: String(row.name || '').trim(),
            dose: String(row.dose || '').trim() || null,
            category: inferCategory(row.name) || null,
            volume: Number(row.volume) || 0,
            route: 'IV',
            status: 'diberikan',
            sort_order: index,
        }))
        .filter((row) => row.name || row.dose || row.volume > 0);
}

/**
 * buildMedicationEntries() phase1: baris koreksi ditambah tiga entri sintetis
 * dari isian cairan, supaya modul Farmasi ikut melihat baris transfusi dan
 * cairan. Nama, dosis, kategori, indikasi, dan statusnya disalin apa adanya.
 */
function extraMedicationRows() {
    const extras = [];
    const type = String(form.transfusionType || '').trim();
    const volume = Number(totals.value.transfusion) || 0;

    if (type && volume > 0) {
        extras.push({
            name: `Transfusi ${type}`,
            dose: `${volume} mL`,
            category: 'Obat Systemic',
            indication: 'Transfusi komponen darah',
            volume,
            route: 'IV',
            status: 'Diberikan',
        });
    }

    const parenteral = Number(totals.value.parenteral) || 0;

    if (parenteral > 0) {
        extras.push({
            name: 'Cairan Parenteral',
            dose: `${parenteral} mL`,
            category: 'Cairan & Elektrolit',
            indication: 'Infus parenteral',
            volume: parenteral,
            route: 'IV',
            status: 'Diberikan',
        });
    }

    const enteral = Number(totals.value.enteral) || 0;

    if (enteral > 0) {
        extras.push({
            name: 'Cairan Enteral',
            dose: `${enteral} mL`,
            category: 'Cairan & Elektrolit',
            indication: 'Pemberian enteral',
            volume: enteral,
            route: 'Enteral',
            status: 'Diberikan',
        });
    }
}

/**
 * GCS (ETT) pada prototype berupa TEKS ("Contoh: E3-Vt-M5"), jadi dikirim apa
 * adanya. ObservationService yang memisahkan bentuk angka (3-15) ke kolom
 * `gcs` dan bentuk teks ke kolom `gcs_text`, jadi tidak ada isian yang dibuang
 * diam-diam.
 */
function gcsPayload() {
    const text = String(form.gcs || '').trim();

    return { raw: text === '' ? null : text };
}

function submit() {
    clientErrors.value = buildClientErrors();

    if (Object.keys(clientErrors.value).length > 0) return;

    const totalsValue = totals.value;
    const gcs = gcsPayload();

    form.transform((data) => ({
        ...data,
        map: calculatedMap.value === '' ? null : integer(calculatedMap.value),
        weight: numeric(data.weight),
        blood_glucose: numeric(data.glucose),
        bp_method: data.bloodPressureMethod || null,
        respiratory_problem: data.respiratoryProblem || null,
        o2_support: showO2Support.value ? String(data.o2Support || '').trim() || 'Tidak' : 'Tidak',
        // Dikirim apa adanya sebagai teks, sama seperti field #inputGCS prototype.
        gcs: gcs.raw,
        rass: showRass.value ? String(data.rass || '').trim() : null,
        transfusion_type: data.transfusionType || null,
        transfusion_volume: numeric(data.transfusionVolume),
        parenteral: numeric(data.parenteral),
        enteral: numeric(data.enteral),
        urine: numeric(data.urine),
        drain: numeric(data.drain),
        iwl: totalsValue.hasFluidData || iwl.value > 0 ? iwl.value : null,
        intake: totalsValue.intake,
        output: totalsValue.output,
        nursing_action: String(data.nursingAction || '').trim() || null,
        notes: null,
        status: status.value,
        ventilator_settings: { ventilator: String(data.ventilator || '').trim() },
        medications: medicationRows(),
        bundles: bundles.value,
        replace_bundles: replaceBundles.value,
        devices: deviceRows.value.map((row) => ({
            key: row.key,
            present: row.present ? '1' : '0',
            startDate: row.startDate || null,
            note: row.note || null,
        })),
    })).post(props.storeUrl, {
        // preserveState menjaga nilai form ketika server membalas dengan error
        // validasi, jadi petugas tidak perlu mengetik ulang seluruh vital.
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            emit('saved');
            emit('close');
        },
    });
}

function requestClose() {
    if (form.processing) return;

    emit('close');
}

function onHeaderScore(value) {
    emit('headerScore', value);
}
</script>

<template>
    <!-- #observationModal pada prototype adalah overlay yang di-show/hide lewat
         style.display. Di sini Modal.vue sudah mengatur visibilitasnya sendiri
         (v-if di dalam Teleport), jadi wrapper ini hanya untuk menyalin
         id dan struktur luarnya. -->
    <div id="observationModal" data-purpose="observation-modal-root">
        <Modal
            :open="props.open"
            :title="isEdit ? 'Sunting Observasi EWS' : 'Tambah Observasi EWS'"
            subtitle="Formulir Monitoring Vital Sign, Cairan, Bundles Pasien ICU & Ruang Rawat Inap"
            icon="fa-solid fa-file-medical"
            size="xl"
            body-class="p-0"
            :close-on-escape="!form.processing"
            :close-on-backdrop="!form.processing"
            data-purpose="observation-modal"
            @close="requestClose"
        >
            <!-- ============ HEADER + STRIP TAB ADMISI ============ -->
            <template #header>
                <div data-purpose="modal-header">
                    <div class="flex items-center justify-between border-b border-slate-700 bg-slate-800 px-4 py-3 text-white">
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-md bg-sky-600 text-white">
                                <i class="fa-solid fa-file-medical text-base" aria-hidden="true"></i>
                            </div>
                            <div>
                                <h3 id="clinical-modal-title" class="text-base font-bold leading-tight tracking-wide text-white">
                                    {{ isEdit ? 'Sunting Observasi EWS' : 'Tambah Observasi EWS' }}
                                </h3>
                                <p class="text-xs text-slate-300">
                                    Formulir Monitoring Vital Sign, Cairan, Bundles Pasien ICU &amp; Ruang Rawat Inap
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-700 text-slate-400 transition hover:bg-slate-600 hover:text-white"
                            id="closeModalCross"
                            aria-label="Tutup"
                            data-purpose="modal-close"
                            @click="requestClose"
                        >
                            <i class="fa-solid fa-xmark text-lg" aria-hidden="true"></i>
                        </button>
                    </div>

                    <!-- Strip indikasi modul: lima sel, status 4 pertama dari
                         Encounter::getCompletionAttribute() lewat prop
                         admissionCompletion. prototype memakai <div> (bukan tautan)
                         dan sel terakhir selalu menyala. -->
                    <div class="grid grid-cols-2 bg-[#009b9e] text-center text-[11px] font-bold text-white md:grid-cols-5" data-purpose="admission-tabs">
                        <div
                            v-for="cell in admissionCells"
                            :key="cell.key"
                            class="border-r border-[#028486] py-2"
                            :class="cell.active ? 'bg-[#00898c]' : ''"
                            :data-admission-tab="cell.key"
                            :data-purpose="`admission-tab-${cell.key}`"
                        >
                            {{ cell.label }}
                            <span class="block" :class="cell.statusClass" data-admission-status>
                                {{ cell.statusLabel }}
                            </span>
                        </div>
                        <div class="animate-pulse bg-rose-700 py-2 font-black text-white" data-purpose="admission-tab-ews">
                            OBSERVASI EWS
                            <span class="block font-semibold text-rose-100">&bull; Data Pengisian EWS ICU atau Kemuning</span>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ============ IDENTIFIKASI PASIEN (12 kunci banner) ============ -->
            <div
                class="flex flex-wrap items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-2.5 text-xs text-slate-700"
                data-purpose="modal-patient-banner"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="flex h-6 w-6 items-center justify-center rounded-full bg-sky-600 text-[10px] font-bold text-white"
                        data-patient="initials"
                    >{{ patient && patient.initials ? patient.initials : '-' }}</span>
                    <span class="font-bold text-slate-900" data-patient="name">{{ patient && patient.name ? patient.name : '-' }}</span>
                    <span class="text-slate-400">|</span>
                    <span data-patient="demographics">{{ patient && patient.demographics ? patient.demographics : '-' }}</span>
                    <span class="text-slate-400">|</span>
                    <span class="font-mono" data-patient="mrn">
                        {{ patient && patient.mrn ? `No. RM: ${patient.mrn}` : 'No. RM: -' }}
                    </span>
                </div>
                <div class="flex items-center gap-3 text-[11px]">
                    <span>
                        Unit:
                        <strong class="rounded bg-sky-100 px-1.5 py-0.5 font-bold text-sky-700" data-patient="unitBed">
                            {{ patient && patient.unitBed ? patient.unitBed : '-' }}
                        </strong>
                    </span>
                    <span>
                        DPJP:
                        <strong class="text-slate-800" data-patient="dpjp">{{ patient && patient.dpjp ? patient.dpjp : '-' }}</strong>
                    </span>
                    <span
                        class="rounded border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-bold text-emerald-700"
                        data-patient="payment"
                    >{{ patient && patient.payment ? patient.payment : '-' }}</span>
                </div>
            </div>
            <!-- ============ ISI FORMULIR ============ -->
            <form
                id="ewsInputForm"
                class="max-h-[75vh] space-y-6 overflow-y-auto p-5 text-xs text-slate-800 sm:p-6"
                data-purpose="ews-input-form"
                novalidate
                @submit.prevent="submit"
            >
                <!-- ============ FIELD SET 1: OBSERVASI UMUM ============ -->
                <fieldset class="border-t border-slate-200 pt-4" data-purpose="section-observasi-umum">
                    <legend class="mb-3 flex items-center gap-2 text-sm font-bold text-sky-700">
                        <i class="fa-solid fa-heart-pulse" aria-hidden="true"></i> Observasi Umum
                    </legend>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputTgl">
                                Tanggal (digunakan bila ada transfusi) :
                            </label>
                            <input
                                id="inputTgl"
                                v-model="form.observation_date"
                                type="date"
                                class="input"
                                data-purpose="input-date"
                                @input="syncDeviceDates"
                            >
                            <p v-if="messageFor('observation_date')" class="mt-1 text-[11px] text-red-600">
                                {{ messageFor('observation_date') }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputWaktu">Waktu :</label>
                            <input
                                id="inputWaktu"
                                v-model="form.observation_time"
                                type="time"
                                class="input"
                                data-purpose="input-time"
                            >
                            <p v-if="messageFor('observation_time')" class="mt-1 text-[11px] text-red-600">
                                {{ messageFor('observation_time') }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputBB">Berat badan (Kg) :</label>
                            <input
                                id="inputBB"
                                v-model="form.weight"
                                type="number"
                                min="0"
                                max="500"
                                step="0.1"
                                class="input font-mono"
                                placeholder="Isi angka"
                                data-purpose="input-weight"
                            >
                            <p v-if="messageFor('weight')" class="mt-1 text-[11px] text-red-600">{{ messageFor('weight') }}</p>
                        </div>

                        <div class="lg:col-start-1">
                            <label class="mb-1 block font-semibold text-slate-700" for="inputKesadaran">
                                Tingkat Kesadaran :
                            </label>
                            <select
                                id="inputKesadaran"
                                v-model="form.kesadaran"
                                class="input font-medium"
                                data-purpose="input-kesadaran"
                            >
                                <option v-for="(optionValue, optionLabel) in consciousnessOptions" :key="optionLabel" :value="optionValue">
                                    {{ optionLabel }}
                                </option>
                            </select>
                            <p v-if="messageFor('kesadaran')" class="mt-1 text-[11px] text-red-600">
                                {{ messageFor('kesadaran') }}
                            </p>
                        </div>

                        <div v-show="showRass" id="rassWrapper" data-purpose="rass-wrapper">
                            <div class="mb-1 flex items-center justify-between">
                                <label class="block font-semibold text-purple-700" for="inputRASS">RASS (Skala Sedasi) :</label>
                                <span class="font-mono text-[10px] italic text-purple-500">Richmond Scale</span>
                            </div>
                            <select
                                id="inputRASS"
                                v-model="form.rass"
                                class="w-full rounded-lg border border-purple-300 bg-purple-50 text-xs font-bold text-purple-800 shadow-sm focus:border-purple-500 focus:ring-purple-500"
                                data-purpose="input-rass"
                            >
                                <option v-for="option in RASS_OPTIONS" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                            <p class="mt-1 text-[10px] text-slate-500" id="rassDescription" data-purpose="rass-description">
                                {{ rassDescription }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputGCS">GCS (ETT) :</label>
                            <input
                                id="inputGCS"
                                v-model="form.gcs"
                                type="text"
                                class="input font-mono"
                                placeholder="Contoh: E3-Vt-M5"
                                data-purpose="input-gcs"
                            >
                            <p class="field-hint">Bentuk teks untuk pasien intubasi (mis. E3-Vt-M5) atau angka 3-15.</p>
                        </div>
                    </div>
                </fieldset>
                <!-- ============ FIELD SET 2: TANDA VITAL ============ -->
                <fieldset class="border-t border-slate-200 pt-4" data-purpose="section-vitals">
                    <legend class="mb-3 flex items-center gap-2 text-sm font-bold text-sky-700">
                        <i class="fa-solid fa-stethoscope" aria-hidden="true"></i>
                        Tanda-Tanda Vital (Vitals &amp; Airway/Breathing)
                    </legend>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div id="metodeTD">
                            <label class="mb-1 block font-semibold text-slate-700">Metode Tekanan Darah :</label>
                            <div class="flex items-center gap-4 py-2">
                                <label class="inline-flex items-center text-xs">
                                    <input
                                        v-model="form.bloodPressureMethod"
                                        type="radio"
                                        value="NIBP"
                                        class="border-slate-300 text-sky-600 focus:ring-sky-500"
                                        data-purpose="input-bp-method"
                                    >
                                    <span class="ml-1.5 font-medium">NIBP</span>
                                </label>
                                <label class="inline-flex items-center text-xs">
                                    <input
                                        v-model="form.bloodPressureMethod"
                                        type="radio"
                                        value="IBP"
                                        class="border-slate-300 text-sky-600 focus:ring-sky-500"
                                        data-purpose="input-bp-method"
                                    >
                                    <span class="ml-1.5 font-medium">IBP</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputSistolik">Sistolik (mmHg) :</label>
                            <input
                                id="inputSistolik"
                                v-model="form.sys"
                                type="number"
                                min="20"
                                max="350"
                                class="input font-mono"
                                placeholder="Isi angka"
                                data-purpose="input-sys"
                            >
                            <p v-if="messageFor('sys')" class="mt-1 text-[11px] text-red-600">{{ messageFor('sys') }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputDiastolik">Diastolik (mmHg) :</label>
                            <input
                                id="inputDiastolik"
                                v-model="form.dia"
                                type="number"
                                min="5"
                                max="250"
                                class="input font-mono"
                                placeholder="Isi angka"
                                data-purpose="input-dia"
                            >
                            <p v-if="messageFor('dia')" class="mt-1 text-[11px] text-red-600">{{ messageFor('dia') }}</p>
                        </div>

                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <label class="font-bold text-sky-700" for="calculatedMAP">MAP :</label>
                                <span class="font-mono text-[10px] italic text-slate-400">Otomatis / Terhitung</span>
                            </div>
                            <input
                                id="calculatedMAP"
                                :value="calculatedMap"
                                type="text"
                                readonly
                                placeholder="-"
                                class="w-full rounded-lg border border-sky-300 bg-sky-50 font-mono text-xs font-bold text-sky-800 shadow-inner"
                                data-purpose="calculated-map"
                            >
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputHR">Nadi (x/mnt) :</label>
                            <input
                                id="inputHR"
                                v-model="form.hr"
                                type="number"
                                min="10"
                                max="300"
                                class="input font-mono"
                                placeholder="Isi angka"
                                data-purpose="input-hr"
                            >
                            <p v-if="messageFor('hr')" class="mt-1 text-[11px] text-red-600">{{ messageFor('hr') }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputRhythm">Irama Nadi :</label>
                            <select id="inputRhythm" v-model="form.rhythm" class="input" data-purpose="input-rhythm">
                                <option v-for="option in RHYTHM_OPTIONS" :key="option || 'kosong'" :value="option">
                                    {{ option === '' ? 'Pilih irama' : option }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputRR">Respirasi (x/mnt) :</label>
                            <input
                                id="inputRR"
                                v-model="form.rr"
                                type="number"
                                min="0"
                                max="100"
                                class="input font-mono"
                                placeholder="Isi angka"
                                data-purpose="input-rr"
                            >
                            <p v-if="messageFor('rr')" class="mt-1 text-[11px] text-red-600">{{ messageFor('rr') }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputBreathType">Tipe Napas :</label>
                            <select id="inputBreathType" v-model="form.breathType" class="input" data-purpose="input-breath-type">
                                <option v-for="option in BREATH_OPTIONS" :key="option || 'kosong'" :value="option">
                                    {{ option === '' ? 'Pilih tipe napas' : option }}
                                </option>
                            </select>
                        </div>

                        <div class="lg:col-start-1">
                            <label class="mb-1 block font-semibold text-slate-700" for="inputSuhu">Suhu (&deg;C) :</label>
                            <input
                                id="inputSuhu"
                                v-model="form.suhu"
                                type="number"
                                min="20"
                                max="50"
                                step="0.1"
                                class="input font-mono"
                                placeholder="Isi angka"
                                data-purpose="input-suhu"
                            >
                            <p v-if="messageFor('suhu')" class="mt-1 text-[11px] text-red-600">{{ messageFor('suhu') }}</p>
                            <p class="field-hint">35,05 / 36,05 / 38,05 / 41,05 jatuh celah band dan bernilai 0</p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputGDS">Gula Darah (mg/dL) :</label>
                            <input
                                id="inputGDS"
                                v-model="form.glucose"
                                type="number"
                                min="0"
                                max="2000"
                                class="input font-mono"
                                placeholder="Isi angka"
                                data-purpose="input-glucose"
                            >
                            <p v-if="messageFor('blood_glucose')" class="mt-1 text-[11px] text-red-600">
                                {{ messageFor('blood_glucose') }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputSpO2">SpO2 (%) :</label>
                            <input
                                id="inputSpO2"
                                v-model="form.spo2"
                                type="number"
                                min="20"
                                max="100"
                                class="input font-mono"
                                placeholder="Isi angka"
                                data-purpose="input-spo2"
                            >
                            <p v-if="messageFor('spo2')" class="mt-1 text-[11px] text-red-600">{{ messageFor('spo2') }}</p>
                        </div>
                        <div id="gangguanParu">
                            <label class="mb-1 block font-semibold text-slate-700">Gangguan Paru :</label>
                            <div class="flex items-center gap-4 py-2">
                                <label class="inline-flex items-center text-xs">
                                    <input
                                        v-model="form.respiratoryProblem"
                                        type="radio"
                                        value="Tidak"
                                        class="border-slate-300 text-sky-600 focus:ring-sky-500"
                                        data-purpose="input-lung-issue"
                                    >
                                    <span class="ml-1.5 font-medium">Tidak</span>
                                </label>
                                <label class="inline-flex items-center text-xs">
                                    <input
                                        v-model="form.respiratoryProblem"
                                        type="radio"
                                        value="Ya"
                                        class="border-slate-300 text-sky-600 focus:ring-sky-500"
                                        data-purpose="input-lung-issue"
                                    >
                                    <span class="ml-1.5 font-medium">Ya</span>
                                </label>
                            </div>
                        </div>

                        <div class="lg:col-start-1 lg:col-span-2">
                            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                                <div id="alatO2">
                                    <label class="mb-1 block font-semibold text-slate-700">Alat Bantu Oksigen :</label>
                                    <div class="flex items-center gap-4">
                                        <label class="inline-flex items-center text-xs">
                                            <input
                                                v-model="form.o2Enabled"
                                                type="radio"
                                                value="Tidak"
                                                class="border-slate-300 text-sky-600 focus:ring-sky-500"
                                                data-purpose="input-o2-enabled"
                                            >
                                            <span class="ml-1.5 font-medium">Tidak</span>
                                        </label>
                                        <label class="inline-flex items-center text-xs">
                                            <input
                                                v-model="form.o2Enabled"
                                                type="radio"
                                                value="Ya"
                                                class="border-slate-300 text-sky-600 focus:ring-sky-500"
                                                data-purpose="input-o2-enabled"
                                            >
                                            <span class="ml-1.5 font-medium">Ya</span>
                                        </label>
                                    </div>
                                </div>

                                <div v-show="showO2Support" id="o2SupportWrapper" data-purpose="o2-support-wrapper">
                                    <label class="mb-1 block font-semibold text-slate-700" for="inputO2Support">
                                        Jenis Dukungan O2 :
                                    </label>
                                    <select
                                        id="inputO2Support"
                                        v-model="form.o2Support"
                                        class="input"
                                        data-purpose="input-o2-support"
                                    >
                                        <option v-for="option in O2_SUPPORT_OPTIONS" :key="option || 'kosong'" :value="option">
                                            {{ option === '' ? 'Pilih dukungan O2' : o2SupportLabel(option) }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <!-- ============ FIELD SET 3: INPUT CAIRAN & PENGOBATAN ============ -->
                <fieldset class="border-t border-slate-200 pt-4" data-purpose="section-fluid-management">
                    <legend class="mb-3 flex items-center gap-2 text-sm font-bold text-sky-700">
                        <i class="fa-solid fa-syringe" aria-hidden="true"></i> Input Cairan &amp; Pengobatan
                    </legend>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputTransfusiType">Transfusi :</label>
                            <select
                                id="inputTransfusiType"
                                v-model="form.transfusionType"
                                class="input"
                                data-purpose="input-transfusion-type"
                            >
                                <option v-for="option in TRANSFUSION_OPTIONS" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputTransfusion">
                                Cairan Transfusi (mL) :
                            </label>
                            <input
                                id="inputTransfusion"
                                v-model="form.transfusionVolume"
                                type="number"
                                min="0"
                                class="input font-mono"
                                placeholder="Isi angka"
                                data-purpose="input-transfusion"
                            >
                            <p v-if="messageFor('transfusion_volume')" class="mt-1 text-[11px] text-red-600">
                                {{ messageFor('transfusion_volume') }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputParenteral">Parenteral (mL) :</label>
                            <input
                                id="inputParenteral"
                                v-model="form.parenteral"
                                type="number"
                                min="0"
                                class="input font-mono"
                                placeholder="Isi angka"
                                data-purpose="input-parenteral"
                            >
                            <p v-if="messageFor('parenteral')" class="mt-1 text-[11px] text-red-600">
                                {{ messageFor('parenteral') }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputEnteral">Enteral (mL) :</label>
                            <input
                                id="inputEnteral"
                                v-model="form.enteral"
                                type="number"
                                min="0"
                                class="input font-mono"
                                placeholder="Isi angka"
                                data-purpose="input-enteral"
                            >
                            <p v-if="messageFor('enteral')" class="mt-1 text-[11px] text-red-600">{{ messageFor('enteral') }}</p>
                        </div>

                        <div class="sm:col-span-3">
                            <MedicationRowsEditor
                                v-model="form.medications"
                                :categories="reference.medicationCategories || {}"
                                :disabled="form.processing"
                            />
                            <p v-if="errorFor('medications')" class="mt-1 text-[11px] text-red-600">{{ errorFor('medications') }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block font-bold text-sky-700" for="totalIntakeDisplay">Total Intake (mL) :</label>
                            <input
                                id="totalIntakeDisplay"
                                :value="`${totals.intake} mL`"
                                type="text"
                                readonly
                                class="w-full rounded-lg border border-sky-300 bg-sky-50 font-mono text-xs font-black text-sky-700 shadow-inner"
                                data-purpose="total-intake"
                            >
                        </div>
                    </div>
                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <h4 class="mb-2 flex items-center gap-1.5 text-xs font-bold text-sky-700">
                            <i class="fa-solid fa-droplet" aria-hidden="true"></i> Output Cairan
                        </h4>

                        <div class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700" for="inputUrine">Urine / BAB (mL) :</label>
                                <input
                                    id="inputUrine"
                                    v-model="form.urine"
                                    type="number"
                                    min="0"
                                    class="input font-mono"
                                    placeholder="Isi angka"
                                    data-purpose="input-urine"
                                >
                                <p v-if="messageFor('urine')" class="mt-1 text-[11px] text-red-600">{{ messageFor('urine') }}</p>
                            </div>

                            <div>
                                <label class="mb-1 block font-semibold text-slate-700" for="inputDrain">
                                    Output Drain / NGT (mL) :
                                </label>
                                <input
                                    id="inputDrain"
                                    v-model="form.drain"
                                    type="number"
                                    min="0"
                                    class="input font-mono"
                                    placeholder="Isi angka"
                                    data-purpose="input-drain"
                                >
                                <p v-if="messageFor('drain')" class="mt-1 text-[11px] text-red-600">{{ messageFor('drain') }}</p>
                            </div>

                            <div>
                                <div class="mb-1 flex items-center justify-between">
                                    <label class="font-semibold text-slate-700" for="inputIWL">IWL (mL) :</label>
                                    <span class="font-mono text-[10px] italic text-slate-400">Otomatis per jam</span>
                                </div>
                                <input
                                    id="inputIWL"
                                    :value="iwl"
                                    type="number"
                                    readonly
                                    class="w-full rounded-lg border border-slate-300 bg-slate-50 font-mono text-xs font-bold text-slate-800 shadow-inner"
                                    data-purpose="calculated-iwl"
                                >
                            </div>

                            <div>
                                <label class="mb-1 block font-bold text-amber-700" for="totalOutputDisplay">
                                    Total Output (mL) :
                                </label>
                                <input
                                    id="totalOutputDisplay"
                                    :value="`${totals.output} mL`"
                                    type="text"
                                    readonly
                                    class="w-full rounded-lg border border-amber-300 bg-amber-50 font-mono text-xs font-black text-amber-700 shadow-inner"
                                    data-purpose="total-output"
                                >
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 border-t border-slate-100 pt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <h4 class="mb-2 flex items-center gap-1.5 text-xs font-bold text-sky-800">
                            <i class="fa-solid fa-scale-unbalanced" aria-hidden="true"></i> Ringkasan Fluid Balance
                        </h4>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label class="mb-1 block font-bold text-slate-800" for="fluidBalanceCurrent">
                                    Fluid Balance (Saat Ini) :
                                </label>
                                <input
                                    id="fluidBalanceCurrent"
                                    :value="totals.balanceLabel"
                                    type="text"
                                    readonly
                                    class="w-full rounded-lg border border-slate-300 bg-white text-center font-mono text-xs font-bold"
                                    data-purpose="fluid-balance-current"
                                >
                            </div>

                            <div>
                                <label class="mb-1 block font-semibold text-slate-600" for="fluidBalance24h">
                                    {{ fluidWindow.label }} :
                                </label>
                                <input
                                    id="fluidBalance24h"
                                    :value="totals.balanceMlLabel"
                                    type="text"
                                    readonly
                                    class="w-full rounded-lg border border-slate-300 bg-white font-mono text-xs"
                                    data-purpose="fluid-balance-24h"
                                >
                            </div>

                            <div>
                                <label class="mb-1 block font-semibold text-slate-600" for="fluidIntake24h">
                                    Intake 24 jam ({{ fluidWindow.start }} - {{ fluidWindow.end }}) :
                                </label>
                                <input
                                    id="fluidIntake24h"
                                    :value="totals.intakeLabel"
                                    type="text"
                                    readonly
                                    class="w-full rounded-lg border border-slate-300 bg-white font-mono text-xs"
                                    data-purpose="fluid-intake-24h"
                                >
                            </div>

                            <div>
                                <label class="mb-1 block font-semibold text-slate-600" for="fluidOutput24h">
                                    Output 24 jam ({{ fluidWindow.start }} - {{ fluidWindow.end }}) :
                                </label>
                                <input
                                    id="fluidOutput24h"
                                    :value="totals.outputLabel"
                                    type="text"
                                    readonly
                                    class="w-full rounded-lg border border-slate-300 bg-white font-mono text-xs"
                                    data-purpose="fluid-output-24h"
                                >
                            </div>

                            <div class="sm:col-span-2">
                                <label class="mb-1 block font-semibold text-slate-700" for="fluidBalanceDuringStay">
                                    Balance Selama Dirawat :
                                </label>
                                <div class="flex items-center gap-2">
                                    <input
                                        id="fluidBalanceDuringStay"
                                        :value="totals.balanceMlLabel"
                                        type="text"
                                        readonly
                                        class="w-48 rounded-lg border border-emerald-300 bg-emerald-50 font-mono text-xs font-bold text-emerald-700"
                                        data-purpose="fluid-balance-during-stay"
                                    >
                                    <span class="font-medium text-slate-500">mL</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>
                <!-- ============ FIELD SET 4: BUNDLE HAIs & PERANGKAT ============ -->
                <fieldset class="border-t border-slate-200 pt-4" data-purpose="section-hai-bundles">
                    <legend class="mb-3 flex items-center gap-2 text-sm font-bold text-sky-700">
                        <i class="fa-solid fa-hospital-user" aria-hidden="true"></i>
                        Tindakan Medis &amp; Keperawatan (Invasive Lines &amp; Bundles Pencegahan HAIs)
                    </legend>

                    <p v-if="bundlesError" class="mb-2 text-[11px] text-red-600">{{ bundlesError }}</p>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <!-- ============ KOLOM KIRI ============ -->
                        <div class="space-y-4">
                            <div class="device-card" data-purpose="device-row-ett">
                                <label class="inline-flex items-center font-bold text-xs">
                                    <input
                                        type="checkbox"
                                        class="rounded text-sky-600 focus:ring-sky-500"
                                        :checked="deviceFor('ett').row.present"
                                        data-device="ett"
                                        @change="setDevicePresent(deviceFor('ett').row, $event.target.checked)"
                                    >
                                    <span class="ml-2">Endotracheal Tube / Tracheostomy Tube</span>
                                </label>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[11px] text-slate-500">Tgl:</span>
                                    <input
                                        id="ettStartDate"
                                        class="device-date"
                                        type="date"
                                        :value="deviceFor('ett').row.startDate"
                                        data-device-date="ett"
                                        @input="setDeviceDate(deviceFor('ett').row, $event.target.value)"
                                    >
                                </div>
                            </div>

                            <div id="vapBundleChecklist" class="bundle-box" :class="groupBorder('vap')" data-purpose="bundle-box-vap">
                                <div class="flex items-center justify-between border-b border-slate-100 pb-1 text-xs">
                                    <span class="font-extrabold tracking-wider text-slate-800">MAINTENANCE (VAP BUNDLE)</span>
                                    <span class="text-[10px] font-bold uppercase text-rose-600">* Wajib Evaluasi Tiap Shift</span>
                                </div>
                                <div
                                    v-for="item in bundleItemsByGroup.vap"
                                    :key="item.key"
                                    class="flex items-start justify-between gap-2 pt-1"
                                    :data-purpose="`bundle-item-${item.key}`"
                                >
                                    <p class="pr-2 text-[11px] text-slate-700">{{ item.label }} *</p>
                                    <div class="flex shrink-0 items-center gap-3">
                                        <label class="inline-flex items-center">
                                            <input v-model="bundles[item.key]" type="radio" value="Ya" class="text-sky-600">
                                            <span class="ml-1 text-xs font-bold">Ya</span>
                                        </label>
                                        <label class="inline-flex items-center">
                                            <input v-model="bundles[item.key]" type="radio" value="Tidak" class="text-sky-600">
                                            <span class="ml-1 text-xs">Tidak</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="device-card" data-purpose="device-row-cvc">
                                <label class="inline-flex items-center font-bold text-xs">
                                    <input
                                        type="checkbox"
                                        class="rounded text-sky-600 focus:ring-sky-500"
                                        :checked="deviceFor('cvc').row.present"
                                        data-device="cvc"
                                        @change="setDevicePresent(deviceFor('cvc').row, $event.target.checked)"
                                    >
                                    <span class="ml-2">Central Venous Catheter / CVC (Jugular)</span>
                                </label>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[11px] text-slate-500">Tgl:</span>
                                    <input
                                        id="cvcStartDate"
                                        class="device-date"
                                        type="date"
                                        :value="deviceFor('cvc').row.startDate"
                                        data-device-date="cvc"
                                        @input="setDeviceDate(deviceFor('cvc').row, $event.target.value)"
                                    >
                                </div>
                            </div>

                            <div class="device-card" data-purpose="device-row-ventTubing">
                                <label class="inline-flex items-center font-bold text-xs">
                                    <input
                                        type="checkbox"
                                        class="rounded text-sky-600 focus:ring-sky-500"
                                        :checked="deviceFor('ventTubing').row.present"
                                        data-device="ventTubing"
                                        @change="setDevicePresent(deviceFor('ventTubing').row, $event.target.checked)"
                                    >
                                    <span class="ml-2">Tubing Ventilator</span>
                                </label>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[11px] text-slate-500">Tgl:</span>
                                    <input
                                        id="ventTubingStartDate"
                                        class="device-date"
                                        type="date"
                                        :value="deviceFor('ventTubing').row.startDate"
                                        data-device-date="ventTubing"
                                        @input="setDeviceDate(deviceFor('ventTubing').row, $event.target.value)"
                                    >
                                </div>
                            </div>

                            <div class="device-card" data-purpose="device-row-ngt">
                                <label class="inline-flex items-center font-bold text-xs">
                                    <input
                                        type="checkbox"
                                        class="rounded text-sky-600 focus:ring-sky-500"
                                        :checked="deviceFor('ngt').row.present"
                                        data-device="ngt"
                                        @change="setDevicePresent(deviceFor('ngt').row, $event.target.checked)"
                                    >
                                    <span class="ml-2">Nasogastric Tube / NGT</span>
                                </label>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[11px] text-slate-500">Tgl:</span>
                                    <input
                                        id="ngtStartDate"
                                        class="device-date"
                                        type="date"
                                        :value="deviceFor('ngt').row.startDate"
                                        data-device-date="ngt"
                                        @input="setDeviceDate(deviceFor('ngt').row, $event.target.value)"
                                    >
                                </div>
                            </div>
                        </div>
                        <!-- ============ KOLOM KANAN ============ -->
                        <div class="space-y-4">
                            <div class="device-card" data-purpose="device-row-arterial">
                                <label class="inline-flex items-center font-bold text-xs">
                                    <input
                                        type="checkbox"
                                        class="rounded text-sky-600 focus:ring-sky-500"
                                        :checked="deviceFor('arterial').row.present"
                                        data-device="arterial"
                                        @change="setDevicePresent(deviceFor('arterial').row, $event.target.checked)"
                                    >
                                    <span class="ml-2">Arteri Line Catheter (Radial Dextra)</span>
                                </label>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[11px] text-slate-500">Tgl:</span>
                                    <input
                                        id="arterialStartDate"
                                        class="device-date"
                                        type="date"
                                        :value="deviceFor('arterial').row.startDate"
                                        data-device-date="arterial"
                                        @input="setDeviceDate(deviceFor('arterial').row, $event.target.value)"
                                    >
                                </div>
                            </div>

                            <div class="device-card" data-purpose="device-row-dc">
                                <label class="inline-flex items-center font-bold text-xs">
                                    <input
                                        type="checkbox"
                                        class="rounded text-sky-600 focus:ring-sky-500"
                                        :checked="deviceFor('dc').row.present"
                                        data-device="dc"
                                        @change="setDevicePresent(deviceFor('dc').row, $event.target.checked)"
                                    >
                                    <span class="ml-2">Dower Catheter (Foley No. 16)</span>
                                </label>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[11px] text-slate-500">Tgl:</span>
                                    <input
                                        id="dcStartDate"
                                        class="device-date"
                                        type="date"
                                        :value="deviceFor('dc').row.startDate"
                                        data-device-date="dc"
                                        @input="setDeviceDate(deviceFor('dc').row, $event.target.value)"
                                    >
                                </div>
                            </div>

                            <div id="cautiBundleChecklist" class="bundle-box" :class="groupBorder('cauti')" data-purpose="bundle-box-cauti">
                                <div class="flex items-center justify-between border-b border-slate-100 pb-1 text-xs">
                                    <span class="font-extrabold tracking-wider text-slate-800">MAINTENANCE (CAUTI BUNDLE)</span>
                                    <span class="text-[10px] font-bold uppercase text-rose-600">* Wajib Evaluasi Tiap Shift</span>
                                </div>
                                <div
                                    v-for="item in bundleItemsByGroup.cauti"
                                    :key="item.key"
                                    class="flex items-start justify-between gap-2 pt-1"
                                    :data-purpose="`bundle-item-${item.key}`"
                                >
                                    <p class="pr-2 text-[11px] text-slate-700">{{ item.label }} *</p>
                                    <div class="flex shrink-0 items-center gap-3">
                                        <label class="inline-flex items-center">
                                            <input v-model="bundles[item.key]" type="radio" value="Ya" class="text-sky-600">
                                            <span class="ml-1 text-xs font-bold">Ya</span>
                                        </label>
                                        <label class="inline-flex items-center">
                                            <input v-model="bundles[item.key]" type="radio" value="Tidak" class="text-sky-600">
                                            <span class="ml-1 text-xs">Tidak</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div id="clabsiBundleChecklist" class="bundle-box" :class="groupBorder('clabsi')" data-purpose="bundle-box-clabsi">
                                <div class="flex items-center justify-between border-b border-slate-100 pb-1 text-xs">
                                    <span class="font-extrabold tracking-wider text-slate-800">MAINTENANCE (CLABSI BUNDLE)</span>
                                    <span class="text-[10px] font-bold uppercase text-rose-600">* Wajib Evaluasi Tiap Shift</span>
                                </div>
                                <div
                                    v-for="item in bundleItemsByGroup.clabsi"
                                    :key="item.key"
                                    class="flex items-start justify-between gap-2 pt-1"
                                    :data-purpose="`bundle-item-${item.key}`"
                                >
                                    <p class="pr-2 text-[11px] text-slate-700">{{ item.label }} *</p>
                                    <div class="flex shrink-0 items-center gap-3">
                                        <label class="inline-flex items-center">
                                            <input v-model="bundles[item.key]" type="radio" value="Ya" class="text-sky-600">
                                            <span class="ml-1 text-xs font-bold">Ya</span>
                                        </label>
                                        <label class="inline-flex items-center">
                                            <input v-model="bundles[item.key]" type="radio" value="Tidak" class="text-sky-600">
                                            <span class="ml-1 text-xs">Tidak</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="device-card" data-purpose="device-row-infus">
                                <label class="inline-flex items-center font-bold text-xs">
                                    <input
                                        type="checkbox"
                                        class="rounded text-sky-600 focus:ring-sky-500"
                                        :checked="deviceFor('infus').row.present"
                                        data-device="infus"
                                        @change="setDevicePresent(deviceFor('infus').row, $event.target.checked)"
                                    >
                                    <span class="ml-2">Infus Perifer</span>
                                </label>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[11px] text-slate-500">Tgl:</span>
                                    <input
                                        id="infusStartDate"
                                        class="device-date"
                                        type="date"
                                        :value="deviceFor('infus').row.startDate"
                                        data-device-date="infus"
                                        @input="setDeviceDate(deviceFor('infus').row, $event.target.value)"
                                    >
                                </div>
                            </div>

                            <div class="space-y-1.5 rounded-lg border border-slate-200 bg-slate-50 p-2.5" data-purpose="device-row-lain">
                                <div class="flex items-center justify-between">
                                    <label class="inline-flex items-center font-bold text-xs">
                                        <input
                                            type="checkbox"
                                            class="rounded text-sky-600"
                                            :checked="deviceFor('lain').row.present"
                                            data-device="lain"
                                            @change="setDevicePresent(deviceFor('lain').row, $event.target.checked)"
                                        >
                                        <span class="ml-2">Tindakan Lain</span>
                                    </label>
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-[11px] text-slate-500">Tgl:</span>
                                        <input
                                            id="lainStartDate"
                                            class="device-date"
                                            type="date"
                                            :value="deviceFor('lain').row.startDate"
                                            data-device-date="lain"
                                            @input="setDeviceDate(deviceFor('lain').row, $event.target.value)"
                                        >
                                    </div>
                                </div>
                                <input
                                    id="lainNoteInput"
                                    type="text"
                                    class="w-full rounded border border-slate-300 bg-white px-2 py-1 text-[11px]"
                                    placeholder="Rawat luka ulkus dekubitus grade 1 sacrum"
                                    :value="deviceFor('lain').row.note"
                                    data-purpose="device-note"
                                    @input="setDeviceNote(deviceFor('lain').row, $event.target.value)"
                                >
                            </div>
                        </div>
                    </div>

                    <p class="mt-3 text-[10px] text-slate-500" data-purpose="bundle-summary">
                        <strong class="text-slate-800">{{ bundleCompliant }}</strong> dari
                        <strong class="text-slate-800">{{ bundleAnswered }}</strong> butir dijawab "Ya"
                        <span class="text-slate-400">({{ bundleItems.length }} butir konfigurasi, pilihan awal "Ya")</span>
                        &bull; Tanggal perangkat yang dikosongkan otomatis mengikuti tanggal observasi.
                    </p>
                </fieldset>
                <!-- ============ FIELD SET 5: VENTILASI & CATATAN ============ -->
                <fieldset class="border-t border-slate-200 pt-4" data-purpose="section-notes-and-vent">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <label class="font-semibold text-slate-700" for="inputVentilasi">Ventilasi :</label>
                                <span class="text-[10px] text-slate-400">Mode, parameter, PEEP, FiO2</span>
                            </div>
                            <textarea
                                id="inputVentilasi"
                                v-model="form.ventilator"
                                :placeholder="VENT_PLACEHOLDER"
                                rows="4"
                                class="textarea font-mono placeholder:whitespace-pre-line"
                                data-purpose="ventilator-settings"
                            ></textarea>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700" for="inputTindakanKeperawatan">
                                Tindakan Keperawatan :
                            </label>
                            <select
                                id="inputTindakanKeperawatan"
                                v-model="form.nursingAction"
                                class="input mb-2"
                                data-purpose="nursing-action"
                            >
                                <option v-for="action in NURSING_ACTIONS" :key="action" :value="action">
                                    {{ action }}
                                </option>
                            </select>

                            <div class="rounded border border-slate-200 bg-slate-50 p-2 text-[11px] text-slate-600">
                                <p class="mb-1 font-bold text-slate-700">Rencana Implementasi Lanjutan:</p>
                                <ul class="list-inside list-disc space-y-0.5">
                                    <li v-for="suggestion in PLAN_SUGGESTIONS" :key="suggestion">{{ suggestion }}</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <!-- ============ PRATINJAU SKOR EWS (aria-live) ============ -->
                <EwsLivePreview :state="preview" :max="scaleMax" @header-score="onHeaderScore" />

                <!-- ============ TOMBOL AKSI: DI DALAM FORM, SESUAI PROTOTYPE ============ -->
                <div
                    class="no-print flex items-center justify-center gap-4 border-t border-slate-200 pt-4"
                    data-purpose="form-actions"
                >
                    <button
                        type="submit"
                        id="saveObservationBtn"
                        class="inline-flex items-center gap-2 rounded-lg bg-[#0284c7] px-8 py-2.5 text-sm font-bold text-white shadow transition hover:bg-[#0369a1]"
                        data-purpose="save-observation"
                    >
                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                        {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
                    </button>
                    <button
                        id="closeModalCancelBtn"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-[#dc2626] px-8 py-2.5 text-sm font-bold text-white shadow transition hover:bg-[#b91c1c]"
                        data-purpose="close-modal-cancel"
                        @click="requestClose"
                    >
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i> Batal
                    </button>
                </div>
            </form>
        </Modal>
    </div>
</template>

<style scoped>
/* Kartu perangkat invasif: kelas yang sama persis seperti prototype. */
.device-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.625rem;
    border: 1px solid rgb(226 232 240);
    border-radius: 0.5rem;
    background-color: rgb(248 250 252);
}

.device-date {
    width: 7rem;
    padding: 0.25rem;
    border: 1px solid rgb(203 213 225);
    border-radius: 0.25rem;
    font-family: theme('fontFamily.mono');
    font-size: 0.75rem;
}

.bundle-box {
    padding: 1rem;
    border: 1px solid rgb(226 232 240);
    border-radius: 0.5rem;
    background-color: #fff;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}

.bundle-box > div + div {
    margin-top: 0.75rem;
}
</style>
