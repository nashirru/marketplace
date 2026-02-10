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
            
            <?php
            // --- AWAL KONTEN DINAMIS ---
            // Ambil data FAQ yang aktif dari database
            // DIURUTKAN BERDASARKAN 'sort_order'
            $faq_result = $conn->query("SELECT question, answer FROM faq WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
            
            if ($faq_result && $faq_result->num_rows > 0):
                while ($faq = $faq_result->fetch_assoc()):
            ?>
                    <!-- FAQ Dinamis -->
                    <div class="bg-white p-6 rounded-xl shadow-lg transition hover:shadow-xl">
                        <details class="group">
                            <summary class="flex justify-between items-center cursor-pointer list-none">
                                <h2 class="text-xl font-semibold text-indigo-600 group-hover:text-indigo-800"><?= htmlspecialchars($faq['question']) ?></h2>
                                <span class="ml-4 transition-transform duration-300 group-open:rotate-180">
                                    <i class="fas fa-chevron-down text-indigo-500"></i>
                                </span>
                            </summary>
                            <!-- Gunakan nl2br() untuk menghormati baris baru dari textarea -->
                            <p class="text-gray-700 mt-3 leading-relaxed"><?= nl2br(htmlspecialchars($faq['answer'])) ?></p>
                        </details>
                    </div>
            <?php
                endwhile; // Selesai loop
            else:
            ?>
                <!-- Fallback jika tidak ada FAQ di DB -->
                <div class="bg-white p-6 rounded-xl shadow-lg">
                    <p class="text-gray-700">Belum ada pertanyaan umum yang ditambahkan saat ini. Silakan hubungi kami jika Anda memiliki pertanyaan.</p>
                </div>
            <?php
            endif; // Selesai Cek num_rows
            // --- AKHIR KONTEN DINAMIS ---
            ?>

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