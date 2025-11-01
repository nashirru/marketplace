<?php
// File: test_bug.php
// Skrip ini akan mensimulasikan 10 user yang mencoba checkout
// produk yang sama (dengan stok 1) secara bersamaan.

set_time_limit(120);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ==================================================================
// KONFIGURASI WAJIB
// Ubah 3 variabel di bawah ini
// ==================================================================

// 1. URL lengkap ke file checkout_process.php Anda
$CHECKOUT_URL = 'https://uncompiled-thriftless-semaj.ngrok-free.dev/warok/checkout/checkout_process.php';

// 2. ID alamat yang valid dari tabel user_addresses
$ADDRESS_ID_TO_USE = 10; // Ganti dengan ID alamat yang ada di database Anda

// 3. Cookie Sesi Anda
// Cara mendapatkannya:
//    - Login ke web Anda
//    - Buka Dev Tools (F12) -> Application -> Cookies
//    - Copy nama dan value cookie sesi (misal: 'PHPSESSID=abc123xyz890')
$COOKIE_STRING = 'PHPSESSID=kp0lo3iherveembftl6rg6i7m9'; // GANTI INI

// 4. Jumlah request simultan (untuk membeli stok 1)
$NUM_REQUESTS = 10;

// ==================================================================
// AKHIR KONFIGURASI
// ==================================================================

echo "<h1>Tes Race Condition Dimulai...</h1>";
echo "<p>Menyiapkan <strong>$NUM_REQUESTS</strong> request simultan ke <strong>$CHECKOUT_URL</strong>...</p>";
echo "<p>Mencoba membeli produk dengan stok 1...</p>";
echo "<hr>";
echo "<pre>";

// Data POST yang akan dikirim, sama seperti form checkout
// Ini mengasumsikan user MENGGUNAKAN alamat tersimpan
$postData = http_build_query([
    'existing_address' => $ADDRESS_ID_TO_USE
    // Jika Anda TIDAK menggunakan alamat tersimpan,
    // Anda harus menambahkan field lain di sini
    // 'full_name' => 'Test User',
    // 'phone_number' => '08123456789',
    // ... dst
]);

$master = curl_multi_init();
$curl_handles = [];

for ($i = 0; $i < $NUM_REQUESTS; $i++) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $CHECKOUT_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_COOKIE, $COOKIE_STRING); // PENTING untuk sesi
    curl_setopt($ch, CURLOPT_HEADER, false);
    
    // Tambahkan user agent agar terlihat seperti browser
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

    curl_multi_add_handle($master, $ch);
    $curl_handles[$i] = $ch;
}

// Jalankan semua request secara simultan
$running = null;
do {
    curl_multi_exec($master, $running);
    curl_multi_select($master);
} while ($running > 0);

// Ambil hasil
$results = [];
$success_count = 0;
$fail_count = 0;

for ($i = 0; $i < $NUM_REQUESTS; $i++) {
    $output = curl_multi_getcontent($curl_handles[$i]);
    $json_output = json_decode($output, true);
    
    if ($json_output && isset($json_output['success'])) {
        if ($json_output['success'] === true) {
            $success_count++;
            $results[$i] = "Request #$i: BERHASIL! (Token: " . substr($json_output['snap_token'], 0, 10) . "...)";
        } else {
            $fail_count++;
            $results[$i] = "Request #$i: GAGAL! (Pesan: " . htmlspecialchars($json_output['message']) . ")";
        }
    } else {
        $fail_count++;
        $results[$i] = "Request #$i: GAGAL TOTAL! (Response non-JSON atau error)\nOutput: " . htmlspecialchars($output);
    }
    
    curl_multi_remove_handle($master, $curl_handles[$i]);
}

curl_multi_close($master);

// Tampilkan Laporan
echo "<h2>HASIL TES:</h2>\n";

foreach ($results as $result) {
    echo $result . "\n";
}

echo "\n<hr><h2>RINGKASAN:</h2>\n";
echo "Request BERHASIL: $success_count\n";
echo "Request GAGAL:     $fail_count\n";

echo "\n\n";

if ($success_count === 1) {
    echo "<strong>STATUS: FIX BERHASIL!</strong>\n";
    echo "Hanya 1 dari $NUM_REQUESTS request yang berhasil, sisanya gagal karena stok habis.\n";
    echo "Silakan cek database Anda. Stok produk sekarang harus 0 dan hanya ada 1 order baru.";
} else if ($success_count > 1) {
    echo "<strong>STATUS: BUG MASIH ADA!</strong>\n";
    echo "Lebih dari 1 request ($success_count) berhasil! Ini berarti stok Anda minus.\n";
    echo "Cek kembali apakah Anda sudah menjalankan `perintah.sql` (ENGINE=InnoDB) dan mengupload file PHP baru.";
} else {
    echo "<strong>STATUS: TES GAGAL!</strong>\n";
    echo "Tidak ada request yang berhasil. Ini bisa berarti cookie/session Anda salah, atau ada error lain di `checkout_process.php`.\n";
    echo "Cek `logs/checkout_debug.log` untuk detail.";
}

echo "</pre>";
?>