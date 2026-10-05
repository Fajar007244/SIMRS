{{--
    Skrip vanilla untuk modal "Tambah Pasien".

    Dipisah dari markup supaya partials/add-patient-modal.blade.php tetap bisa
    dibaca sebagai prototype. Port dari blok <script> phase1/pasien.html
    baris 229-553 dengan tiga perubahan yang disengaja:

      - dialog alert -> banner in-app #admissionErrorBanner (teks sama).
      - dialog confirm -> kotak konfirmasi in-app #duplicateConfirm (teks
        sama). Field tersembunyi confirm_duplicate ikut dikirim saat pengguna
        menekan tombolnya, dan PasienController::guardDuplicateMrn() tetap
        menolak permintaan yang tidak mengirimnya.
      - penyimpanan localStorage -> POST ke route "pasien.store". Baris yang
        kosong dibuang sebelum submit, sama seperti collectDiagnoses() /
        collectProcedures() di prototype.

    Tidak ada dialog browser sama sekali: alert/prompt/confirm nihil di file
    ini maupun di partial lain milik fitur ini.
--}}
@push('scripts')
<script>
    (function () {
        'use strict';

        var modal = document.getElementById('addPatientModal');

        if (! modal) {
            return;
        }

        var TABS = ['identitas', 'asmed', 'keperawatan', 'diagnosa', 'procedure'];

        /* Teks sama persis dengan dialog alert pada savePatient() prototype,
           dan dipakai lagi sebagai pesan diagnoses.required di server. */
        var REQUIRED_MESSAGE = 'Lengkapi data wajib: nama, No. RM, unit, bed, tanggal & jam masuk, dan minimal 1 diagnosa.';

        var form = document.getElementById('addPatientForm');
        var openButton = document.getElementById('openAddPatientBtn');
        var cancelButton = document.getElementById('cancelModalBtn');
        var closeTargets = modal.querySelectorAll('[data-close-modal]');
        var tabButtons = modal.querySelectorAll('[data-tab]');
        var panels = modal.querySelectorAll('[data-panel]');
        var prevButton = document.getElementById('prevStepBtn');
        var nextButton = document.getElementById('nextStepBtn');
        var saveButton = document.getElementById('savePatientBtn');
        var addDiagnosisButton = document.getElementById('addDiagnosaBtn');
        var addProcedureButton = document.getElementById('addProcedureBtn');
        var diagnosisRows = document.getElementById('diagnosaRows');
        var procedureRows = document.getElementById('procedureRows');
        var diagnosisTemplate = document.getElementById('diagnosisRowTemplate');
        var procedureTemplate = document.getElementById('procedureRowTemplate');
        var errorBanner = document.getElementById('admissionErrorBanner');
        var errorText = document.getElementById('admissionErrorText');
        var errorDismiss = modal.querySelectorAll('[data-close-error]');
        var confirmBox = document.getElementById('duplicateConfirm');
        var confirmText = document.getElementById('duplicateConfirmText');
        var confirmYes = document.getElementById('duplicateConfirmYes');
        var confirmNo = document.getElementById('duplicateConfirmNo');
        var confirmInput = document.getElementById('confirmDuplicateInput');
        var tabInput = document.getElementById('activeTabInput');
        var mrnField = document.getElementById('fMrn');

        var knownPatients = [];
        var knownPatientsNode = document.getElementById('knownPatientsData');

        if (knownPatientsNode) {
            try {
                knownPatients = JSON.parse(knownPatientsNode.textContent) || [];
            } catch (error) {
                knownPatients = [];
            }
        }

        /* Indeks baris berikutnya. Baris yang dirender server memakai indeks
           nyata (0..n-1) sehingga skrip melanjutkan dari n. */
        var diagnosisIndex = diagnosisRows ? diagnosisRows.querySelectorAll('[data-row]').length : 0;
        var procedureIndex = procedureRows ? procedureRows.querySelectorAll('[data-row]').length : 0;
        var currentTab = 0;
        var lastFocused = null;

        function value(id) {
            var element = document.getElementById(id);

            return element ? element.value : '';
        }

        function showError(message) {
            if (! errorBanner || ! errorText) {
                return;
            }

            errorText.textContent = message;
            errorBanner.classList.remove('hidden');
        }

        function clearError() {
            if (! errorBanner) {
                return;
            }

            errorBanner.classList.add('hidden');
            errorText.textContent = '';
        }

        function showConfirm(message) {
            confirmText.textContent = message;
            confirmBox.classList.remove('hidden');
        }

        function hideConfirm() {
            confirmBox.classList.add('hidden');
        }

        function switchTab(index) {
            currentTab = Math.max(0, Math.min(TABS.length - 1, index));

            tabButtons.forEach(function (button, position) {
                var active = position === currentTab;

                button.classList.toggle('bg-white', active);
                button.classList.toggle('text-teal-800', active);
                button.classList.toggle('shadow-sm', active);
                button.classList.toggle('text-slate-600', ! active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            panels.forEach(function (panel, position) {
                panel.classList.toggle('hidden', position !== currentTab);
            });

            prevButton.disabled = currentTab === 0;
            nextButton.classList.toggle('hidden', currentTab === TABS.length - 1);
            saveButton.classList.toggle('hidden', currentTab !== TABS.length - 1);
            saveButton.classList.toggle('inline-flex', currentTab === TABS.length - 1);

            tabInput.value = String(currentTab);
        }

        function openModal() {
            lastFocused = document.activeElement;
            switchTab(0);
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';

            setTimeout(function () {
                var field = document.getElementById('fName');

                if (field) {
                    field.focus();
                }
            }, 50);
        }

        function closeModal() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
            clearError();
            hideConfirm();

            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        }

        /* Ganti __INDEX__ pada atribut name cetakan sehingga baris clone punya
           indeks sendiri dan tidak menimpa baris lain. */
        function indexTemplate(scope, index) {
            scope.querySelectorAll('[data-indexed]').forEach(function (element) {
                var name = element.getAttribute('name');

                if (name) {
                    element.setAttribute('name', name.split('__INDEX__').join(String(index)));
                }
            });

            var row = scope.querySelector('[data-row]');

            if (row) {
                row.setAttribute('data-row-index', String(index));
            }
        }

        function addDiagnosisRow(type, text, code) {
            var fragment = diagnosisTemplate.content.cloneNode(true);

            indexTemplate(fragment, diagnosisIndex);
            diagnosisIndex += 1;
            diagnosisRows.appendChild(fragment);

            var row = diagnosisRows.lastElementChild;

            if (type) {
                row.querySelector('.dx-type').value = type;
            }

            if (text) {
                row.querySelector('.dx-text').value = text;
            }

            if (code) {
                row.querySelector('.dx-code').value = code;
            }

            bindDiagnosisRemove(row);
        }

        /* Baris terakhir dilindungi: menghapus baris terakhir akan memunculkan
           kembali baris "utama" kosong, sama seperti prototype. */
        function removeDiagnosisRow(row) {
            if (row && row.parentNode) {
                row.parentNode.removeChild(row);
            }

            if (diagnosisRows.querySelectorAll('[data-row]').length === 0) {
                addDiagnosisRow('utama', '', '');
            }
        }

        function bindDiagnosisRemove(row) {
            var button = row.querySelector('.dx-remove');

            if (button) {
                button.addEventListener('click', function () {
                    removeDiagnosisRow(row);
                });
            }
        }

        function addProcedureRow(name, date, operator, code) {
            var fragment = procedureTemplate.content.cloneNode(true);

            indexTemplate(fragment, procedureIndex);
            procedureIndex += 1;
            procedureRows.appendChild(fragment);

            var row = procedureRows.lastElementChild;

            if (name) {
                row.querySelector('.pc-name').value = name;
            }

            if (date) {
                row.querySelector('.pc-date').value = date;
            }

            if (operator) {
                row.querySelector('.pc-operator').value = operator;
            }

            if (code) {
                row.querySelector('.pc-code').value = code;
            }

            bindProcedureRemove(row);
        }

        /* Berbeda dari diagnosa, menghapus prosedur sampai kosong dibiarkan. */
        function removeProcedureRow(row) {
            if (row && row.parentNode) {
                row.parentNode.removeChild(row);
            }
        }

        function bindProcedureRemove(row) {
            var button = row.querySelector('.pc-remove');

            if (button) {
                button.addEventListener('click', function () {
                    removeProcedureRow(row);
                });
            }
        }

        function collectDiagnoses() {
            var collected = [];

            diagnosisRows.querySelectorAll('[data-row]').forEach(function (row) {
                var text = row.querySelector('.dx-text').value.trim();

                if (text) {
                    collected.push({
                        type: row.querySelector('.dx-type').value,
                        text: text,
                        code: row.querySelector('.dx-code').value.trim()
                    });
                }
            });

            return collected;
        }

        function collectProcedures() {
            var collected = [];

            procedureRows.querySelectorAll('[data-row]').forEach(function (row) {
                var name = row.querySelector('.pc-name').value.trim();

                if (name) {
                    collected.push({
                        name: name,
                        performed_at: row.querySelector('.pc-date').value,
                        operator: row.querySelector('.pc-operator').value.trim(),
                        code: row.querySelector('.pc-code').value.trim()
                    });
                }
            });

            return collected;
        }

        /* Buang baris kosong sebelum submit; kalau tidak, server akan menerima
           indeks yang bolong. */
        function pruneEmptyRows() {
            diagnosisRows.querySelectorAll('[data-row]').forEach(function (row) {
                if (row.querySelector('.dx-text').value.trim() === '' && row.parentNode) {
                    row.parentNode.removeChild(row);
                }
            });

            procedureRows.querySelectorAll('[data-row]').forEach(function (row) {
                if (row.querySelector('.pc-name').value.trim() === '' && row.parentNode) {
                    row.parentNode.removeChild(row);
                }
            });
        }

        function isMrnTaken(mrn) {
            return knownPatients.indexOf(mrn) !== -1;
        }

        function submitAdmission() {
            clearError();
            hideConfirm();

            var diagnoses = collectDiagnoses();

            if (! value('fName').trim() || ! value('fMrn').trim() || ! value('fUnit').trim()
                || ! value('fBed').trim() || ! value('fAdmittedAt') || diagnoses.length === 0) {

                showError(REQUIRED_MESSAGE);
                switchTab(diagnoses.length ? 0 : TABS.indexOf('diagnosa'));
                return;
            }

            var mrn = value('fMrn').trim();

            /* Percabangan prototype: No. RM terdaftar berarti hanya admisi baru
               pada pasien yang sama. Konfirmasi-nya in-app, dan field
               confirm_duplicate dikirim supaya server mengizinkan. */
            if (isMrnTaken(mrn) && ! confirmInput.value) {
                showConfirm(
                    'No. RM ' + mrn + ' sudah terdaftar untuk ' + value('fName').trim()
                    + '. Tambahkan sebagai admisi baru?'
                );
                return;
            }

            pruneEmptyRows();
            saveButton.disabled = true;
            form.submit();
        }

        if (openButton) {
            openButton.addEventListener('click', openModal);
        }

        cancelButton.addEventListener('click', closeModal);

        Array.prototype.forEach.call(closeTargets, function (element) {
            element.addEventListener('click', closeModal);
        });

        Array.prototype.forEach.call(errorDismiss, function (element) {
            element.addEventListener('click', clearError);
        });

        addDiagnosisButton.addEventListener('click', function () {
            addDiagnosisRow('penyerta', '', '');
        });

        addProcedureButton.addEventListener('click', function () {
            addProcedureRow('', '', '', '');
        });

        prevButton.addEventListener('click', function () {
            switchTab(currentTab - 1);
        });

        nextButton.addEventListener('click', function () {
            switchTab(currentTab + 1);
        });

        Array.prototype.forEach.call(tabButtons, function (button) {
            button.addEventListener('click', function () {
                switchTab(parseInt(button.getAttribute('data-tab-index'), 10));
            });
        });

        confirmYes.addEventListener('click', function () {
            confirmInput.value = '1';
            hideConfirm();
            submitAdmission();
        });

        confirmNo.addEventListener('click', function () {
            confirmInput.value = '';
            hideConfirm();
            switchTab(0);
            mrnField.focus();
        });

        /* Mengubah No. RM membatalkan konfirmasi yang sudah tampil. */
        mrnField.addEventListener('input', function () {
            confirmInput.value = '';
            hideConfirm();
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitAdmission();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && ! modal.classList.contains('hidden')) {
                closeModal();
            }
        });

        diagnosisRows.querySelectorAll('[data-row]').forEach(bindDiagnosisRemove);
        procedureRows.querySelectorAll('[data-row]').forEach(bindProcedureRemove);

        switchTab(0);

        /* Server yang menolak (validasi gagal atau No. RM ganda tanpa
           konfirmasi) mengembalikan halaman ini; modal langsung dibuka lagi
           dengan isian lama. */
        if (modal.getAttribute('data-open-on-load') === '1') {
            openModal();
        }
    })();
</script>
@endpush