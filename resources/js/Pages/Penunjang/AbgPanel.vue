<script setup>
/**
 * AbgPanel.vue - tab AGD: tabel riwayat + formulir `#abgForm`.
 *
 * Port 1:1 dari phase1/penunjang.html:
 *   renderAbgTable() -> tabel (Waktu, pH, PaCO2, PaO2, HCO3, BE, SaO2, FiO2,
 *                         Metode, Analisis) + kolom hapus
 *   abgForm()        -> formulir tambah hasil AGD baru, termasuk tombol
 *                       "Reset data contoh" (data-action="reset-support")
 *
 * Validasi: SupportService::saveAbg() melempar ValidationException bila nilai
 * di luar rentang wajar (pH 5-9, PaCO2 10-150, dst). RONA-RONA rentang yang
 * sama dipasang sebagai atribut min/max pada input DAN sebagai aturan
 * validasi server, sehingga kesalahan ketahuan sebagai pesan inline berbahasa
 * Indonesia, bukan sebagai galat 500.
 *
 * EMITS
 *   reset  void  tombol reset diklik; halaman membuka Modal konfirmasi
 */
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import DataTableWrap from '@/Components/DataTableWrap.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { formatNumber, isBlank } from '@/composables/useFormatting';
import { DASH, analyzeAbg, valueToneClass } from './abg';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    search: { type: String, default: '' },
    storeUrl: { type: String, required: true },
    deleteUrl: { type: String, required: true },
});

const emit = defineEmits(['reset']);

const METHODS = ['Arteri', 'Vena', 'Kapiler'];

const FIELDS = [
    { key: 'ph', label: 'pH', placeholder: '7.35 - 7.45', step: '0.01' },
    { key: 'pco2', label: 'PaCO2 (mmHg)', placeholder: '35 - 45', step: '1' },
    { key: 'po2', label: 'PaO2 (mmHg)', placeholder: '80 - 100', step: '1' },
    { key: 'hco3', label: 'HCO3 (mEq/L)', placeholder: '22 - 26', step: '0.1' },
    { key: 'be', label: 'BE', placeholder: '-2 - +2', step: '0.1' },
    { key: 'sao2', label: 'SaO2 (%)', placeholder: '95 - 100', step: '1' },
    { key: 'fio2', label: 'FiO2 (%)', placeholder: '21 - 100', step: '1' },
];

function today() {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

function nowTime() {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

const form = useForm({
    ph: '',
    pco2: '',
    po2: '',
    hco3: '',
    be: '',
    sao2: '',
    fio2: '',
    method: 'Arteri',
    date: today(),
    time: nowTime(),
    by: '',
});

const deleteForm = useForm({ id: '' });
const deleting = ref(false);

const defaults = () => ({
    ph: '', pco2: '', po2: '', hco3: '', be: '', sao2: '', fio2: '',
    method: 'Arteri', date: today(), time: nowTime(), by: '',
});

const visibleRows = computed(() => {
    const term = (props.search || '').trim().toLowerCase();

    if (term === '') return props.rows;

    return props.rows.filter((row) =>
        [row.measuredAtLabel, row.method, row.by]
            .some((field) => String(field ?? '').toLowerCase().includes(term)),
    );
});

function number(value) {
    if (isBlank(value)) return DASH;

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : DASH;
}

function submit() {
    form.post(props.storeUrl, {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(defaults());
            form.reset();
        },
    });
}

function destroy(row) {
    deleting.value = true;
    deleteForm.id = row.id;

    deleteForm.delete(props.deleteUrl, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            deleteForm.id = '';
        },
    });
}
</script>

<template>
    <div data-purpose="support-abg">
        <EmptyState
            v-if="rows.length === 0"
            icon="fa-lungs"
            title="Belum ada hasil Analisa Gas Darah"
            message="Belum ada hasil AGD untuk episode ini. Gunakan formulir di bawah untuk menambah hasil."
        />

        <EmptyState
            v-else-if="visibleRows.length === 0"
            icon="fa-magnifying-glass"
            title="Tidak ada hasil yang cocok"
            message="Ubah kata kunci pencarian pada baris alat di atas."
        />

        <DataTableWrap v-else :sticky="false" table-class="table table-compact row-hover">
            <thead>
                <tr>
                    <th class="th">Waktu</th>
                    <th class="th text-center">pH</th>
                    <th class="th text-center">PaCO2</th>
                    <th class="th text-center">PaO2</th>
                    <th class="th text-center">HCO3</th>
                    <th class="th text-center">BE</th>
                    <th class="th text-center">SaO2</th>
                    <th class="th text-center">FiO2</th>
                    <th class="th">Metode</th>
                    <th class="th">Analisis</th>
                    <th class="th no-print"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in visibleRows" :key="row.id" data-purpose="support-abg-row">
                    <td class="td font-mono text-[11px] text-slate-600">{{ row.measuredAtLabel }}</td>
                    <td class="td text-center font-mono font-bold" :class="valueToneClass(row, 'ph')">{{ number(row.ph) }}</td>
                    <td class="td text-center font-mono font-bold" :class="valueToneClass(row, 'pco2')">{{ number(row.pco2) }}</td>
                    <td class="td text-center font-mono font-bold" :class="valueToneClass(row, 'po2')">{{ number(row.po2) }}</td>
                    <td class="td text-center font-mono font-bold" :class="valueToneClass(row, 'hco3')">{{ number(row.hco3) }}</td>
                    <td class="td text-center font-mono font-bold" :class="valueToneClass(row, 'be')">{{ number(row.be) }}</td>
                    <td class="td text-center font-mono font-bold" :class="valueToneClass(row, 'sao2')">
                        {{ number(row.sao2) }}<span v-if="!isBlank(row.sao2)">%</span>
                    </td>
                    <td class="td text-center font-mono text-slate-500">
                        {{ number(row.fio2) }}<span v-if="!isBlank(row.fio2)">%</span>
                    </td>
                    <td class="td text-[11px] text-slate-500">{{ row.method || DASH }}</td>
                    <td class="td">
                        <span class="text-[10px] font-bold" :class="analyzeAbg(row).tone">{{ analyzeAbg(row).label }}</span>
                        <span v-if="row.abnormal" class="mt-0.5 block text-[10px] font-bold text-red-700">
                            {{ row.flags.map((flag) => flag.flagLabel).join(', ') }}
                        </span>
                    </td>
                    <td class="td text-right no-print">
                        <button
                            type="button"
                            class="btn btn-ghost btn-sm text-red-600"
                            :disabled="deleting"
                            :aria-label="`Hapus hasil AGD ${row.measuredAtLabel}`"
                            @click="destroy(row)"
                        >
                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
            </tbody>
        </DataTableWrap>

        <!-- phase1 abgForm() -->
        <form
            id="abgForm"
            class="mt-4 rounded-xl border border-teal-200 bg-teal-50/50 p-4 no-print"
            data-purpose="support-abg-form"
            @submit.prevent="submit"
        >
            <p class="mb-3 text-xs font-black uppercase tracking-wide text-teal-800">
                <i class="fa-solid fa-plus mr-1.5" aria-hidden="true"></i>Tambah Hasil AGD Baru
            </p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div v-for="field in FIELDS" :key="field.key">
                    <label class="label text-teal-700" :for="`abg-${field.key}`">{{ field.label }}</label>
                    <input
                        :id="`abg-${field.key}`"
                        v-model="form[field.key]"
                        type="number"
                        :step="field.step"
                        inputmode="decimal"
                        class="input"
                        :class="form.errors[field.key] ? 'input-error' : ''"
                        :placeholder="field.placeholder"
                    >
                    <p v-if="form.errors[field.key]" class="mt-1 text-[11px] text-red-600" :data-purpose="`form-error-${field.key}`">
                        {{ form.errors[field.key] }}
                    </p>
                </div>

                <div>
                    <label class="label text-teal-700" for="abg-method">Metode</label>
                    <select id="abg-method" v-model="form.method" class="select">
                        <option v-for="method in METHODS" :key="method" :value="method">{{ method }}</option>
                    </select>
                    <p v-if="form.errors.method" class="mt-1 text-[11px] text-red-600">{{ form.errors.method }}</p>
                </div>

                <div>
                    <label class="label text-teal-700" for="abg-date">Tanggal</label>
                    <input id="abg-date" v-model="form.date" type="date" class="input">
                    <p v-if="form.errors.date" class="mt-1 text-[11px] text-red-600">{{ form.errors.date }}</p>
                </div>

                <div>
                    <label class="label text-teal-700" for="abg-time">Jam</label>
                    <input id="abg-time" v-model="form.time" type="time" class="input">
                    <p v-if="form.errors.time" class="mt-1 text-[11px] text-red-600">{{ form.errors.time }}</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="label text-teal-700" for="abg-by">Dicek oleh</label>
                    <input id="abg-by" v-model="form.by" type="text" class="input" placeholder="Nama petugas / laboratorium">
                    <p v-if="form.errors.by" class="mt-1 text-[11px] text-red-600">{{ form.errors.by }}</p>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-[10px] text-teal-700">Interpretasi dihitung otomatis dari pH / PaCO2 / HCO3.</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" @click="emit('reset')">
                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset data AGD
                    </button>
                    <button type="submit" class="btn btn-success btn-sm" :disabled="form.processing">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        {{ form.processing ? 'Menyimpan...' : 'Simpan AGD' }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</template>