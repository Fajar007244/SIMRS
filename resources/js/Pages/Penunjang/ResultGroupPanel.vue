<script setup>
/**
 * ResultGroupPanel.vue - panel tab lab / blood / micro / rad.
 *
 * Port 1:1 dari dua fungsi phase1/penunjang.html:
 *   renderResultTable() -> tabel hasil (lab & blood)
 *   renderRad()         -> kartu temuan (rad)
 *   labForm()           -> formulir `data-labform="<group>"` untuk menambah hasil
 *
 * Bentuk baris untuk keempat kelompok sama-sama SupportRow hasil
 * SupportService::getGroup(), jadi satu komponen ini melayani semuanya; rad
 * memakai varian kartu karena prototype menampilkannya sebagai kartu temuan.
 *
 * ATURAN NILAI (WAJIB, sama dengan SupportService::saveSupportResult()):
 *   lab / blood -> WAJIB angka, pesan "Nilai harus berupa angka."
 *   micro / rad  -> teks bebas, pesan "Nilai wajib diisi."
 * Kolom input `type` dan validasi controller keduanya memakai aturan ini.
 *
 * EMITS
 *   save  void  tombol "Simpan Hasil" ditekan (form sudah tervalidasi klien)
 */
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import DataTableWrap from '@/Components/DataTableWrap.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { isBlank } from '@/composables/useFormatting';
import { DASH, FLAG_STYLES, isPendingMicro } from './abg';

const props = defineProps({
    group: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    search: { type: String, default: '' },
    storeUrl: { type: String, required: true },
    deleteUrl: { type: String, required: true },
});

const emit = defineEmits(['save']);

const GROUP_LABELS = {
    lab: 'Laboratorium',
    blood: 'Darah Lengkap',
    micro: 'Mikrobiologi',
    rad: 'Radiologi',
};

const NUMERIC_GROUPS = ['lab', 'blood'];
const isNumeric = computed(() => NUMERIC_GROUPS.includes(props.group));
const isCards = computed(() => props.group === 'rad');

const groupLabel = computed(() => GROUP_LABELS[props.group] || 'Penunjang');

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
    group: props.group,
    key: '',
    value: '',
    date: today(),
    time: nowTime(),
    by: '',
});

const deleting = ref(false);

const defaults = () => ({ group: props.group, key: '', value: '', date: today(), time: nowTime(), by: '' });

const deleteForm = useForm({ group: props.group, key: '' });

/** Baris yang lolos pencarian klien. */
const visibleRows = computed(() => {
    const term = (props.search || '').trim().toLowerCase();

    if (term === '') return props.rows;

    return props.rows.filter((row) =>
        [row.label, row.key, row.value, row.unit, row.reference, row.by]
            .some((field) => String(field ?? '').toLowerCase().includes(term)),
    );
});

/** Dikelompokkan per tanggal pengambilan, tanggal terbaru lebih dulu. */
const groups = computed(() => {
    const byDate = new Map();

    for (const row of visibleRows.value) {
        const date = String(row.resultedAt ?? '').slice(0, 10);

        if (!byDate.has(date)) byDate.set(date, []);
        byDate.get(date).push(row);
    }

    return [...byDate.entries()]
        .sort((a, b) => (a[0] < b[0] ? 1 : -1))
        .map(([date, rows]) => ({
            date,
            label: date === '' || !/^\d{4}-\d{2}-\d{2}$/.test(date)
                ? DASH
                : `${date.slice(8, 10)}/${date.slice(5, 7)}/${date.slice(0, 4)}`,
            rows: [...rows].sort((a, b) => String(b.resultedAt ?? '').localeCompare(String(a.resultedAt ?? ''))),
        }));
});

function rowStyle(row) {
    if (props.group === 'micro' && isPendingMicro(row)) return FLAG_STYLES.low;

    return FLAG_STYLES[row.flag] || FLAG_STYLES.none;
}

function rowText(row) {
    if (props.group === 'micro' && isPendingMicro(row)) return 'Pending';
    if (isBlank(row.flagLabel)) return 'Normal';

    return row.flagLabel;
}

function timeOf(row) {
    return String(row.resultedAt ?? '').slice(11, 16) || DASH;
}

function submit() {
    form.post(props.storeUrl, {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(defaults());
            form.reset();
            emit('save');
        },
    });
}

function destroy(row) {
    deleting.value = true;
    deleteForm.key = row.key;

    deleteForm.delete(props.deleteUrl, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            deleteForm.key = '';
        },
    });
}
</script>

<template>
    <div :data-group="group" data-purpose="support-result-group">
        <EmptyState
            v-if="rows.length === 0"
            :icon="group === 'rad' ? 'fa-x-ray' : 'fa-flask'"
            :title="`Belum ada hasil ${groupLabel}`"
            message="Belum ada hasil tercatat untuk kelompok ini. Gunakan formulir di bawah untuk menambah hasil."
        />

        <EmptyState
            v-else-if="visibleRows.length === 0"
            icon="fa-magnifying-glass"
            title="Tidak ada hasil yang cocok"
            message="Ubah kata kunci pencarian pada baris alat di atas."
        />

        <template v-else-if="isCards">
            <!-- phase1 renderRad(): kartu temuan, bukan tabel -->
            <div class="space-y-3">
                <div
                    v-for="row in visibleRows"
                    :key="row.key"
                    class="rounded-r-lg border-l-4 border-purple-500 bg-purple-50/50 px-4 py-3"
                    data-purpose="support-rad-card"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-black text-purple-900">{{ row.label }}</p>
                        <span class="font-mono text-[10px] text-slate-500">{{ row.resultedAtLabel }}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-700">{{ isBlank(row.value) ? DASH : row.value }}</p>
                    <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                        <span class="text-[10px] text-slate-500">Oleh: {{ row.by || DASH }}</span>
                        <button
                            type="button"
                            class="btn btn-ghost btn-sm text-red-600 no-print"
                            :disabled="deleting"
                            @click="destroy(row)"
                        >
                            <i class="fa-solid fa-trash" aria-hidden="true"></i> Hapus
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else>
            <!-- phase1 renderResultTable(): kelompok baris per tanggal -->
            <DataTableWrap :sticky="false" table-class="table table-compact row-hover">
                <thead>
                    <tr>
                        <th class="th">Waktu</th>
                        <th class="th">Item</th>
                        <th class="th text-right">Nilai</th>
                        <th class="th text-center">Rentang</th>
                        <th class="th text-center">Status</th>
                        <th class="th">Oleh</th>
                        <th class="th no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="groupEntry in groups" :key="groupEntry.date">
                        <tr class="bg-slate-100/70">
                            <td colspan="7" class="td font-bold text-slate-700">{{ groupEntry.label }}</td>
                        </tr>
                        <tr v-for="row in groupEntry.rows" :key="row.key + row.resultedAt" data-purpose="support-result-row">
                            <td class="td font-mono text-[11px] text-slate-500">{{ timeOf(row) }}</td>
                            <td class="td">
                                <span class="font-semibold text-slate-800">{{ row.label }}</span>
                                <span class="block text-[10px] text-slate-400">{{ row.key }}</span>
                            </td>
                            <td class="td text-right font-mono font-bold text-slate-900">
                                {{ row.value }}
                                <span v-if="!isBlank(row.unit)" class="text-[10px] font-medium text-slate-400">{{ row.unit }}</span>
                            </td>
                            <td class="td text-center font-mono text-[10px] text-slate-500">
                                <span :class="isBlank(row.reference) ? 'text-slate-300' : ''">{{ row.reference }}</span>
                            </td>
                            <td class="td text-center">
                                <span class="inline-block rounded border px-1.5 py-0.5 text-[10px] font-bold" :class="rowStyle(row)">
                                    {{ rowText(row) }}
                                </span>
                            </td>
                            <td class="td text-[11px] text-slate-600">{{ row.by || DASH }}</td>
                            <td class="td text-right no-print">
                                <button
                                    type="button"
                                    class="btn btn-ghost btn-sm text-red-600"
                                    :disabled="deleting"
                                    :aria-label="`Hapus hasil ${row.label}`"
                                    @click="destroy(row)"
                                >
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </DataTableWrap>
        </template>

        <!-- phase1 labForm(): satu formulir tambah hasil per kelompok -->
        <form
            :data-labform="group"
            class="mt-4 rounded-xl border border-sky-200 bg-sky-50/50 p-4 no-print"
            data-purpose="support-result-form"
            @submit.prevent="submit"
        >
            <p class="mb-3 text-xs font-black uppercase tracking-wide text-sky-800">
                <i class="fa-solid fa-plus mr-1.5" aria-hidden="true"></i>Tambah Hasil {{ groupLabel }}
            </p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="sm:col-span-2">
                    <label class="label text-sky-700" for="support-item">Item</label>
                    <select
                        id="support-item"
                        v-model="form.key"
                        class="select"
                        :class="form.errors.key ? 'input-error' : ''"
                    >
                        <option v-for="item in items" :key="item.key" :value="item.key">
                            {{ item.name }}{{ isBlank(item.panel) || item.panel === '-' ? '' : ' (' + item.panel + ')' }}
                        </option>
                    </select>
                    <p v-if="form.errors.key" class="mt-1 text-[11px] text-red-600" data-purpose="form-error-key">{{ form.errors.key }}</p>
                </div>

                <div>
                    <label class="label text-sky-700" for="support-value">Nilai</label>
                    <input
                        id="support-value"
                        v-model="form.value"
                        :type="isNumeric ? 'number' : 'text'"
                        :step="isNumeric ? 'any' : undefined"
                        :inputmode="isNumeric ? 'decimal' : undefined"
                        class="input"
                        :class="form.errors.value ? 'input-error' : ''"
                        :placeholder="isNumeric ? '0' : 'Contoh: Escherichia coli > 10^5 CFU/mL'"
                    >
                    <p v-if="form.errors.value" class="mt-1 text-[11px] text-red-600" data-purpose="form-error-value">{{ form.errors.value }}</p>
                    <p v-else-if="isNumeric" class="mt-1 text-[11px] text-slate-500">Wajib diisi angka.</p>
                </div>

                <div>
                    <label class="label text-sky-700" for="support-date">Tanggal</label>
                    <input id="support-date" v-model="form.date" type="date" class="input">
                    <p v-if="form.errors.date" class="mt-1 text-[11px] text-red-600">{{ form.errors.date }}</p>
                </div>

                <div>
                    <label class="label text-sky-700" for="support-time">Jam</label>
                    <input id="support-time" v-model="form.time" type="time" class="input">
                    <p v-if="form.errors.time" class="mt-1 text-[11px] text-red-600">{{ form.errors.time }}</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="label text-sky-700" for="support-by">Dicek oleh</label>
                    <input
                        id="support-by"
                        v-model="form.by"
                        type="text"
                        class="input"
                        placeholder="Nama petugas / laboratorium"
                    >
                    <p v-if="form.errors.by" class="mt-1 text-[11px] text-red-600">{{ form.errors.by }}</p>
                </div>
            </div>

            <p v-if="form.errors.group" class="mt-2 text-[11px] text-red-600">{{ form.errors.group }}</p>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-[10px] text-sky-700">
                    {{ isNumeric ? 'Nilai harus berupa angka.' : 'Hasil kultur / temuan radiologi boleh berupa teks.' }}
                </p>
                <button type="submit" class="btn btn-primary btn-sm" :disabled="form.processing">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    {{ form.processing ? 'Menyimpan...' : 'Simpan Hasil' }}
                </button>
            </div>
        </form>
    </div>
</template>