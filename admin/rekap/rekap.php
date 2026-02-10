<?php
// File: rekap/rekap.php
// Pastikan file ini di-include dari admin.php
if (!defined('IS_ADMIN_PAGE')) {
    die("Akses dilarang...");
}

// Set tanggal default: 30 hari terakhir
$default_end_date = date('Y-m-d');
$default_start_date = date('Y-m-d', strtotime('-29 days'));

// Daftar status pesanan dari ENUM di database
$order_statuses = [
    'waiting_payment' => 'Menunggu Pembayaran',
    'waiting_approval' => 'Menunggu Konfirmasi',
    'belum_dicetak' => 'Belum Dicetak',
    'processed' => 'Diproses',
    'shipped' => 'Dikirim',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan'
];

// Status yang di-check secara default (yang valid untuk laporan)
$default_checked_statuses = ['belum_dicetak', 'processed', 'shipped', 'completed'];

?>
<style>
    /* Styling untuk tab "sangar" */
    .tab-btn {
        transition: all 0.3s ease;
        border-bottom: 4px solid transparent;
    }
    .tab-btn.active {
        border-bottom-color: #4f46e5; /* Indigo-600 */
        color: #4f46e5;
        font-weight: 600;
    }
    .tab-btn:not(.active):hover {
        border-bottom-color: #e0e7ff; /* Indigo-200 */
        color: #6366f1; /* Indigo-500 */
    }
    .tab-content {
        display: none;
    }
    .tab-content.active {
        display: block;
    }
    
    /* Styling untuk multi-select status (checkbox group) */
    .status-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 0.75rem;
    }
</style>

<div class="bg-white p-6 rounded-lg shadow-lg">
    <form id="rekapForm" action="rekap/export_laporan.php" method="POST" target="_blank">

        <!-- 1. Pilihan Tab (Dunia yang Berbeda) -->
        <div class="mb-6 border-b border-gray-200">
            <nav class="flex -mb-px space-x-6" aria-label="Tabs">
                <button type="button" id="tab-btn-pesanan"
                        class="tab-btn active w-1/2 py-4 px-1 text-center text-gray-500 hover:text-gray-700 text-lg"
                        onclick="switchTab('pesanan')">
                    <i class="fas fa-file-invoice-dollar mr-2"></i> Laporan Pesanan
                </button>
                <button type="button" id="tab-btn-produk"
                        class="tab-btn w-1/2 py-4 px-1 text-center text-gray-500 hover:text-gray-700 text-lg"
                        onclick="switchTab('produk')">
                    <i class="fas fa-box-open mr-2"></i> Laporan Penjualan Produk
                </button>
            </nav>
        </div>
        
        <!-- Hidden input untuk report_type -->
        <input type="hidden" id="report_type" name="report_type" value="pesanan">

        <!-- 2. Pilihan Tanggal (Berlaku untuk semua) -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6 pb-6 border-b border-gray-200">
            <div>
                <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Tanggal Mulai</label>
                <input type="text" id="start_date" name="start_date"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                       value="<?= $default_start_date ?>" required>
            </div>
            <div>
                <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">Tanggal Selesai</label>
                <input type="text" id="end_date" name="end_date"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                       value="<?= $default_end_date ?>" required>
            </div>
        </div>

        <!-- 3. Konten Tab Spesifik -->
        <div class="space-y-6">
            <!-- Dunia 1: Opsi Laporan Pesanan -->
            <div id="tab-content-pesanan" class="tab-content active space-y-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Filter Status Pesanan</label>
                    <p class="text-sm text-gray-500 mb-3">Pilih satu atau lebih status yang ingin dimasukkan dalam laporan.</p>
                    <div class="status-grid p-4 border border-gray-200 rounded-md bg-gray-50">
                        <?php foreach ($order_statuses as $key => $label): ?>
                        <div class="relative flex items-start">
                            <div class="flex items-center h-5">
                                <input id="status_<?= $key ?>" name="order_status[]" type="checkbox" value="<?= $key ?>"
                                       class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
                                       <?= in_array($key, $default_checked_statuses) ? 'checked' : '' ?>>
                            </div>
                            <div class="ml-3 text-sm">
                                <label for="status_<?= $key ?>" class="font-medium text-gray-700"><?= $label ?></label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Dunia 2: Opsi Laporan Produk -->
            <div id="tab-content-produk" class="tab-content space-y-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Opsi Laporan Produk</label>
                    <div class="space-y-2">
                        <div class="relative flex items-start">
                            <div class="flex items-center h-5">
                                <input id="hide_product_id" name="hide_product_id" type="checkbox" value="1"
                                       class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                            </div>
                            <div class="ml-3 text-sm">
                                <label for="hide_product_id" class="font-medium text-gray-700">Sembunyikan ID Produk</label>
                                <p class="text-gray-500">Menyembunyikan kolom ID Produk dari laporan.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <label for="group_by" class="block text-sm font-medium text-gray-700 mb-1">Grup Berdasarkan (Fitur Baru)</label>
                    <select id="group_by" name="group_by" class="w-full max-w-xs px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="none" selected>Tidak Digrupkan (Per Produk)</option>
                        <option value="category">Per Kategori Produk</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- 4. Opsi General (Berlaku untuk semua) -->
        <div class="mt-6 pt-6 border-t border-gray-200">
            <label class="block text-base font-semibold text-gray-800 mb-3">Opsi General Laporan</label>
            <div class="space-y-3">
                <div class="relative flex items-start">
                    <div class="flex items-center h-5">
                        <input id="hide_financial" name="hide_financial" type="checkbox" value="1"
                               class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="hide_financial" class="font-medium text-gray-700">Sembunyikan Data Keuangan</label>
                        <p class="text-gray-500">Hilangkan info harga, total, dan omzet dari laporan.</p>
                    </div>
                </div>

                <div class="relative flex items-start">
                    <div class="flex items-center h-5">
                        <input id="include_summary" name="include_summary" type="checkbox" value="1"
                               class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded" checked>
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="include_summary" class="font-medium text-gray-700">Sertakan Ringkasan Total</label>
                        <p class="text-gray-500">Menampilkan box total omzet, total pesanan, dll. di atas tabel.</p>
                    </div>
                </div>

                <div id="opsi_alamat_wrapper" class="relative flex items-start">
                    <div class="flex items-center h-5">
                        <input id="include_address" name="include_address" type="checkbox" value="1"
                               class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="include_address" class="font-medium text-gray-700">Sertakan Alamat Pengiriman</label>
                        <p class="text-gray-500">Hanya berlaku untuk Laporan Pesanan.</p>
                    </div>
                </div>
                
                <div id="opsi_chart_wrapper" class="relative flex items-start">
                    <div class="flex items-center h-5">
                        <input id="include_chart" name="include_chart" type="checkbox" value="1"
                               class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="include_chart" class="font-medium text-gray-700">Sertakan Chart Penjualan (PDF)</label>
                        <p class="text-gray-500">Hanya berlaku untuk ekspor PDF Laporan Pesanan.</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Hidden input untuk gambar chart (diisi oleh JS) -->
        <input type="hidden" name="chart_image_base64" id="chart_image_base64">

        <!-- 5. Tombol Aksi -->
        <div class="flex items-center justify-end space-x-4 pt-6 border-t border-gray-200">
            <button type="submit" name="export_format" value="excel"
                    class="inline-flex items-center justify-center px-5 py-2.5 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                <i class="fas fa-file-excel mr-2"></i> Export Excel
            </button>
            <button type="submit" name="export_format" value="pdf"
                    class="inline-flex items-center justify-center px-5 py-2.5 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                <i class="fas fa-file-pdf mr-2"></i> Export PDF
            </button>
        </div>

    </form>
</div>

<!-- 6. Preview Chart (Hanya untuk Laporan Pesanan) -->
<div id="chart_preview_wrapper" class="bg-white p-6 rounded-lg shadow-lg mt-6">
    <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Preview Chart Omzet Pesanan</h3>
    <p class="text-sm text-gray-500 mb-2">Chart ini menunjukkan total pendapatan berdasarkan filter tanggal dan status yang dipilih.</p>
    <div class="h-80 w-full">
        <canvas id="rekapChartPreview"></canvas>
    </div>
    <div id="chartLoading" class="text-center p-4">
        <i class="fas fa-spinner fa-spin mr-2"></i> Memuat data chart...
    </div>
</div>

<script>
    // Referensi elemen-elemen UI
    const elements = {
        form: document.getElementById('rekapForm'),
        reportTypeInput: document.getElementById('report_type'),
        
        tabBtnPesanan: document.getElementById('tab-btn-pesanan'),
        tabBtnProduk: document.getElementById('tab-btn-produk'),
        tabContentPesanan: document.getElementById('tab-content-pesanan'),
        tabContentProduk: document.getElementById('tab-content-produk'),
        
        startDatePicker: null,
        endDatePicker: null,
        
        hideFinancialCheck: document.getElementById('hide_financial'),
        includeAddressWrapper: document.getElementById('opsi_alamat_wrapper'),
        includeAddressCheck: document.getElementById('include_address'),
        
        chartPreviewWrapper: document.getElementById('chart_preview_wrapper'),
        opsiChartWrapper: document.getElementById('opsi_chart_wrapper'),
        includeChartCheck: document.getElementById('include_chart'),
        
        chartLoading: document.getElementById('chartLoading'),
        myRekapChart: null
    };

    // Fungsi untuk ganti tab
    function switchTab(tabName) {
        elements.reportTypeInput.value = tabName;
        
        const isPesanan = (tabName === 'pesanan');
        
        // Atur tombol tab
        elements.tabBtnPesanan.classList.toggle('active', isPesanan);
        elements.tabBtnProduk.classList.toggle('active', !isPesanan);
        
        // Atur konten tab
        elements.tabContentPesanan.classList.toggle('active', isPesanan);
        elements.tabContentProduk.classList.toggle('active', !isPesanan);
        
        // Atur opsi general yang spesifik
        // Opsi Alamat
        elements.includeAddressWrapper.style.opacity = isPesanan ? '1' : '0.5';
        elements.includeAddressCheck.disabled = !isPesanan;
        if (!isPesanan) {
            elements.includeAddressCheck.checked = false;
        }
        
        // Opsi Chart & Preview (hanya untuk pesanan)
        const showChart = isPesanan && !elements.hideFinancialCheck.checked;
        elements.chartPreviewWrapper.style.display = showChart ? 'block' : 'none';
        elements.opsiChartWrapper.style.opacity = showChart ? '1' : '0.5';
        elements.includeChartCheck.disabled = !showChart;
        if (!showChart) {
            elements.includeChartCheck.checked = false;
        }
        
        // Update chart jika tab pesanan aktif (dan keuangan tidak hidden)
        if (showChart) {
            updateChartPreview();
        }
    }

    // Fungsi untuk update chart preview
    async function updateChartPreview() {
        // Hanya update jika tab pesanan aktif
        if (elements.reportTypeInput.value !== 'pesanan' || elements.hideFinancialCheck.checked) {
            elements.chartPreviewWrapper.style.display = 'none';
            return;
        }
        
        elements.chartPreviewWrapper.style.display = 'block';
        elements.chartLoading.style.display = 'block';

        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        
        // Kumpulkan status yang dicheck
        const statusCheckboxes = document.querySelectorAll('input[name="order_status[]"]:checked');
        const statusQuery = Array.from(statusCheckboxes).map(cb => `status[]=${encodeURIComponent(cb.value)}`).join('&');
        
        if (!startDate || !endDate) return;

        try {
            const response = await fetch(`rekap/ajax_get_rekap_chart.php?start_date=${startDate}&end_date=${endDate}&${statusQuery}`);
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            const result = await response.json();
            
            if (result.success && elements.myRekapChart) {
                elements.myRekapChart.data.labels = result.labels;
                elements.myRekapChart.data.datasets[0].data = result.data;
                elements.myRekapChart.update();
            } else {
                console.error('Gagal mengambil data chart:', result.message);
            }
        } catch (error) {
            console.error('Error fetching chart data:', error);
        } finally {
            elements.chartLoading.style.display = 'none';
        }
    }

    // Fungsi untuk toggle opsi berdasarkan 'Sembunyikan Keuangan'
    function toggleFinancialOptions() {
        const isHidden = elements.hideFinancialCheck.checked;
        const isPesanan = (elements.reportTypeInput.value === 'pesanan');
        
        const showChart = !isHidden && isPesanan;
        
        elements.chartPreviewWrapper.style.display = showChart ? 'block' : 'none';
        elements.opsiChartWrapper.style.opacity = showChart ? '1' : '0.5';
        elements.includeChartCheck.disabled = !showChart;
        if (!showChart) {
            elements.includeChartCheck.checked = false;
        }
        
        if (showChart) {
            updateChartPreview();
        }
    }

    // Inisialisasi saat DOM loaded
    document.addEventListener('DOMContentLoaded', function () {
        // Inisialisasi Flatpickr
        elements.startDatePicker = flatpickr("#start_date", {
            dateFormat: "Y-m-d",
            defaultDate: "<?= $default_start_date ?>",
            onChange: function(selectedDates, dateStr, instance) {
                elements.endDatePicker.set('minDate', dateStr);
                updateChartPreview();
            }
        });
        
        elements.endDatePicker = flatpickr("#end_date", {
            dateFormat: "Y-m-d",
            defaultDate: "<?= $default_end_date ?>",
            minDate: "<?= $default_start_date ?>",
            onChange: function(selectedDates, dateStr, instance) {
                elements.startDatePicker.set('maxDate', dateStr);
                updateChartPreview();
            }
        });

        // Inisialisasi Chart.js
        const ctx = document.getElementById('rekapChartPreview').getContext('2d');
        elements.myRekapChart = new Chart(ctx, {
            type: 'line',
            data: { labels: [], datasets: [{
                label: 'Total Penjualan (Rp)',
                data: [],
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                borderColor: 'rgba(79, 70, 229, 1)',
                borderWidth: 2,
                fill: true,
                tension: 0.1
            }]},
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { callback: (value) => 'Rp ' + new Intl.NumberFormat('id-ID').format(value) }},
                    x: { ticks: { maxRotation: 70, minRotation: 0 } }
                },
                plugins: {
                    tooltip: { callbacks: { label: (context) => 'Rp ' + new Intl.NumberFormat('id-ID').format(context.parsed.y) }}
                }
            }
        });
        
        // Event Listeners
        // 1. Tombol 'Sembunyikan Keuangan'
        elements.hideFinancialCheck.addEventListener('change', toggleFinancialOptions);
        
        // 2. Checkbox status (untuk update chart)
        document.querySelectorAll('input[name="order_status[]"]').forEach(cb => {
            cb.addEventListener('change', updateChartPreview);
        });

        // 3. Form submission (untuk PDF chart)
        elements.form.addEventListener('submit', function(e) {
            const submitter = e.submitter;
            const exportFormat = submitter ? submitter.value : null;

            if (exportFormat === 'pdf' && elements.includeChartCheck.checked && !elements.hideFinancialCheck.checked && elements.reportTypeInput.value === 'pesanan') {
                document.getElementById('chart_image_base64').value = elements.myRekapChart.toBase64Image('image/png');
            } else {
                document.getElementById('chart_image_base64').value = '';
            }
        });

        // Inisialisasi state awal UI
        switchTab('pesanan'); // Default ke tab pesanan
    });
</script>