<script setup>
/**
 * PatientHeaderBanner.vue - banner identitas pasien, bagian paling atas tiap
 * halaman klinis.
 *
 * Port 1:1 dari blok data-purpose="patient-banner" yang IDENTIK di keenam
 * halaman phase1 (cppt / penunjang / farmasi / observasi / bundles), plus
 * bagian bawah profil.html yang lebih kaya.
 *
 * DATA: `patient` harus berisi PERSIS 12 kunci hasil
 * AdmissionService::getBannerPayload() (lihat docblock service itu):
 *   name, initials, demographics, mrn, payment, unitBed, admission, dpjp,
 *   allergies, diagnoses, latestObsTimestamp, latestObsRecordedBy
 * Kunci yang hilang / null aman: komponen merender '-'.
 *
 * PROPS
 *   moduleStatus  String       default ''    teks status modul di kotak kanan
 *                                  (mis. "82% - 9/11 butir" atau "Belum ada data").
 *                                  Slot `status` selalu menang bila diisi.
 *   statusLabel   String       default 'Status Modul'  judul kotak status
 *   statusTone    String       default 'teal'  slate|sky|emerald|teal|amber|
 *                                             orange|red|rose|purple|indigo|
 *                                             yellow|hospital
 *   alertNote     String       default 'DNR: Tidak - Fall Risk: Tinggi'
 *                                  baris kecil di bawah blok alergi

 * SLOTS
 *   status    override isi kotak status (menang atas `moduleStatus`)
 *   actions   tombol di baris abu-abu kanan (mis. Edit ASMED / Cetak)
 *   default   sisipan di baris abu-abu setelah blok diagnosa

 * EMITS : (tidak ada)

 * DEGRADASI: patient = null atau {} -> banner tetap tampil dengan "-".
 * Teks "Alergi" diberi gaya merah hanya bila isinya bukan "tidak ada".
 */
import { computed } from 'vue';
import { tone } from '../tone';
import { hasNoAllergy, isBlank } from '../composables/useFormatting';

const props = defineProps({
    patient: { type: Object, default: null },
    moduleStatus: { type: String, default: '' },
    statusLabel: { type: String, default: 'Status Modul' },
    statusTone: {
        type: String,
        default: 'teal',
        validator: (value) => [
            'slate', 'sky', 'emerald', 'teal', 'amber', 'orange', 'red',
            'rose', 'purple', 'indigo', 'yellow', 'hospital',
        ].includes(value),
    },
    alertNote: { type: String, default: 'DNR: Tidak • Fall Risk: Tinggi' },
});

const DASH = '-';

const p = computed(() => (props.patient && typeof props.patient === 'object' ? props.patient : {}));

/** null / '' / '-' -> '-' (identik ClinicalFormat::dash). */
function dash(value) {
    return isBlank(value) ? DASH : String(value).trim();
}

const initials = computed(() => dash(p.value.initials));
const name = computed(() => dash(p.value.name));
const demographics = computed(() => dash(p.value.demographics));
const mrn = computed(() => dash(p.value.mrn));
const payment = computed(() => dash(p.value.payment));
const unitBed = computed(() => dash(p.value.unitBed));
const admission = computed(() => dash(p.value.admission));
const dpjp = computed(() => dash(p.value.dpjp));
const diagnoses = computed(() => dash(p.value.diagnoses));
const latestObsTimestamp = computed(() => dash(p.value.latestObsTimestamp));
const latestObsRecordedBy = computed(() => dash(p.value.latestObsRecordedBy));

const allergies = computed(() => dash(p.value.allergies));
const allergyIsBlank = computed(() => hasNoAllergy(p.value.allergies));

/** Kotak status disembunyikan kalau tidak ada teks dan tidak ada slot. */
const showStatusBox = computed(() => Boolean((props.moduleStatus || '').trim()));

const statusToneClass = computed(() => tone(props.statusTone));
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm print-border" data-purpose="patient-banner">
        <div class="flex flex-col items-start justify-between gap-4 bg-gradient-to-r from-sky-900 via-slate-800 to-slate-900 p-4 text-white xl:flex-row xl:items-center">
            <!-- Bio summary -->
            <div class="flex min-w-0 items-center gap-3.5">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border-2 border-sky-400 bg-white/10 p-2 text-center font-bold text-white">
                    <span class="text-xl leading-none" data-patient="initials">{{ initials }}</span>
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-black uppercase tracking-tight text-white" data-patient="name">{{ name }}</h1>
                        <span class="rounded border border-blue-400/30 bg-blue-600/30 px-2 py-0.5 font-mono text-xs text-blue-200" data-patient="demographics">
                            {{ demographics }}
                        </span>
                        <span class="rounded bg-slate-700 px-2.5 py-0.5 font-mono text-xs font-bold tracking-wider text-slate-200" data-patient="mrn">
                            No. RM: {{ mrn }}
                        </span>
                        <span class="rounded border border-emerald-500/30 bg-emerald-600/20 px-2 py-0.5 text-[11px] font-bold text-emerald-300" data-patient="payment">
                            {{ payment }}
                        </span>
                    </div>
                    <p class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-300">
                        <span><strong class="font-normal text-slate-400">Lokasi:</strong> <span data-patient="unitBed">{{ unitBed }}</span></span>
                        <span><strong class="font-normal text-slate-400">Tgl Masuk:</strong> <span data-patient="admission">{{ admission }}</span></span>
                        <span><strong class="font-normal text-slate-400">DPJP:</strong> <span data-patient="dpjp">{{ dpjp }}</span></span>
                    </p>
                </div>
            </div>

            <!-- Status modul + alergi -->
            <div class="flex flex-wrap items-center gap-2">
                <div
                    v-if="showStatusBox || $slots.status"
                    class="rounded-lg border px-3 py-1.5"
                    :class="[statusToneClass.deep, statusToneClass.deepBorder]"
                >
                    <div class="text-[10px] font-semibold uppercase" :class="statusToneClass.deepLabel">
                        {{ props.statusLabel }}
                    </div>
                    <slot name="status">
                        <div class="text-xs font-bold" :class="statusToneClass.deepValue" data-module-status>
                            {{ props.moduleStatus || DASH }}
                        </div>
                    </slot>
                </div>

                <div class="rounded-lg border border-amber-500/50 bg-amber-950/50 px-3 py-1.5 text-xs text-amber-200">
                    <span
                        class="flex items-center gap-1.5 font-bold"
                        :class="allergyIsBlank ? '' : 'text-red-300'"
                        data-patient="allergies"
                    >
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                        {{ allergyIsBlank ? 'Alergi: ' : 'Alergi: ' }}{{ allergies }}
                    </span>
                    <span v-if="props.alertNote" class="block text-[10px] text-amber-300">{{ props.alertNote }}</span>
                </div>
            </div>
        </div>

        <!-- Bar diagnostik abu-abu -->
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 px-4 py-2 text-xs text-slate-700">
            <div class="flex flex-wrap items-center gap-3">
                <span class="font-bold text-slate-900">
                    <i class="fa-solid fa-stethoscope mr-1 text-sky-600" aria-hidden="true"></i> Diagnosa Medis:
                </span>
                <span class="text-slate-600" data-patient="diagnoses">{{ diagnoses }}</span>

                <div class="h-3.5 w-px bg-slate-300"></div>

                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Update Terakhir</span>
                <span class="font-semibold text-slate-900" data-patient="latestObsTimestamp">{{ latestObsTimestamp }}</span>
                <span class="text-slate-500">oleh</span>
                <span class="font-semibold text-slate-900" data-patient="latestObsRecordedBy">{{ latestObsRecordedBy }}</span>

                <slot></slot>
            </div>

            <div v-if="$slots.actions" class="flex items-center gap-2 no-print">
                <slot name="actions"></slot>
            </div>
        </div>
    </section>
</template>
