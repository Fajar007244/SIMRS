<script setup>
/**
 * Profil.vue - "Profil & Ringkasan ICU" (route `profil`).
 *
 * Port 1:1 dari phase1/profil.html, dengan tiga penyesuaian yang dictated
 * oleh kontrak front-end (docs/FRONTEND_CONTRACT.md):
 *
 * 1. phase1 memakai header pasien sendiri; di sini header PatientHeaderBanner
 *    dipasang di slot `status` ClinicalLayout (WAJIB di `status`, bukan
 *    `default`, supaya menempel di atas area konten). Isi kaya phase1 -
 *    kotak "Status risiko", "Alergi" dan "Kode status" dirender lewat header
 *    tersebut, sedangkan strip 4 kolom Diagnosis utama / penyerta / Status
 *    ventilasi / Pembiayaan dirender sebagai pita ringkasan abu-abu di
 *    section pertama badan halaman, sehingga tidak ada isi phase1 yang hilang.
 * 2. phase1 tidak punya tab modul di layout; ClinicalLayout.vue yang
 *    merender CLINICAL_TABS apa adanya.
 * 3. phase1 menulis logika derivasi di JavaScript; di sini logika itu
 *    dipindah ke blok <script setup> (computed) dengan data dari service.
 *
 * PROPS (dari ProfilController)
 *   encounterId String  encounters.encounter_id, mis. enc-159853-icu-20260906
 *   activeTab   String  'profil' -ClinicalLayout memakainya untuk menandai tab
 *   banner      Object  12 kunci AdmissionService::getBannerPayload()
 *   profile     Object  AdmissionService::getProfile()
 *   telemetry   Object  ObservationService::getTelemetry()
 *
 * EMITS: (tidak ada - halaman ini read-only)
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

import ClinicalLayout from '@/Layouts/ClinicalLayout.vue';
import PatientHeaderBanner from '@/Components/PatientHeaderBanner.vue';
import SectionCard from '@/Components/SectionCard.vue';
import StatCard from '@/Components/StatCard.vue';
import EwsBadge from '@/Components/EwsBadge.vue';
import DataTableWrap from '@/Components/DataTableWrap.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PrintButton from '@/Components/PrintButton.vue';
import ComplianceBar from '@/Components/ComplianceBar.vue';
import DeviceBadge from '@/Components/DeviceBadge.vue';

import { url } from '@/router';
import { dash, formatNumber, hasNoAllergy, numeric, signed } from '@/composables/useFormatting';
import { riskLongLabel, tone } from '@/tone';

const props = defineProps({
    encounterId: { type: String, required: true },
    activeTab: { type: String, default: 'profil' },
    banner: { type: Object, default: () => ({}) },
    profile: { type: Object, required: true },
    telemetry: { type: Object, default: () => ({}) },
});

/* --------------------------------- data --------------------------------- */

const patient = computed(() => props.profile?.patient || {});
const encounter = computed(() => props.profile?.encounter || {});
const diagnoses = computed(() => props.profile?.diagnoses || []);
const procedures = computed(() => props.profile?.procedures || []);
const asmed = computed(() => props.profile?.asmed || null);
const nursingCare = computed(() => props.profile?.nursingCare || null);
const careTeam = computed(() => props.profile?.careTeam || []);
const devices = computed(() => props.profile?.devices || []);
const completion = computed(() => props.profile?.completion || {});
const latest = computed(() => props.profile?.latestObservation || props.telemetry?.latest || null);
const deltas = computed(() => props.telemetry?.deltas || {});

const primaryDiagnosis = computed(() => diagnoses.value.find((row) => row.isPrimary) || null);
const secondaryDiagnoses = computed(() => diagnoses.value.filter((row) => !row.isPrimary));

/* ------------------------------ derivasi ------------------------------- */

const ewsTotal = computed(() => (latest.value ? latest.value.ewsTotal : null));
const ewsRisk = computed(() => (latest.value ? latest.value.ewsRisk : ''));

/** "Sedang · observasi rutin" dst, atau teks "Belum ada observasi". */
const riskStatus = computed(() => {
    if (!latest.value) return 'Belum ada observasi';

    return `${riskLongLabel(latest.value.ewsRisk)} (EWS ${latest.value.ewsTotal})`;
});

/** "Hari rawat ke-3" - fase1 memakai +1 dengan minimum 1. */
const hospitalDay = computed(() => {
    const days = Number(encounter.value.losDays);
    return Number.isFinite(days) && days > 0 ? `Hari rawat ke-${days}` : '-';
});

const mapValue = computed(() => numeric(latest.value?.map));
const fluidBalance = computed(() => numeric(latest.value?.fluidBalance));

const o2Summary = computed(() => {
    const source = latest.value;
    if (!source) return { ventilation: 'Belum ada data ventilasi', oxygen: 'Belum ada observasi' };

    const parts = [source.o2Support, source.breathType].filter(Boolean);

    return {
        ventilation: dash(source.breathType || source.o2Support || 'Tanpa keterangan'),
        oxygen: parts.length ? parts.join(' · ') : `SpO2 ${dash(source.spo2)}%`,
    };
});

const allergyText = computed(() => dash(encounter.value.allergyAlert || patient.value.allergies));
const hasAllergy = computed(() => !hasNoAllergy(patient.value.allergies) || !hasNoAllergy(encounter.value.allergyAlert));

/**
 * Prioritas perawatan - diturunkan dari observasi terakhir, bukan teks tetap.
 * Port fase1 (profil.html, blok "profilePriorities"); kelas ditulis literal
 * supaya scanner JIT Tailwind ikut memindahnya.
 */
const priorities = computed(() => {
    const source = latest.value;

    if (!source) return [];

    const items = [];
    const map = Number(source.map);
    const spo2 = Number(source.spo2);
    const balance = (Number(source.intake) || 0) - (Number(source.output) || 0);

    items.push(
        !Number.isFinite(map) || map < 65
            ? {
                  key: 'red',
                  title: 'Hemodinamik',
                  body: `MAP ${Number.isFinite(map) ? `${map} mmHg` : '-'} (<65). Evaluasi kebutuhan vasopressor dan resusitasi cairan.`,
              }
            : {
                  key: 'emerald',
                  title: 'Hemodinamik',
                  body: `MAP ${map} mmHg - target tercapai, pertahankan.`,
              },
    );

    if (source.breathType || source.o2Support) {
        items.push({
            key: 'sky',
            title: 'Ventilasi',
            body: `${dash(source.breathType || source.o2Support)} · SpO2 ${dash(source.spo2)}%. Tinjau bundle VAP.`,
        });
    } else if (Number.isFinite(spo2) && spo2 < 94) {
        items.push({
            key: 'sky',
            title: 'Ventilasi',
            body: `SpO2 ${spo2}% (<94%) tanpa catatan support oksigen. Pertimbangkan oksigen tambahan.`,
        });
    } else {
        items.push({ key: 'sky', title: 'Ventilasi', body: 'Tidak ada catatan gangguan napas.' });
    }

    if (balance !== 0) {
        items.push({
            key: balance > 0 ? 'amber' : 'red',
            title: 'Cairan & ginjal',
            body: `Balance ${balance > 0 ? '+' : ''}${balance} mL. Pantau intake/output dan evaluasi fungsi ginjal.`,
        });
    } else {
        items.push({
            key: 'amber',
            title: 'Cairan & ginjal',
            body: 'Balance 0 mL pada entri terakhir. Pastikan catatan I/O lengkap.',
        });
    }

    return items;
});

/** Kelas tone per prioritas - literal, bukan template, agar JIT memindai. */
const PRIORITY_TONE = {
    red: 'border-l-red-500 bg-red-50',
    amber: 'border-l-amber-500 bg-amber-50',
    sky: 'border-l-sky-500 bg-sky-50',
    emerald: 'border-l-emerald-500 bg-emerald-50',
};

const PRIORITY_TEXT = {
    red: 'text-red-900',
    amber: 'text-amber-900',
    sky: 'text-sky-900',
    emerald: 'text-emerald-900',
};

const PRIORITY_BODY = {
    red: 'text-red-800',
    amber: 'text-amber-800',
    sky: 'text-sky-800',
    emerald: 'text-emerald-800',
};

/** Tone kartu ringkasan episode - cermin StatCard (aksen kiri, ikon, warna nilai). */
const TONE_DIAGNOSIS_UTAMA = tone('rose');
const TONE_DIAGNOSIS_PENYERTA = tone('amber');
const TONE_VENTILASI = tone('sky');
const TONE_PEMBIAYAAN = tone('emerald');

/** Empat indikator kelengkapan admisi (Encounter::getCompletionAttribute). */
const completionItems = computed(() => [
    { key: 'asmed', label: 'ASMED', done: completion.value.asmed === true },
    { key: 'nursingCare', label: 'Asuhan Keperawatan', done: completion.value.nursingCare === true },
    { key: 'diagnosis', label: 'Diagnosis', done: completion.value.diagnosis === true },
    { key: 'procedure', label: 'Prosedur', done: completion.value.procedure === true },
]);

/** "Akses cepat" fase1, URL-nya dibangun dari router.js (satu sumber URL). */
const QUICK_LINKS = [
    { key: 'observasi', label: 'Observasi EWS & hemodinamik', icon: 'fa-chart-line', tone: 'text-sky-600', border: 'border-slate-200 hover:border-sky-300 hover:bg-sky-50' },
    { key: 'cppt', label: 'CPPT dan dokumentasi medis', icon: 'fa-user-doctor', tone: 'text-emerald-600', border: 'border-slate-200 hover:border-emerald-300 hover:bg-emerald-50' },
    { key: 'penunjang', label: 'Laboratorium dan AGD', icon: 'fa-flask-vial', tone: 'text-purple-600', border: 'border-slate-200 hover:border-purple-300 hover:bg-purple-50' },
    { key: 'farmasi', label: 'Farmasi dan drip aktif', icon: 'fa-pills', tone: 'text-amber-600', border: 'border-slate-200 hover:border-amber-300 hover:bg-amber-50' },
    { key: 'bundles', label: 'Bundle pencegahan HAIs', icon: 'fa-shield-virus', tone: 'text-teal-600', border: 'border-slate-200 hover:border-teal-300 hover:bg-teal-50' },
];

const quickLinks = computed(() =>
    QUICK_LINKS.map((item) => ({ ...item, href: url(item.key, { encounter: props.encounterId }) })),
);

const observasiUrl = computed(() => url('observasi', { encounter: props.encounterId }));

/** Baris "terakhir" untuk tabel observasi - satu kolom per tanda vital. */
const vitalCells = computed(() => {
    const source = latest.value;
    if (!source) return [];

    return [
        { label: 'TD / MAP', value: `${dash(source.bpLabel)} / ${dash(source.map)} mmHg` },
        { label: 'Nadi', value: `${dash(source.hr)} /menit`, hint: deltaHint('hr', ' /menit') },
        { label: 'Respirasi', value: `${dash(source.rr)} /menit`, hint: deltaHint('rr', ' /menit') },
        { label: 'Suhu', value: `${formatNumber(source.suhu, 1)} °C`, hint: deltaHint('suhu', ' °C') },
        { label: 'SpO2', value: `${dash(source.spo2)} %`, hint: deltaHint('spo2', ' %') },
        { label: 'Kesadaran', value: dash(source.kesadaran), hint: source.gcs ? `GCS ${source.gcs}` : '' },
        { label: 'Support O2', value: dash(source.o2Support) },
        { label: 'Ritme', value: dash(source.rhythm) },
        { label: 'Intake', value: `${formatNumber(source.intake, 0)} mL` },
        { label: 'Output', value: `${formatNumber(source.output, 0)} mL` },
        { label: 'Balance', value: `${signed(source.fluidBalance, 0)} mL` },
        { label: 'Obat', value: `${formatNumber(source.medicationCount, 0)} administer` },
    ];
});

/** " (+2)" / " (-3)" - delta observasi terakhir vs sebelumnya. */
function deltaHint(key, suffix = '') {
    const delta = Number(deltas.value?.[key]);
    if (!Number.isFinite(delta) || delta === 0) return '';

    return ` (${delta > 0 ? '+' : ''}${delta}${suffix})`;
}

/** DeviceBadge hanya bisa menerima label ringkas; buat singkatan dari kode. */
function deviceShortLabel(row) {
    const code = String(row.code || '').toUpperCase();
    if (code) return code;

    return String(row.label || '-').split('/')[0].trim() || '-';
}
</script>

<template>
    <Head title="Profil & Ringkasan ICU" />

    <ClinicalLayout
        title="Profil & Ringkasan ICU"
        subtitle="SIMRS EMR v4.8 - Modul Monitoring Kritis Terpadu"
        active-tab="profil"
        :encounter-id="encounterId"
    >
        <template #status>
            <PatientHeaderBanner
                :patient="banner"
                status-label="Status Episode"
                status-tone="sky"
                alert-note=""
            >
                <template #status>
                    <div class="text-xs font-bold text-sky-100" data-module-status>
                        {{ dash(encounter.statusLabel) }}
                    </div>
                    <div class="mt-0.5 text-[10px] text-sky-300">
                        {{ hospitalDay }} &middot; DPJP: {{ dash(encounter.dpjp) }}
                    </div>
                    <div class="mt-0.5 text-[10px] text-sky-300">DNR: Tidak</div>
                </template>
                <template #actions>
                    <PrintButton />
                </template>
            </PatientHeaderBanner>
        </template>

        <div class="grid gap-4 lg:grid-cols-12">
            <!-- ============================ KOLOM UTAMA ============================ -->
            <div class="space-y-4 lg:col-span-8">

                <!-- ===== 1. Ringkasan episode & diagnosa (strip 4 kolom phase1) ===== -->
                <SectionCard
                    title="Ringkasan Episode & Diagnosa"
                    icon="fa-id-card"
                    tone="sky"
                >
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-purpose="profil-summary-cards">
                        <div
                            class="rounded-xl border border-l-4 border-slate-200 bg-white p-3.5 shadow-sm"
                            :class="TONE_DIAGNOSIS_UTAMA.accent"
                            data-purpose="profil-summary-card"
                        >
                            <div class="mb-1 flex items-center justify-between gap-2 text-slate-500">
                                <span class="truncate text-[10px] font-bold uppercase tracking-wider">Diagnosis utama</span>
                                <i class="shrink-0" :class="['fa-solid fa-stethoscope', TONE_DIAGNOSIS_UTAMA.icon]" aria-hidden="true"></i>
                            </div>
                            <div class="break-words text-sm font-bold leading-snug" :class="TONE_DIAGNOSIS_UTAMA.value">
                                {{ primaryDiagnosis ? dash(primaryDiagnosis.text) : 'Belum diisi' }}
                            </div>
                            <div class="mt-0.5 text-[10px] text-slate-400">
                                {{ primaryDiagnosis && primaryDiagnosis.code ? `Kode ${primaryDiagnosis.code}` : 'Belum ada kode ICD-10' }}
                            </div>
                        </div>
                        <div
                            class="rounded-xl border border-l-4 border-slate-200 bg-white p-3.5 shadow-sm"
                            :class="TONE_DIAGNOSIS_PENYERTA.accent"
                            data-purpose="profil-summary-card"
                        >
                            <div class="mb-1 flex items-center justify-between gap-2 text-slate-500">
                                <span class="truncate text-[10px] font-bold uppercase tracking-wider">Diagnosis penyerta</span>
                                <i class="shrink-0" :class="['fa-solid fa-layer-group', TONE_DIAGNOSIS_PENYERTA.icon]" aria-hidden="true"></i>
                            </div>
                            <div class="break-words text-sm font-bold leading-snug" :class="TONE_DIAGNOSIS_PENYERTA.value">
                                {{ secondaryDiagnoses.length
                                    ? secondaryDiagnoses.map((row) => dash(row.text)).join(', ')
                                    : 'Tidak ada diagnosis penyerta' }}
                            </div>
                            <div class="mt-0.5 text-[10px] text-slate-400">
                                {{ secondaryDiagnoses.length ? `${secondaryDiagnoses.length} diagnosis penyerta tercatat` : '-' }}
                            </div>
                        </div>
                        <div
                            class="rounded-xl border border-l-4 border-slate-200 bg-white p-3.5 shadow-sm"
                            :class="TONE_VENTILASI.accent"
                            data-purpose="profil-summary-card"
                        >
                            <div class="mb-1 flex items-center justify-between gap-2 text-slate-500">
                                <span class="truncate text-[10px] font-bold uppercase tracking-wider">Status ventilasi</span>
                                <i class="shrink-0" :class="['fa-solid fa-wind', TONE_VENTILASI.icon]" aria-hidden="true"></i>
                            </div>
                            <div class="break-words text-sm font-bold leading-snug" :class="TONE_VENTILASI.value">
                                {{ o2Summary.ventilation }}
                            </div>
                            <div class="mt-0.5 text-[10px] text-slate-400">
                                {{ o2Summary.oxygen }}
                            </div>
                        </div>
                        <div
                            class="rounded-xl border border-l-4 border-slate-200 bg-white p-3.5 shadow-sm"
                            :class="TONE_PEMBIAYAAN.accent"
                            data-purpose="profil-summary-card"
                        >
                            <div class="mb-1 flex items-center justify-between gap-2 text-slate-500">
                                <span class="truncate text-[10px] font-bold uppercase tracking-wider">Pembiayaan</span>
                                <i class="shrink-0" :class="['fa-solid fa-file-invoice-dollar', TONE_PEMBIAYAAN.icon]" aria-hidden="true"></i>
                            </div>
                            <div class="break-words text-sm font-bold leading-snug" :class="TONE_PEMBIAYAAN.value">
                                {{ dash(patient.paymentLabel) }}
                            </div>
                            <div class="mt-0.5 text-[10px] text-slate-400">
                                {{ patient.bloodType && patient.bloodType !== '-' ? `Golongan darah ${patient.bloodType}` : 'Golongan darah belum dicatat' }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                            Kelengkapan data admisi
                        </span>
                        <span
                            v-for="item in completionItems"
                            :key="item.key"
                            class="inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-bold"
                            :class="item.done
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                : 'border-slate-200 bg-white text-slate-400'"
                        >
                            <i class="fa-solid" :class="item.done ? 'fa-circle-check' : 'fa-circle-xmark'" aria-hidden="true"></i>
                            {{ item.label }}
                        </span>
                    </div>
                </SectionCard>

                <!-- ===== 2. Ringkasan kondisi terkini (4 StatCard phase1) ===== -->
                <SectionCard
                    title="Ringkasan Kondisi Terkini"
                    subtitle="Terisi dari observasi tersimpan pada episode ini."
                    icon="fa-heart-pulse"
                    tone="emerald"
                >
                    <template #actions>
                        <a :href="observasiUrl" class="btn btn-primary btn-sm no-print">
                            <i class="fa-solid fa-chart-line" aria-hidden="true"></i>Buka observasi
                        </a>
                    </template>

                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <StatCard
                            label="Observasi tersimpan"
                            :value="formatNumber(profile.observationCount, 0)"
                            sub="pada episode ini"
                            icon="fa-solid fa-clipboard-list"
                            tone="sky"
                            mono
                        />
                        <StatCard
                            label="EWS terakhir"
                            :value="latest ? ewsTotal : '-'"
                            :sub="latest ? dash(latest.recordedAtLabel) : 'Belum ada data'"
                            :detail="riskStatus"
                            icon="fa-solid fa-heart-pulse"
                            tone="emerald"
                            mono
                        />
                        <StatCard
                            label="MAP terakhir"
                            :value="mapValue === null ? '-' : `${mapValue} mmHg`"
                            sub="target &gt;65 mmHg"
                            icon="fa-solid fa-heart"
                            :tone="mapValue !== null && mapValue < 65 ? 'red' : 'teal'"
                            mono
                        />
                        <StatCard
                            label="Balance terakhir"
                            :value="fluidBalance === null ? '-' : `${signed(latest.fluidBalance, 0)} mL`"
                            sub="per entri observasi"
                            icon="fa-solid fa-droplet"
                            tone="sky"
                            mono
                        />
                    </div>

                    <p class="mt-3 flex items-start gap-1.5 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2.5 text-xs text-slate-600">
                        <i
                            class="fa-solid fa-circle-info mt-0.5 shrink-0"
                            :class="latest ? 'text-emerald-600' : 'text-sky-600'"
                            aria-hidden="true"
                        ></i>
                        <span v-if="latest">
                            Observasi terakhir {{ dash(latest.recordedAtLabel) }} oleh {{ dash(latest.recordedBy) }}.
                        </span>
                        <span v-else>Belum ada observasi pada episode ini.</span>
                    </p>
                </SectionCard>

                <!-- ===== 3. Prioritas perawatan hari ini (phase1) ===== -->
                <SectionCard
                    title="Prioritas Perawatan Hari Ini"
                    subtitle="Fokus yang perlu dipastikan dalam handover dan ronde berikutnya."
                    icon="fa-solid fa-list-check"
                    tone="amber"
                >
                    <template #actions>
                        <span class="chip bg-amber-100 text-amber-800">Review per shift</span>
                    </template>

                    <div v-if="priorities.length" class="grid gap-3 sm:grid-cols-3">
                        <div
                            v-for="item in priorities"
                            :key="item.title"
                            class="border-l-4 px-3 py-3"
                            :class="[PRIORITY_TONE[item.key], PRIORITY_TEXT[item.key]]"
                        >
                            <strong class="block text-xs">{{ item.title }}</strong>
                            <span class="mt-1 block text-[11px]" :class="PRIORITY_BODY[item.key]">{{ item.body }}</span>
                        </div>
                    </div>
                    <EmptyState
                        v-else
                        icon="fa-clipboard-list"
                        title="Prioritas perawatan belum dapat dihitung"
                        message="Prioritas muncul otomatis setelah observasi pertama tercatat pada episode ini."
                    />
                </SectionCard>
                <!-- ===== 4. Diagnosa episode (utama vs penyerta, ICD-10) ===== -->
                <SectionCard
                    title="Diagnosis Episode"
                    subtitle="Diagnosis utama dan penyerta beserta kode ICD-10."
                    icon="fa-solid fa-stethoscope"
                    tone="sky"
                >
                    <template #footer>
                        <span>{{ diagnoses.length }} diagnosis tercatat</span>
                    </template>

                    <DataTableWrap v-if="diagnoses.length" :sticky="false" table-class="table table-compact">
                        <thead>
                            <tr>
                                <th class="th">Tipe</th>
                                <th class="th">Diagnosis</th>
                                <th class="th">Kode ICD-10</th>
                                <th class="th">Oleh</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in diagnoses" :key="row.id" class="row-hover">
                                <td class="td">
                                    <span
                                        class="chip"
                                        :class="row.isPrimary
                                            ? 'bg-sky-100 text-sky-700'
                                            : 'bg-slate-100 text-slate-600'"
                                    >{{ dash(row.typeLabel) }}</span>
                                </td>
                                <td class="td">
                                    <span class="font-semibold text-slate-800">{{ dash(row.text) }}</span>
                                </td>
                                <td class="td mono text-slate-600">{{ dash(row.code) }}</td>
                                <td class="td text-slate-600">{{ dash(row.author) }}</td>
                            </tr>
                        </tbody>
                    </DataTableWrap>
                    <EmptyState
                        v-else
                        icon="fa-stethoscope"
                        title="Belum ada diagnosis"
                        message="Diagnosis diisi oleh dokter pada modul Medis &amp; CPPT."
                    />
                </SectionCard>

                <!-- ===== 5. Prosedur / tindakan (ICD-9) ===== -->
                <SectionCard
                    title="Prosedur &amp; Tindakan"
                    subtitle="Tindakan yang dilakukan pada episode ini beserta kode ICD-9-CM."
                    icon="fa-solid fa-scalpel"
                    tone="purple"
                >
                    <template #footer>
                        <span>{{ procedures.length }} prosedur tercatat</span>
                        <span v-if="latest" class="mono">Bundle observasi terakhir: {{ formatNumber(latest.bundlePercent, 0) }}%</span>
                    </template>

                    <DataTableWrap v-if="procedures.length" :sticky="false" table-class="table table-compact">
                        <thead>
                            <tr>
                                <th class="th">Nama tindakan</th>
                                <th class="th">Kode ICD-9</th>
                                <th class="th">Tanggal</th>
                                <th class="th">Operator</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in procedures" :key="row.id" class="row-hover">
                                <td class="td font-semibold text-slate-800">{{ dash(row.name) }}</td>
                                <td class="td mono text-slate-600">{{ dash(row.code) }}</td>
                                <td class="td text-slate-600">{{ dash(row.performedAtLabel) }}</td>
                                <td class="td text-slate-600">{{ dash(row.operator) }}</td>
                            </tr>
                        </tbody>
                    </DataTableWrap>
                    <EmptyState
                        v-else
                        icon="fa-scalpel"
                        title="Belum ada prosedur"
                        message="Prosedur / tindakan diisi oleh dokter pada modul Medis &amp; CPPT."
                    />
                </SectionCard>

                <!-- ===== 6. ASMED ===== -->
                <SectionCard
                    title="ASMED"
                    subtitle="Anamnesis, pemeriksaan fisik, dan rencana medis."
                    icon="fa-solid fa-file-medical"
                    tone="hospital"
                >
                    <template #actions>
                        <span
                            class="badge"
                            :class="asmed ? 'border-emerald-200 bg-emerald-100 text-emerald-800' : 'border-slate-200 bg-slate-100 text-slate-500'"
                        >{{ asmed ? 'Sudah diisi' : 'Belum diisi' }}</span>
                    </template>

                    <div v-if="asmed" class="grid gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <span class="block text-slate-500">Keluhan utama</span>
                            <p class="mt-1 text-slate-800">{{ dash(asmed.complaint) }}</p>
                        </div>
                        <div>
                            <span class="block text-slate-500">Riwayat penyakit</span>
                            <p class="mt-1 leading-relaxed text-slate-800">{{ dash(asmed.history) }}</p>
                        </div>
                        <div>
                            <span class="block text-slate-500">Pemeriksaan fisik</span>
                            <p class="mt-1 leading-relaxed text-slate-800">{{ dash(asmed.physicalExam) }}</p>
                        </div>
                        <div>
                            <span class="block text-slate-500">Vital awal</span>
                            <p class="mono mt-1 text-slate-800">{{ dash(asmed.vitals) }}</p>
                        </div>
                        <div>
                            <span class="block text-slate-500">Rencana / instruksi medis</span>
                            <p class="mt-1 leading-relaxed text-slate-800">{{ dash(asmed.plan) }}</p>
                        </div>
                        <div class="md:col-span-2 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 text-[11px] text-slate-500">
                            <span class="font-semibold text-slate-800">{{ dash(asmed.examiner) }}</span>
                            <span>diperiksa {{ dash(asmed.examinedAtLabel) }}</span>
                            <span v-if="asmed.hasPlan" class="chip bg-emerald-100 text-emerald-700">Rencana tersedia</span>
                        </div>
                    </div>
                    <EmptyState
                        v-else
                        icon="fa-file-medical"
                        title="ASMED belum diisi"
                        message="Pemeriksaan medis awal diisi dokter di modul Medis &amp; CPPT."
                    />
                </SectionCard>

                <!-- ===== 7. Asuhan keperawatan / bidan ===== -->
                <SectionCard
                    title="Asuhan Keperawatan &amp; Bidan"
                    subtitle="Pengkajian, masalah, dan intervensi terakhir."
                    icon="fa-solid fa-hand-holding-heart"
                    tone="emerald"
                >
                    <template #actions>
                        <span
                            class="badge"
                            :class="nursingCare ? 'border-emerald-200 bg-emerald-100 text-emerald-800' : 'border-slate-200 bg-slate-100 text-slate-500'"
                        >{{ nursingCare ? 'Sudah diisi' : 'Belum diisi' }}</span>
                    </template>

                    <div v-if="nursingCare" class="grid gap-4 md:grid-cols-3">
                        <div>
                            <span class="block text-slate-500">Pengkajian</span>
                            <p class="mt-1 leading-relaxed text-slate-800">{{ dash(nursingCare.assessment) }}</p>
                        </div>
                        <div>
                            <span class="block text-slate-500">Masalah / diagnosa keperawatan</span>
                            <p class="mt-1 leading-relaxed text-slate-800">{{ dash(nursingCare.problems) }}</p>
                        </div>
                        <div>
                            <span class="block text-slate-500">Intervensi / rencana</span>
                            <p class="mt-1 leading-relaxed text-slate-800">{{ dash(nursingCare.interventions) }}</p>
                        </div>
                        <div class="md:col-span-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 text-[11px] text-slate-500">
                            <span class="font-semibold text-slate-800">{{ dash(nursingCare.nurse) }}</span>
                            <span class="chip bg-slate-100 text-slate-600">{{ dash(nursingCare.shiftLabel) }}</span>
                            <span>dicatat {{ dash(nursingCare.recordedAtLabel) }}</span>
                        </div>
                    </div>
                    <EmptyState
                        v-else
                        icon="fa-hand-holding-heart"
                        title="Asuhan keperawatan belum diisi"
                        message="Pengkajian keperawatan diisi perawat atau bidan pada modul ini."
                    />
                </SectionCard>
                <!-- ===== 8. Perangkat invasif & kepatuhan bundle ===== -->
                <SectionCard
                    title="Perangkat Invasive"
                    subtitle="Katalog 8 perangkat beserta lama pemakaian dan status review."
                    icon="fa-solid fa-bars-stethoscope"
                    tone="teal"
                >
                    <template #footer>
                        <span>{{ devices.filter((row) => row.isActive).length }} perangkat aktif</span>
                        <span class="mono">{{ devices.filter((row) => row.needsReview).length }} perlu review</span>
                    </template>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <div
                            v-for="row in devices"
                            :key="row.code"
                            class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 px-3 py-2"
                        >
                            <span class="min-w-0 flex-1 truncate text-xs text-slate-700" :title="dash(row.label)">
                                {{ dash(row.label) }}
                            </span>
                            <DeviceBadge
                                :label="deviceShortLabel(row)"
                                :days="row.days"
                                :needs-review="row.needsReview"
                            />
                        </div>
                    </div>

                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <p class="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                            Kepatuhan bundle pada observasi terakhir
                        </p>
                        <ComplianceBar
                            v-if="latest"
                            :percent="Number(latest.bundlePercent) || 0"
                            label-text="Bundle"
                            :height="10"
                        />
                        <p v-else class="field-hint">Belum ada observasi, kepatuhan bundle belum dapat dihitung.</p>
                    </div>
                </SectionCard>

                <!-- ===== 9. Observasi terakhir (telemetry) ===== -->
                <SectionCard
                    title="Observasi Terakhir"
                    subtitle="Satu baris flowsheet beserta delta terhadap observasi sebelumnya."
                    icon="fa-solid fa-chart-line"
                    tone="yellow"
                >
                    <template #actions>
                        <a :href="observasiUrl" class="btn btn-secondary btn-sm no-print">
                            <i class="fa-solid fa-list-ul" aria-hidden="true"></i>Lihat semua
                        </a>
                    </template>

                    <template #footer>
                        <span>Total observasi: {{ formatNumber(profile.observationCount, 0) }}</span>
                        <span>Berisiko (high + emergency): {{ formatNumber(telemetry.atRiskCount, 0) }}</span>
                    </template>

                    <div v-if="latest" class="space-y-3">
                        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                            <EwsBadge :total="ewsTotal" :risk="ewsRisk" size="lg">
                                <template #suffix>/18</template>
                            </EwsBadge>
                            <span class="text-xs text-slate-700">{{ riskStatus }}</span>
                            <span class="mono text-[11px] text-slate-500">{{ dash(latest.recordedBy) }}</span>
                        </div>

                        <DataTableWrap :sticky="false" table-class="table table-compact">
                            <thead>
                                <tr>
                                    <th class="th">Parameter</th>
                                    <th class="th">Nilai</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="cell in vitalCells" :key="cell.label" class="row-hover">
                                    <td class="th">{{ cell.label }}</td>
                                    <td class="td">
                                        <span class="mono font-semibold text-slate-800">{{ cell.value }}</span>
                                        <span v-if="cell.hint" class="text-[10px] text-slate-500">{{ cell.hint }}</span>
                                    </td>
                                </tr>
                                <tr v-if="latest.notes" class="row-hover">
                                    <td class="th">Catatan</td>
                                    <td class="td text-slate-700">{{ dash(latest.notes) }}</td>
                                </tr>
                            </tbody>
                        </DataTableWrap>
                    </div>
                    <EmptyState
                        v-else
                        icon="fa-chart-line"
                        title="Belum ada observasi"
                        message="Ringkasan tanda vital dan skor EWS muncul setelah observasi pertama tersimpan."
                    />
                </SectionCard>
            </div>

            <!-- ============================== SIDEBAR ============================== -->
            <aside class="space-y-4 lg:col-span-4">

                <!-- ===== 10. Rekap data ===== -->
                <SectionCard title="Rekap Data Episode" icon="fa-solid fa-chart-simple" tone="sky">
                    <div class="grid grid-cols-3 gap-2">
                        <StatCard
                            label="Observasi"
                            :value="formatNumber(profile.observationCount, 0)"
                            icon="fa-solid fa-clipboard-list"
                            tone="sky"
                        />
                        <StatCard
                            label="Pemberian"
                            :value="formatNumber(profile.medicationCount, 0)"
                            icon="fa-solid fa-pills"
                            tone="amber"
                        />
                        <StatCard
                            label="Catatan"
                            :value="formatNumber(profile.medicalNoteCount, 0)"
                            icon="fa-solid fa-file-lines"
                            tone="emerald"
                        />
                    </div>
                </SectionCard>

                <!-- ===== 11. Akses cepat (phase1 aside) ===== -->
                <SectionCard title="Akses Cepat" icon="fa-solid fa-arrow-up-right-dots" tone="sky">
                    <template #actions>
                        <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Encounter</span>
                    </template>

                    <div class="space-y-2">
                        <a
                            v-for="link in quickLinks"
                            :key="link.key"
                            :href="link.href"
                            class="flex items-center justify-between rounded-lg border px-3 py-2.5 text-xs font-semibold text-slate-700"
                            :class="link.border"
                        >
                            <span>
                                <i class="fa-solid mr-2 w-4" :class="[link.icon, link.tone]" aria-hidden="true"></i>{{ link.label }}
                            </span>
                            <i class="fa-solid fa-chevron-right text-[10px] text-slate-400" aria-hidden="true"></i>
                        </a>
                    </div>
                </SectionCard>
                <!-- ===== 12. Identitas pasien ===== -->
                <SectionCard title="Identitas Pasien" icon="fa-solid fa-address-card" tone="slate">
                    <dl class="space-y-3 text-xs">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Nama</dt>
                            <dd class="text-right font-semibold uppercase text-slate-900">{{ dash(patient.name) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">No. RM</dt>
                            <dd class="mono text-right font-semibold text-slate-800">{{ dash(patient.mrn) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Patient ID</dt>
                            <dd class="mono text-right text-slate-700">{{ dash(patient.patientId) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Demografi</dt>
                            <dd class="text-right text-slate-800">{{ dash(patient.demographics) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Tanggal lahir</dt>
                            <dd class="text-right text-slate-800">{{ dash(patient.birthDateLabel) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Golongan darah</dt>
                            <dd class="text-right text-slate-800">{{ dash(patient.bloodType) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Pembiayaan</dt>
                            <dd class="text-right text-slate-800">{{ dash(patient.paymentLabel) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Telepon</dt>
                            <dd class="mono text-right text-slate-700">{{ dash(patient.phone) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Alamat</dt>
                            <dd class="text-right leading-relaxed text-slate-700">{{ dash(patient.address) }}</dd>
                        </div>
                    </dl>
                </SectionCard>

                <!-- ===== 13. Episode perawatan ===== -->
                <SectionCard title="Episode Perawatan" icon="fa-solid fa-location-dot" tone="sky">
                    <dl class="space-y-3 text-xs">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Unit / Bed</dt>
                            <dd class="text-right font-semibold text-slate-800">{{ dash(encounter.unitBed) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Tanggal masuk</dt>
                            <dd class="text-right text-slate-800">{{ dash(encounter.admittedAtLabel) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Lama dirawat</dt>
                            <dd class="text-right text-slate-800">{{ hospitalDay }} ({{ formatNumber(encounter.losDays, 0) }} hari)</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Tanggal keluar</dt>
                            <dd class="text-right text-slate-800">{{ encounter.dischargedAt ? dash(encounter.dischargedAt) : 'Masih dirawat' }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Status</dt>
                            <dd class="text-right">
                                <span class="chip bg-emerald-100 text-emerald-700">{{ dash(encounter.statusLabel) }}</span>
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">DPJP</dt>
                            <dd class="text-right text-slate-800">{{ dash(encounter.attendingPhysician) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-slate-500">Encounter ID</dt>
                            <dd class="mono break-all text-right text-slate-600">{{ dash(encounter.encounterId) }}</dd>
                        </div>
                    </dl>
                </SectionCard>

                <!-- ===== 14. Alergi (merah bila ada) ===== -->
                <SectionCard title="Alergi &amp; Keamanan Obat" icon="fa-solid fa-triangle-exclamation" tone="red">
                    <div
                        class="rounded-lg border px-3 py-2.5 text-xs"
                        :class="hasAllergy
                            ? 'border-red-200 bg-red-50 text-red-800'
                            : 'border-emerald-200 bg-emerald-50 text-emerald-800'"
                    >
                        <strong class="block text-[10px] font-bold uppercase tracking-wider">Alergi pasien</strong>
                        <span class="mt-1 block font-semibold">{{ allergyText }}</span>
                    </div>
                    <p class="field-hint">
                        Verifikasi identitas dan riwayat alergi sebelum pemberian obat.
                    </p>
                </SectionCard>

                <!-- ===== 15. Tim perawatan (phase1 aside) ===== -->
                <SectionCard title="Tim Perawatan" icon="fa-solid fa-people-group" tone="slate">
                    <dl class="space-y-3 text-xs">
                        <div
                            v-for="member in careTeam"
                            :key="member.key"
                            class="flex items-start justify-between gap-3"
                        >
                            <dt class="shrink-0 text-slate-500">{{ dash(member.label) }}</dt>
                            <dd class="text-right font-semibold text-slate-800">{{ dash(member.name) }}</dd>
                        </div>
                    </dl>
                </SectionCard>

                <!-- ===== 16. Catatan keselamatan (phase1 aside) ===== -->
                <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900 no-print">
                    <div class="flex gap-2">
                        <i class="fa-solid fa-triangle-exclamation mt-0.5 text-amber-600" aria-hidden="true"></i>
                        <div>
                            <strong class="block">Catatan keselamatan</strong>
                            <p class="mt-1 leading-relaxed">
                                Pastikan identitas pasien diverifikasi sebelum dokumentasi klinis.
                            </p>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </ClinicalLayout>
</template>