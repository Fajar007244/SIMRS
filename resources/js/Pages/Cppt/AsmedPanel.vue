<script setup>
/**
 * Cppt/AsmedPanel.vue - tab "ASMED" pada halaman CPPT.
 *
 * Port 1:1 dari phase1/cppt.html:
 *   renderAsmed()  -> mode tampilan (blok S/O/O/P + kartu diagnosis aktif)
 *   asmedForm()    -> mode formulir (enam field, tombol Batal + Simpan)
 *   emptyState()   -> kondisi belum ada ASMED
 *
 * Penyimpanan lewat POST ke AdmissionService::storeAsmed() yang UPSERT, jadi
 * "Simpan ASMED" pada episode yang sudah punya ASMED menyunting baris itu -
 * sama seperti tombol "Edit ASMED" pada prototype.
 *
 * PROPS
 *   asmed      Object|null  baris ASMED dari AdmissionService::getCpptPanels()
 *   diagnoses  Array        baris diagnosis untuk kartu "Diagnosa Aktif"
 *   canWrite   Boolean      peran boleh menulis (hanya dokter)
 *   storeUrl   String       URL POST
 *   authorName String       nama petugas yang sedang login, dipakai sebagai
 *                           nilai awal kolom "Nama Pemeriksa"
 *
 * EMITS
 *   saved  void  setelah server menerima penyimpanan
 */
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    asmed: { type: Object, default: null },
    diagnoses: { type: Array, default: () => [] },
    canWrite: { type: Boolean, default: false },
    storeUrl: { type: String, default: '' },
    authorName: { type: String, default: '' },
});

const emit = defineEmits(['saved']);

const DASH = '-';

const editing = ref(false);

function blankForm() {
    return {
        complaint: '',
        examiner: '',
        history: '',
        physical_exam: '',
        vitals: '',
        plan: '',
    };
}

const form = useForm(blankForm());

/**
 * Kunci asmeds.vitals + label/satuan Indonesia untuk ditampilkan. Urutannya
 * mengikuti urutan kolom isian pada formulir, bukan urutan kunci json.
 *
 * Nilai yang sudah membawa satuan ("92%", "118 x/mnt", "88/52 mmHg") dipakai
 * apa adanya; angka telanjang dilengkapi satuannya.
 */
const VITAL_FIELDS = [
    { key: 'tekanan_darah', label: 'Tekanan darah', unit: 'mmHg' },
    { key: 'nadi', label: 'Nadi', unit: 'x/mnt' },
    { key: 'respirasi', label: 'Respirasi', unit: 'x/mnt' },
    { key: 'suhu', label: 'Suhu', unit: '°C' },
    { key: 'saturasi', label: 'Saturasi', unit: '%' },
];

const VITAL_DUMP = /(?:^|,\s*)(ringkasan|tekanan_darah|nadi|respirasi|suhu|saturasi)\s*:\s*/i;

/**
 * Pecah "tekanan_darah: 88/52 mmHg, nadi: 118 x/mnt, ..." menjadi objek
 * kunci -> nilai. Mengembalikan null kalau teksnya bukan bentuk itu, yaitu
 * kalimat yang benar-benar diketik petugas di textarea "Tanda Vital".
 *
 * Regex dibuat ulang tiap pemanggilan supaya `lastIndex` dari flag `g` tidak
 * bocor antar pemanggilan pada kolom yang sama.
 */
function parseVitalDump(text) {
    const pattern = new RegExp(VITAL_DUMP.source, 'gi');
    const marks = [];
    let match;

    while ((match = pattern.exec(text)) !== null) {
        marks.push({
            key: match[1].toLowerCase(),
            from: match.index + match[0].length,
            at: match.index,
        });
    }

    if (marks.length === 0) return null;

    const pairs = {};

    marks.forEach((mark, index) => {
        const to = index + 1 < marks.length ? marks[index + 1].at : text.length;

        pairs[mark.key] = text.slice(mark.from, to).trim();
    });

    return pairs;
}

/** Objek / string datar / null -> teks polos tanpa label. */
function rawVitalsText(raw) {
    if (raw === null || raw === undefined) return '';

    if (typeof raw === 'object' && !Array.isArray(raw)) {
        return Object.entries(raw)
            .map(([key, value]) => `${key}: ${value === null || value === undefined ? '' : value}`)
            .join(', ')
            .trim();
    }

    const text = String(raw).trim();

    return text === DASH ? '' : text;
}

/** Suhu ditulis "38,4 °C" - koma desimal Indonesia dan tanda derajat. */
function formatTemperature(text) {
    const number = Number.parseFloat(text.replace(',', '.'));

    if (!Number.isFinite(number)) return text;

    return `${String(number).replace('.', ',')} °C`;
}

/** Lengkapi satuan yang belum ada pada angka telanjang. */
function withUnit(text, unit) {
    if (text.toLowerCase().includes(unit.toLowerCase())) return text;

    return unit === '%' ? `${text}%` : `${text} ${unit}`;
}

function formatVitalValue(key, value) {
    const text = String(value === null || value === undefined ? '' : value).trim();

    if (text === '' || text === DASH) return '';

    if (key === 'suhu') return formatTemperature(text);

    const field = VITAL_FIELDS.find((item) => item.key === key);

    return field ? withUnit(text, field.unit) : text;
}

/**
 * Ubah asmed.vitals jadi bentuk yang layak dibaca manusia.
 *
 * AdmissionService::asmedPayload() meratakan kolom json asmeds.vitals menjadi
 * "tekanan_darah: 88/52 mmHg, nadi: 118 x/mnt, ..." jadi yang sampai ke prop
 * adalah DUMP nama kunci, bukan teks yang diketik petugas. Bentuk datar itu
 * dipecah di sini supaya kunci snake_case tidak pernah tampil mentah; kalimat
 * bebas yang diketik petugas tidak berpola dan dikembalikan apa adanya.
 *
 * Mengembalikan `{ chips, text }`: mode tampilan memakai `chips` untuk
 * memecah vital jadi segmen berlabel, `text` tetap tersedia sebagai satu
 * kalimat untuk penyaringan blok dan untuk nilai bila vital tidak berlabel.
 */
function vitalsParts(raw) {
    const text = rawVitalsText(raw);

    if (text === '') return { chips: [], text: '' };

    const pairs = parseVitalDump(text);

    if (pairs === null) return { chips: [], text };

    const chips = VITAL_FIELDS
        .map((field) => ({ label: field.label, value: formatVitalValue(field.key, pairs[field.key]) }))
        .filter((chip) => chip.value !== '');

    // Tidak ada vital per-kunci yang terbaca: yang ada hanya kalimat petugas,
    // jadi kalimat itu yang ditampilkan, tanpa dilebur jadi label.
    if (chips.length === 0) {
        return { chips: [], text: pairs.ringkasan || text };
    }

    return { chips, text: chips.map((chip) => `${chip.label} ${chip.value}`).join(' · ') };
}

/**
 * Nilai awal textarea "Tanda Vital" saat menyunting.
 *
 * Yang diedit petugas adalah `ringkasan`, satu kalimat bebas seperti
 * "TD 88/52 mmHg, HR 118x/mnt, RR 24x/mnt, Suhu 38.4 C, SpO2 92%", jadi itu
 * yang dikembalikan - bentuk datar dari service dipecah lebih dulu, bentuk
 * json dibaca langsung, kalimat bebas dikembalikan apa adanya. POST berikutnya
 * karena itu menulis ulang kolom yang sama seperti sebelumnya.
 */
function vitalsSummary(raw) {
    const text = rawVitalsText(raw);

    if (text === '') return '';

    const pairs = parseVitalDump(text);

    if (pairs === null) return text;

    return pairs.ringkasan || text;
}

/**
 * Warna chip diagnosa aktif: prima disorot, penyerta netral.
 *
 * String-nya tinggal di sini supaya atribut :class di template cuma berisi satu
 * ekspresi - tidak ada lagi tanda kutip liar di ujung baris class binding.
 */
function diagnosisChipClass(item) {
    return item.isPrimary
        ? 'border-purple-300 bg-purple-100 text-purple-900'
        : 'border-slate-200 bg-slate-50 text-slate-700';
}
/** Isi form dari baris tersimpan, atau kosong saat episode belum punya ASMED. */
function seed() {
    const row = props.asmed || {};

    form.defaults({
        complaint: row.complaint || '',
        examiner: row.examiner && row.examiner !== DASH ? row.examiner : props.authorName,
        history: row.history || '',
        physical_exam: row.physicalExam || '',
        vitals: vitalsSummary(row.vitals),
        plan: row.plan || '',
    });

    form.reset();
    form.clearErrors();
}

watch(
    () => [props.asmed, props.authorName],
    () => {
        editing.value = false;
        seed();
    },
    { immediate: true },
);

function startEdit() {
    if (!props.canWrite) return;

    seed();
    editing.value = true;
}

function cancel() {
    editing.value = false;
    form.clearErrors();
}

function errorFor(field) {
    return form.errors && form.errors[field] ? String(form.errors[field]) : '';
}

function submit() {
    form.post(props.storeUrl, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
            emit('saved');
        },
    });
}

/** S digabung dengan riwayat, sama seperti renderAsmed() di prototype. */
function subjectiveText(row) {
    return [row.complaint, row.history].filter(Boolean).join(' ');
}

function blocks(row) {
    const vitals = vitalsParts(row.vitals);

    const items = [
        { tag: 'S', label: 'Keluhan & Riwayat', value: subjectiveText(row), chips: [] },
        { tag: 'O', label: 'Pemeriksaan Fisik', value: row.physicalExam, chips: [] },
        { tag: 'O', label: 'Tanda Vital', value: vitals.text, chips: vitals.chips },
        { tag: 'P', label: 'Rencana Tatalaksana', value: row.plan, chips: [] },
    ];

    return items.filter((item) => {
        const value = String(item.value || '').trim();

        return value !== '' && value !== DASH;
    });
}
</script>

<template>
    <!-- ================= FORMULIR ASMED (asmedForm) ================= -->
    <form v-if="editing" id="asmedForm" class="space-y-3" data-purpose="cppt-asmed-form" novalidate @submit.prevent="submit">
        <p class="text-[11px] font-bold uppercase text-slate-400">Formulir ASMED</p>

        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="fComplaint">Keluhan Utama</label>
                <input id="fComplaint" v-model="form.complaint" type="text" class="input" :class="{ 'input-error': errorFor('complaint') }">
                <p v-if="errorFor('complaint')" class="mt-1 text-[11px] text-red-600">{{ errorFor('complaint') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="fExaminer">Nama Pemeriksa</label>
                <input id="fExaminer" v-model="form.examiner" type="text" class="input" :class="{ 'input-error': errorFor('examiner') }">
                <p v-if="errorFor('examiner')" class="mt-1 text-[11px] text-red-600">{{ errorFor('examiner') }}</p>
            </div>

            <div class="sm:col-span-2">
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="fHistory">Riwayat Pendamping</label>
                <textarea id="fHistory" v-model="form.history" rows="3" class="textarea" :class="{ 'input-error': errorFor('history') }"></textarea>
                <p v-if="errorFor('history')" class="mt-1 text-[11px] text-red-600">{{ errorFor('history') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="fPhysical">Pemeriksaan Fisik</label>
                <textarea id="fPhysical" v-model="form.physical_exam" rows="3" class="textarea" :class="{ 'input-error': errorFor('physical_exam') }"></textarea>
                <p v-if="errorFor('physical_exam')" class="mt-1 text-[11px] text-red-600">{{ errorFor('physical_exam') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="fVitals">Tanda Vital</label>
                <textarea id="fVitals" v-model="form.vitals" rows="3" class="textarea" :class="{ 'input-error': errorFor('vitals') }"></textarea>
                <p v-if="errorFor('vitals')" class="mt-1 text-[11px] text-red-600">{{ errorFor('vitals') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="fPlan">Rencana Tatalaksana</label>
                <textarea id="fPlan" v-model="form.plan" rows="3" class="textarea" :class="{ 'input-error': errorFor('plan') }"></textarea>
                <p v-if="errorFor('plan')" class="mt-1 text-[11px] text-red-600">{{ errorFor('plan') }}</p>
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-100 pt-2">
            <button
                type="button"
                class="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold transition hover:bg-slate-50"
                data-action="cancel"
                @click="cancel"
            >
                Batal
            </button>
            <button
                type="submit"
                class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-emerald-700 disabled:opacity-60"
                data-purpose="cppt-asmed-submit"
                :disabled="form.processing"
            >
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                {{ form.processing ? 'Menyimpan...' : 'Simpan ASMED' }}
            </button>
        </div>
    </form>

    <!-- ================= KONDISI KOSONG (emptyState) ================= -->
    <EmptyState
        v-else-if="!props.asmed"
        icon="fa-regular fa-file-lines"
        title="Belum ada ASMED untuk pasien ini."
        message="Assessment awal medis diisi dokter saat episode dimulai atau lewat tombol Tulis ASMED."
    >
        <button
            v-if="props.canWrite"
            type="button"
            class="no-print mt-4 inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-emerald-700"
            data-action="new-asmed"
            @click="startEdit"
        >
            <i class="fa-solid fa-plus" aria-hidden="true"></i>
            Tulis ASMED
        </button>
    </EmptyState>

    <!-- ================= TAMPILAN (renderAsmed) ================= -->
    <div v-else class="space-y-3" data-purpose="cppt-asmed-view">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
            <div>
                <p class="font-black text-emerald-800">ASMED / SOAP</p>
                <p class="text-[11px] text-slate-500">
                    Pemeriksa: {{ props.asmed.examiner || DASH }} &bull; {{ props.asmed.examinedAtLabel || DASH }}
                </p>
            </div>
            <button
                v-if="props.canWrite"
                type="button"
                class="no-print inline-flex items-center gap-2 rounded-lg border border-emerald-600 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-50"
                data-action="edit-asmed"
                @click="startEdit"
            >
                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                Edit
            </button>
        </div>

        <div class="grid gap-3 text-sm md:grid-cols-2">
            <div
                v-for="block in blocks(props.asmed)"
                :key="block.tag + '|' + block.label"
                class="rounded-lg border-l-4 border-emerald-500 bg-emerald-50/60 px-3.5 py-2.5"
                :data-soap="block.tag"
            >
                <p class="text-xs font-black text-emerald-800">{{ block.tag }} &mdash; {{ block.label }}</p>

                <ul v-if="block.chips.length > 0" class="mt-1 flex flex-wrap gap-1.5 text-sm text-slate-700">
                    <li
                        v-for="chip in block.chips"
                        :key="chip.label"
                        class="rounded-md border border-emerald-200 bg-white px-1.5 text-slate-700"
                    ><span class="font-semibold text-slate-500">{{ chip.label }}</span> <span>{{ chip.value }}</span></li>
                </ul>

                <p v-else class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ block.value }}</p>
            </div>
        </div>

        <div>
            <p class="mb-2 text-[11px] font-bold uppercase text-slate-400">Diagnosa Aktif</p>
            <div class="flex flex-wrap gap-1.5">
                <span
                    v-for="item in props.diagnoses"
                    :key="item.id"
                    class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-semibold"
                    :class="diagnosisChipClass(item)"
                >
                    <i v-if="item.isPrimary" class="fa-solid fa-star text-[10px] text-purple-500" aria-hidden="true"></i>
                    {{ item.text }}
                    <span v-if="item.code" class="font-mono text-[10px] opacity-70">({{ item.code }})</span>
                </span>
                <span v-if="props.diagnoses.length === 0" class="text-sm text-slate-500">
                    Belum ada diagnosa yang dicatat.
                </span>
            </div>
        </div>
    </div>
</template>
