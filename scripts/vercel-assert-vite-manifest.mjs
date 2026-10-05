// Berhenti dengan pesan jelas kalau buildCommand gagal menghasilkan manifest Vite.
// Tanpa guard ini, deploy tetap "sukses" tapi setiap halaman error karena
// @vite melempar ViteManifestNotFound.
import { existsSync } from 'node:fs';

const manifest = 'public/build/manifest.json';

if (!existsSync(manifest)) {
    console.error([
        '',
        'FATAL: buildCommand tidak menghasilkan ' + manifest + '.',
        'Semua halaman akan gagal karena @vite melempar ViteManifestNotFound.',
        'Baca output Vite pada build log ini - penyebabnya biasanya',
        'gagalnya "npm ci" atau "npm run build".',
        '',
    ].join('\n'));
    process.exit(1);
}

console.log('OK: ' + manifest + ' berhasil dibuat.');