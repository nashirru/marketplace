<?php
// File: help/help.php - Halaman Pusat Bantuan

// Pemuatan File Inti
// Koneksi $conn dan BASE_URL tersedia setelah ini
require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php'; // Memuat fungsi navbar, footer, dan page_head yang baru

// Memuat pengaturan toko ke cache (penting untuk navbar dan footer)
load_settings($conn);

// Judul halaman dan Deskripsi SEO
$store_name = get_setting($conn, 'store_name') ?? 'Marketplace';
$page_title = "Bantuan - " . htmlspecialchars($store_name);
$seo_desc_help = "Temukan jawaban atas pertanyaan umum (FAQ) mengenai cara belanja, pembayaran, dan pengiriman di " . htmlspecialchars($store_name) . ". Hubungi kami jika Anda memerlukan bantuan lebih lanjut.";
// Opsional: Keywords khusus
$seo_keywords_help = "bantuan, faq, cara pesan, pembayaran, pengiriman, " . strtolower(htmlspecialchars($store_name));

?>

<!DOCTYPE html>
<html lang="id">
<?php
// Memanggil page_head yang baru dengan deskripsi dan keyword SEO
page_head($page_title, $conn, $seo_desc_help, $seo_keywords_help);
?>
<body>

    <!-- Memuat Navbar DENGAN $conn -->
    <?php navbar($conn); ?>

    <main class="container mx-auto px-4 py-12 min-h-screen max-w-4xl">
        <h1 class="text-4xl font-extrabold text-gray-900 mb-8 border-b pb-4">Pusat Bantuan (FAQ)</h1>

        <div class="space-y-6">
            <!-- FAQ 1 -->
            <div class="bg-white p-6 rounded-xl shadow-lg transition hover:shadow-xl">
                <details class="group">
                    <summary class="flex justify-between items-center cursor-pointer list-none">
                        <h2 class="text-xl font-semibold text-indigo-600 group-hover:text-indigo-800">Bagaimana cara memesan produk?</h2>
                        <span class="ml-4 transition-transform duration-300 group-open:rotate-180">
                            <i class="fas fa-chevron-down text-indigo-500"></i>
                        </span>
                    </summary>
                    <p class="text-gray-700 mt-3 leading-relaxed">Pilih produk yang Anda inginkan, tentukan jumlahnya, lalu klik tombol "Tambah ke Keranjang". Setelah selesai memilih, buka ikon keranjang di pojok kanan atas, periksa pesanan Anda, dan klik "Checkout". Ikuti langkah-langkah selanjutnya untuk mengisi alamat pengiriman dan memilih metode pembayaran. Pastikan semua data sudah benar sebelum menyelesaikan pesanan.</p>
                </details>
            </div>

            <!-- FAQ 2 -->
            <div class="bg-white p-6 rounded-xl shadow-lg transition hover:shadow-xl">
                 <details class="group">
                    <summary class="flex justify-between items-center cursor-pointer list-none">
                        <h2 class="text-xl font-semibold text-indigo-600 group-hover:text-indigo-800">Apa saja metode pembayaran yang diterima?</h2>
                         <span class="ml-4 transition-transform duration-300 group-open:rotate-180">
                            <i class="fas fa-chevron-down text-indigo-500"></i>
                        </span>
                    </summary>
                    <p class="text-gray-700 mt-3 leading-relaxed">Saat ini kami menerima pembayaran melalui transfer bank manual ke rekening yang tertera saat proses checkout. Pastikan Anda melakukan konfirmasi pembayaran setelah transfer agar pesanan dapat segera kami proses. Kami sedang berupaya menambahkan metode pembayaran otomatis lainnya.</p>
                 </details>
            </div>

            <!-- FAQ 3 -->
            <div class="bg-white p-6 rounded-xl shadow-lg transition hover:shadow-xl">
                 <details class="group">
                    <summary class="flex justify-between items-center cursor-pointer list-none">
                        <h2 class="text-xl font-semibold text-indigo-600 group-hover:text-indigo-800">Berapa lama waktu pengiriman?</h2>
                         <span class="ml-4 transition-transform duration-300 group-open:rotate-180">
                            <i class="fas fa-chevron-down text-indigo-500"></i>
                        </span>
                    </summary>
                    <p class="text-gray-700 mt-3 leading-relaxed">Waktu pengemasan pesanan biasanya 1-2 hari kerja setelah pembayaran dikonfirmasi. Waktu pengiriman oleh kurir bervariasi tergantung lokasi tujuan Anda, umumnya antara 3 hingga 7 hari kerja untuk wilayah Indonesia. Anda akan menerima notifikasi saat pesanan dikirim.</p>
                 </details>
            </div>

             <!-- FAQ 4 (Contoh Tambahan) -->
            <div class="bg-white p-6 rounded-xl shadow-lg transition hover:shadow-xl">
                 <details class="group">
                    <summary class="flex justify-between items-center cursor-pointer list-none">
                        <h2 class="text-xl font-semibold text-indigo-600 group-hover:text-indigo-800">Bagaimana jika saya ingin membatalkan pesanan?</h2>
                         <span class="ml-4 transition-transform duration-300 group-open:rotate-180">
                            <i class="fas fa-chevron-down text-indigo-500"></i>
                        </span>
                    </summary>
                    <p class="text-gray-700 mt-3 leading-relaxed">Anda dapat membatalkan pesanan jika statusnya masih "Menunggu Pembayaran" melalui halaman profil Anda di bagian "Pesanan Saya". Jika pesanan sudah dibayar atau diproses, silakan hubungi customer service kami secepatnya untuk bantuan.</p>
                 </details>
            </div>

        </div>

        <div class="mt-12 text-center p-6 bg-indigo-50 rounded-xl border border-indigo-100">
            <h2 class="text-2xl font-bold text-indigo-700">Masih Butuh Bantuan?</h2>
            <p class="mt-2 text-indigo-600">Jangan ragu untuk menghubungi tim support kami melalui detail kontak yang tersedia di bagian bawah halaman ini.</p>
            <!-- Opsional: Tombol kontak -->
            <!-- <a href="mailto:<?= htmlspecialchars(get_setting($conn, 'store_email') ?? '') ?>" class="mt-4 inline-block bg-indigo-600 text-white font-semibold py-2 px-5 rounded-md hover:bg-indigo-700 transition">Kirim Email</a> -->
        </div>
    </main>

    <!-- Memuat Footer DENGAN $conn (yang sudah ada FAQ) -->
    <?php footer($conn); ?>

</body>
</html>