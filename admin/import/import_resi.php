<?php
// File: admin/import/import_resi.php
// (File ini di-include oleh admin.php)

// Keamanan: Pastikan file ini tidak diakses langsung
if (!defined('IS_ADMIN_PAGE')) {
    die('Akses dilarang!');
}

// (Logika proses sudah dipindah ke admin.php)
?>

<div class="bg-white shadow-md rounded-lg p-6 max-w-2xl mx-auto">
    
    <!-- Poin 1: Form Upload -->
    <h2 class="text-2xl font-semibold mb-4 text-gray-800">1. Upload File Resi</h2>
    
    <form action="admin.php?page=import_resi" method="POST" enctype="multipart/form-data" id="import-form">
        <input type="hidden" name="action" value="process_import">
        
        <div class="mb-4">
            <label for="excel_file" class="block text-sm font-medium text-gray-700 mb-2">Pilih File Excel (.xlsx / .xls)</label>
            <input type="file" name="excel_file" id="excel_file" required 
                   accept=".xlsx, .xls, application/vnd.ms-excel, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm
                          file:mr-4 file:py-2 file:px-4
                          file:rounded-md file:border-0
                          file:text-sm file:font-semibold
                          file:bg-indigo-50 file:text-indigo-700
                          hover:file:bg-indigo-100">
        </div>

        <div class="text-right">
            <button type="submit" id="submit-button"
                    class="inline-flex items-center px-6 py-2 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500
                           disabled:bg-gray-400 disabled:cursor-not-allowed">
                <i class="fas fa-upload mr-2"></i>
                Upload dan Proses
            </button>
        </div>
    </form>

    <!-- Elemen Loading -->
    <div id="loading-spinner" class="hidden text-center mt-4">
        <i class="fas fa-spinner fa-spin text-3xl text-indigo-600"></i>
        <p class="text-gray-600 mt-2">Sedang memproses... Ini mungkin memakan waktu beberapa saat. Jangan tutup halaman ini.</p>
    </div>

</div>

<div class="bg-white shadow-md rounded-lg p-6 max-w-2xl mx-auto mt-8">
    
    <!-- Poin 2: Info Validasi Sistem -->
    <h2 class="text-2xl font-semibold mb-4 text-gray-800">2. Template & Aturan Validasi</h2>
    
    <p class="text-gray-700 mb-4">Pastikan file Excel yang Anda upload memiliki header kolom (di baris pertama) sesuai dengan template berikut (case-insensitive):</p>
    
    <div class="bg-gray-50 p-4 rounded-md border border-gray-200">
        <ul class="list-disc list-inside space-y-2 text-gray-800">
            <li>`nama penerima` (Nama penerima di pesanan)</li>
            <li>`alamat penerima` (Alamat baris 1 di pesanan)</li>
            <li>`no. waybill` (Nomor resi J&T)</li>
            <li>`tanggal pengiriman` (Tanggal resi dibuat)</li>
            <li><strong class="text-indigo-600">`biaya setelah diskon` (Biaya ongkir final)</strong></li>
        </ul>
    </div>
    
    <p class="text-sm text-gray-600 mt-4">
        <i class="fas fa-info-circle mr-1 text-blue-500"></i>
        Sistem akan memvalidasi nama header. Jika tidak sesuai, proses akan dibatalkan.
    </p>
    
    <p class="text-sm text-gray-600 mt-2">
        <i class="fas fa-robot mr-1 text-blue-500"></i>
        <b>Proses Matching (Logika "Super Smart" V11):</b>
        <br>
        1. Sistem akan memasukkan resi & biaya ongkir ke database master (menghindari duplikat).
        <br>
        2. Sistem akan mencari pesanan (status: 'processed'/'belum_dicetak') berdasarkan:
        <br>
        - <b>Nama Penerima</b> (Mendukung beda huruf besar/kecil, cth: 'MUHAMMAD' vs 'muhammad')
        <br>
        - <b>Alamat Penerima</b> (Sangat Canggih: Mendukung beda urutan kata DAN data 'kode' J&T. Cth: 'Jl Kalimantan RT1' vs 'RT1 Jl Kalimantan' akan match. 'NNZZ HWHSHS' vs 'NNZZ HWHSHS' juga akan match.)
        <br>
        3. Jika pesanan ditemukan (Nama COCOK dan Alamat MIRIP), resi akan ditambahkan, status diubah ke 'shipped', dan <strong>biaya ongkir akan di-update</strong>.
    </p>

</div>

<script>
// Script untuk menampilkan loading spinner saat form disubmit
document.getElementById('import-form').addEventListener('submit', function() {
    // Cek jika file sudah dipilih
    if (document.getElementById('excel_file').files.length === 0) {
        // Jangan submit jika tidak ada file
        return;
    }

    // Nonaktifkan tombol
    document.getElementById('submit-button').disabled = true;
    document.getElementById('submit-button').innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Memproses...';
    
    // Tampilkan spinner
    document.getElementById('loading-spinner').classList.remove('hidden');
});
</script>