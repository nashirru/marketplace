<?php
// File: admin/import/import_processor.php
// (File ini di-include oleh admin.php saat POST)
// VERSI 11: LOGIKA V10 + PERBAIKAN "SUPER SMART" normalizeAddressSortedString
// Logika ini TIDAK LAGI membuang 'rt', 'rw', 'jl', 'no', dll.
// VERSI 14: Perbaikan logika sanitasi biaya import

// Keamanan: Pastikan file ini tidak diakses langsung
if (!defined('IS_ADMIN_PAGE')) {
    die('Akses dilarang!');
}

// -----------------------------------------------------------------
// PENTING: MEMUAT LIBRARY PHP SPREADSHEET
// -----------------------------------------------------------------
$autoload_path_root = __DIR__ . '/../../vendor/autoload.php';
$autoload_path_admin = __DIR__ . '/../vendor/autoload.php';
$final_path_to_load = null;

if (file_exists($autoload_path_root)) {
    $final_path_to_load = $autoload_path_root;
} elseif (file_exists($autoload_path_admin)) {
    $final_path_to_load = $autoload_path_admin;
}

if ($final_path_to_load) {
    require_once $final_path_to_load;
} else {
    set_flashdata('error', 'Library PhpSpreadsheet (vendor/autoload.php) tidak ditemukan. Pastikan Anda menjalankan <code>composer require phpoffice/phpspreadsheet</code> di folder root proyek Anda.');
    redirect('/admin/admin.php?page=import_resi');
    exit;
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Fungsi Normalisasi Nama (V5 - SUDAH BENAR)
 * Mencocokkan DENGAN TEPAT kueri SQL: LOWER() dan REPLACE(..., ' ', '')
 */
function normalizeName($str) {
    $str = strtolower(trim($str));
    $str = str_replace(' ', '', $str);
    return $str;
}

/**
 * Fungsi Normalisasi Alamat (DIRUBAH - V11 "Perbaikan Super Smart")
 * Mengubah string alamat menjadi string terurut yang bersih.
 * TIDAK LAGI membuang 'rt', 'rw', 'jl', 'no'
 */
function normalizeAddressSortedString($str) {
    // 1. Ubah ke huruf kecil
    $str = strtolower(trim($str));
    
    // 2. Hapus semua tanda baca (ganti dengan spasi agar kata tidak menempel)
    $str = preg_replace('/[^a-z0-9\s]/', ' ', $str);
    
    // 3. Pecah berdasarkan spasi (satu atau lebih)
    $words = preg_split('/\s+/', $str);
    
    // 4. Filter kata-kata kosong (HANYA KOSONG) dan buat unik
    $filtered_words = array_unique(array_filter($words, function($word) {
        // V11: Hapus filter $stopwords dan strlen.
        // Biarkan 'rt', 'rw', '1', '2' tetap ada.
        return $word !== '';
    }));
    
    // 5. Urutkan kata-kata secara alfabetis
    sort($filtered_words);
    
    // 6. Gabungkan kembali menjadi satu string
    return implode(' ', $filtered_words);
}


// -----------------------------------------------------------------
// Validasi File Upload
// -----------------------------------------------------------------
if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    set_flashdata('error', 'Gagal mengupload file. Pastikan file tidak rusak dan coba lagi.');
    redirect('/admin/admin.php?page=import_resi');
    exit;
}

$file = $_FILES['excel_file'];
$file_path = $file['tmp_name'];
$file_original_name = $file['name'];

$file_ext = strtolower(pathinfo($file_original_name, PATHINFO_EXTENSION));
$allowed_ext = ['xlsx', 'xls'];
if (!in_array($file_ext, $allowed_ext)) {
    set_flashdata('error', 'File harus berekstensi .xlsx atau .xls.');
    redirect('/admin/admin.php?page=import_resi');
    exit;
}

$new_file_name = 'import_' . time() . '_' . $file_original_name;
$destination_path = UPLOAD_DIR_IMPORTS . $new_file_name;
if (!move_uploaded_file($file_path, $destination_path)) {
     set_flashdata('error', 'Gagal memindahkan file upload ke server.');
     redirect('/admin/admin.php?page=import_resi');
     exit;
}

// -----------------------------------------------------------------
// Proses Import
// -----------------------------------------------------------------
try {
    $spreadsheet = IOFactory::load($destination_path);
    $worksheet = $spreadsheet->getActiveSheet();
    
    // Header yang kita butuhkan
    $expected_headers = [
        'nama penerima' => false,
        'alamat penerima' => false,
        'no. waybill' => false,
        'tanggal pengiriman' => false,
        'biaya setelah diskon' => false // <-- HEADER BARU
    ];
    
    $header_map = []; // [nama_header => indeks_kolom]

    $header_row = $worksheet->getRowIterator(1, 1)->current();
    if (!$header_row) {
        throw new Exception("File Excel kosong atau tidak bisa membaca baris pertama.");
    }

    $cell_iterator = $header_row->getCellIterator();
    $cell_iterator->setIterateOnlyExistingCells(false);
    
    foreach ($cell_iterator as $cell) {
        $header_value = strtolower(trim($cell->getValue()));
        $col_index = $cell->getColumn(); // 'A', 'B', 'C', dst.
        
        if (array_key_exists($header_value, $expected_headers)) {
            $expected_headers[$header_value] = true;
            $header_map[$header_value] = $col_index;
        }
    }

    // Cek apakah semua header wajib ada
    $missing_headers = [];
    foreach ($expected_headers as $header => $found) {
        if (!$found) {
            $missing_headers[] = "`$header`";
        }
    }

    if (!empty($missing_headers)) {
        throw new Exception('Header kolom tidak sesuai template. Header yang hilang/salah: ' . implode(', ', $missing_headers));
    }

    // =================================================================
    // LOGIKA "SMART MATCHING" (V11)
    // =================================================================
    
    $conn->begin_transaction(); 

    // Statement 1: Insert ke master list (mengabaikan duplikat resi)
    // DIPERBARUI: Tambah shipping_cost
    $stmt_insert = $conn->prepare("
        INSERT IGNORE INTO imported_shipments 
            (tracking_number, recipient_name, recipient_address, shipment_date, shipping_cost, imported_at) 
        VALUES 
            (?, ?, ?, ?, ?, NOW())
    ");

    // Statement 2: Ambil data order berdasarkan NAMA (SAMA)
    // Kita cari order yang 'siap kirim' atau 'sudah dikirim' (untuk ditimpa/ditambah)
    $stmt_find_orders_by_name = $conn->prepare("
        SELECT id, user_id, tracking_number, address_line_1, address_line_2 
        FROM orders 
        WHERE REPLACE(LOWER(full_name), ' ', '') = ?
          AND status IN ('processed', 'belum_dicetak', 'shipped') 
        ORDER BY created_at DESC
    ");

    // Statement 3: Update resi di tabel 'orders'
    // DIPERBARUI: Tambah shipping_fee_actual
    $stmt_update_order = $conn->prepare("
        UPDATE orders 
        SET tracking_number = ?, status = 'shipped', shipping_fee_actual = ?
        WHERE id = ?
    ");
    
    // Statistik untuk Laporan
    $total_rows_read = 0;
    $total_new_resi_inserted = 0;
    $total_skipped_master_duplicates = 0;
    $total_matched_orders = 0;
    $total_unmatched_resi = 0;
    $total_skipped_order_duplicates = 0;
    $total_failed_other = 0;

    // Batas minimum kemiripan (Misal 75%)
    $MINIMUM_SIMILARITY_THRESHOLD = 75; // Dalam persen

    // Mulai iterasi dari baris ke-2 (data)
    foreach ($worksheet->getRowIterator(2) as $row) {
        $total_rows_read++;
        
        // Baca data berdasarkan mapping header
        $name_val = sanitize_input($worksheet->getCell($header_map['nama penerima'] . $row->getRowIndex())->getValue());
        $address_val = sanitize_input($worksheet->getCell($header_map['alamat penerima'] . $row->getRowIndex())->getValue());
        $resi_val = sanitize_input($worksheet->getCell($header_map['no. waybill'] . $row->getRowIndex())->getValue());
        
        // <-- BARU: Baca biaya -->
        $cost_val_raw = $worksheet->getCell($header_map['biaya setelah diskon'] . $row->getRowIndex())->getValue();
        
        // --- MODIFIKASI V14: Logika pembersihan biaya yang lebih baik ---
        // 1. Hapus "Rp" (case-insensitive) dan spasi
        $cleaned_cost = str_ireplace('rp', '', $cost_val_raw);
        $cleaned_cost = trim($cleaned_cost);
        
        // 2. Hapus pemisah ribuan (.)
        $cleaned_cost = str_replace('.', '', $cleaned_cost);
        
        // 3. Ganti koma desimal (,) dengan titik (.) (jika Anda menggunakannya)
        // $cleaned_cost = str_replace(',', '.', $cleaned_cost);
        
        // 4. Ambil hanya angka menggunakan filter
        $cost_val = (float)filter_var($cleaned_cost, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        // --- AKHIR MODIFIKASI V14 ---
        
        // Baca tanggal pengiriman
        $tanggal_cell = $worksheet->getCell($header_map['tanggal pengiriman'] . $row->getRowIndex());
        $tanggal_val_raw = $tanggal_cell->getValue();
        $tanggal_val = null;
        if (Date::isDateTime($tanggal_cell)) {
             $tanggal_val = Date::excelToDateTimeObject($tanggal_val_raw)->format('Y-m-d H:i:s');
        } else {
             $timestamp = strtotime($tanggal_val_raw);
             if ($timestamp !== false) $tanggal_val = date('Y-m-d H:i:s', $timestamp);
        }
        
        // Jangan proses baris kosong
        if (empty($name_val) && empty($resi_val) && empty($address_val)) {
            $total_rows_read--; // Kurangi lagi karena ini baris kosong
            continue;
        }

        // Normalisasi nama dan alamat dari Excel
        $normalized_excel_name = normalizeName($name_val);
        $sorted_excel_address_str = normalizeAddressSortedString($address_val); // <-- V11

        // --- LANGKAH 1: Masukkan ke Master List 'imported_shipments' ---
        // DIPERBARUI: bind_param 's' (string) kelima diubah jadi 'd' (double) untuk cost
        $stmt_insert->bind_param("sssds", $resi_val, $name_val, $address_val, $tanggal_val, $cost_val);
        $stmt_insert->execute();

        if ($stmt_insert->affected_rows > 0) {
            $total_new_resi_inserted++;
        } else {
            if ($stmt_insert->errno == 0 || $stmt_insert->errno == 1062) {
                 $total_skipped_master_duplicates++;
            } else {
                $total_failed_other++;
            }
        }
        
        // Jangan proses matching jika resi atau nama atau alamat kosong
        if (empty($resi_val) || empty($normalized_excel_name) || empty($sorted_excel_address_str)) {
            if(!empty($resi_val)) $total_unmatched_resi++; // Tetap hitung sbg unmatched jika ada resi
            continue;
        }
        
        // --- LANGKAH 2: Cari SEMUA Order berdasarkan NAMA yang dinormalisasi ---
        $stmt_find_orders_by_name->bind_param("s", $normalized_excel_name);
        $stmt_find_orders_by_name->execute();
        $result_orders = $stmt_find_orders_by_name->get_result();

        $best_match_order_id = null;
        $best_match_current_resi = null;
        $highest_similarity = -1;
        $target_user_id = null; // <-- (PERMINTAAN ANDA) Tampung user_id

        if ($result_orders->num_rows > 0) {
            // Ada 1 atau lebih order dengan nama yang cocok.
            
            while ($order = $result_orders->fetch_assoc()) {
                // (PERMINTAAN ANDA) Ambil User ID dari match pertama (karena nama sama, user pasti sama)
                if($target_user_id === null) {
                    $target_user_id = $order['user_id'];
                }

                // Gabungkan address_line_1 dan address_line_2
                $combined_db_address = ($order['address_line_1'] ?? '') . ' ' . ($order['address_line_2'] ?? '');
                
                // Normalisasi alamat GABUNGAN DB
                $sorted_db_address_str = normalizeAddressSortedString($combined_db_address); // <-- V11
                
                if (empty($sorted_db_address_str)) continue;

                // Hitung persentase kemiripan "Sort & Similar Text"
                similar_text($sorted_excel_address_str, $sorted_db_address_str, $percent);

                if ($percent > $highest_similarity) {
                    $highest_similarity = $percent;
                    $best_match_order_id = $order['id'];
                    $best_match_current_resi = $order['tracking_number'];
                }
            }
        }

        // --- LANGKAH 3: Update Order TERBAIK jika kemiripan cukup ---
        // (PERMINTAAN ANDA): "match ke pengguna"
        // Logika ini sudah "match ke pengguna" (user_id) karena kita mencari berdasarkan NAMA dulu,
        // baru mencari alamat terbaik dari SEMUA order milik pengguna tersebut.
        
        if ($best_match_order_id !== null && $highest_similarity >= $MINIMUM_SIMILARITY_THRESHOLD) {
            // Order ditemukan!
            $order_id = $best_match_order_id;
            $current_resi_string = $best_match_current_resi;
            
            $current_resi_list = array_map('trim', array_filter(explode(',', $current_resi_string ?? '')));
            
            if (!in_array($resi_val, $current_resi_list)) {
                // Resi belum ada, tambahkan!
                $new_resi_string = $resi_val;
                if (!empty($current_resi_string)) {
                    $new_resi_string = $current_resi_string . ',' . $resi_val;
                }

                // DIPERBARUI: bind_param 'di' (double, integer)
                $stmt_update_order->bind_param("sdi", $new_resi_string, $cost_val, $order_id);
                $stmt_update_order->execute();
                
                if($stmt_update_order->affected_rows > 0) {
                    $total_matched_orders++;
                    // (Opsional) Kirim notifikasi ke user_id
                    if($target_user_id) {
                         // create_notification($conn, $target_user_id, "Pesanan Anda telah dikirim! No. Resi: $resi_val");
                    }
                } else {
                    $total_failed_other++;
                }
                
            } else {
                // Order ketemu, TAPI resi ini sudah ada di order itu
                $total_skipped_order_duplicates++;
            }

        } else {
            // Tidak ada order yang cocok (atau kemiripan alamat terlalu rendah)
            $total_unmatched_resi++;
        }
    }

    // Selesai, tutup semua statement
    $stmt_insert->close();
    $stmt_find_orders_by_name->close();
    $stmt_update_order->close();
    
    // Commit transaksi
    $conn->commit();

    // -----------------------------------------------------------------
    // Ringkasan Import (TEKS DIPERBARUI)
    // -----------------------------------------------------------------
    
    $summary_message = "<b>Ringkasan Import Berhasil:</b><br>" .
        "- Total Baris Dibaca dari Excel: $total_rows_read<br><br>" .
        "<b>Status Master Resi ('imported_shipments'):</b><br>" . 
        "- Resi Baru Ditambahkan: <b>$total_new_resi_inserted</b><br>" .
        "- Dilewati (Resi Duplikat/Sudah Ada): $total_skipped_master_duplicates<br><br>" .
        "<b>Status Update ke Order ('orders'):</b><br>" .
        "- Resi Berhasil Dimasukkan ke Order: <b>$total_matched_orders</b><br>" .
        "- Resi (Tidak Menemukan Order): $total_unmatched_resi<br>" .
        "- Resi Dilewati (Sudah ada di Order tsb): $total_skipped_order_duplicates<br><br>" .
        "- Gagal (Error Lain): $total_failed_other";
    
    set_flashdata('info', $summary_message);

} catch (Exception $e) {
    // Tangkap error (cth: header salah, file rusak, db error)
    $conn->rollback(); 
    set_flashdata('error', 'Proses Import Gagal: ' . $e->getMessage());
}

// Hapus file yang diupload setelah selesai diproses
if (file_exists($destination_path)) {
    unlink($destination_path);
}

redirect('/admin/admin.php?page=import_resi');
exit;
?>