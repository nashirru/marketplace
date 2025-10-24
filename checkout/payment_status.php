<?php
// File: checkout/payment_status.php
// PERBAIKAN: Memperbaiki logika JavaScript agar polling berjalan
// baik untuk status 'success' maupun 'pending'.

// 1. Mulai sesi dan muat file yang diperlukan
require_once '../config/config.php';
require_once '../sistem/sistem.php'; // (set_flashdata ada di sini)
require_once '../partial/partial.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 2. Ambil data dari URL
$status = sanitize_input($_GET['status'] ?? 'pending');
$message = sanitize_input($_GET['message'] ?? 'Memproses pesanan Anda.');
$order_id_encoded = sanitize_input($_GET['order_id'] ?? '');

$order_id = (int)$order_id_encoded;
if ($order_id === 0) {
    // Jika tidak ada order_id, langsung arahkan ke profil
    set_flashdata('error', 'ID Pesanan tidak valid.');
    redirect('/profile/profile.php?tab=orders');
}

// 3. Atur notifikasi (flashdata) yang akan muncul di halaman profil
if ($status === 'success') {
    set_flashdata('success', $message);
} elseif ($status === 'pending') {
    set_flashdata('info', $message);
} elseif ($status === 'error') {
    set_flashdata('error', $message);
}

// Ambil nomor order untuk ditampilkan
$order_number = get_order_number_by_id($conn, $order_id);
$page_title = "Status Pembayaran";
?>

<!DOCTYPE html>
<html lang="id">
<?php page_head($page_title . ' - ' . (get_setting($conn, 'store_name') ?? 'Warok Kite'), $conn); ?>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">

    <div class="bg-white p-8 rounded-lg shadow-xl text-center max-w-md w-full">
        <div id="loading-spinner">
            <i class="fas fa-spinner fa-spin text-indigo-600 text-6xl mb-6"></i>
            <h1 class="text-2xl font-bold text-gray-800 mb-2">Verifikasi Pembayaran...</h1>
            <p class="text-gray-600">
                Kami sedang mengkonfirmasi pembayaran Anda untuk pesanan 
                <strong class="text-indigo-700">#<?= htmlspecialchars($order_number ?? $order_id) ?></strong>.
            </p>
            <p class="text-gray-500 text-sm mt-4">Mohon jangan tutup halaman ini. Anda akan diarahkan secara otomatis.</p>
        </div>
        
        <div id="success-message" class="hidden">
             <i class="fas fa-check-circle text-green-500 text-6xl mb-6"></i>
             <h1 class="text-2xl font-bold text-gray-800 mb-2">Verifikasi Berhasil!</h1>
             <p class="text-gray-600">Mengarahkan Anda ke halaman profil...</p>
        </div>

         <div id="fail-message" class="hidden">
             <i class="fas fa-times-circle text-red-500 text-6xl mb-6"></i>
             <h1 class="text-2xl font-bold text-gray-800 mb-2">Verifikasi Gagal</h1>
             <p class="text-gray-600">Status pesanan Anda belum berubah. Mengarahkan Anda ke halaman profil...</p>
         </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const orderId = <?= $order_id ?>;
    // Status awal dari Midtrans (success, pending, error)
    const initialStatus = '<?= htmlspecialchars($status) ?>'; 
    const profileUrl = '<?= BASE_URL ?>/profile/profile.php?tab=orders';
    
    const loadingSpinner = document.getElementById('loading-spinner');
    const successMessage = document.getElementById('success-message');
    const failMessage = document.getElementById('fail-message');

    let attempts = 0;
    const maxAttempts = 15; // Batas polling (15 * 2 detik = 30 detik)

    function pollStatus() {
        attempts++;
        if (attempts > maxAttempts) {
            // Gagal setelah 30 detik, tampilkan pesan gagal & redirect
            loadingSpinner.classList.add('hidden');
            failMessage.classList.remove('hidden');
            setTimeout(() => { window.location.href = profileUrl; }, 2000);
            return;
        }

        const formData = new FormData();
        formData.append('order_id', orderId);

        fetch('<?= BASE_URL ?>/checkout/check_payment_status.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // dbStatus adalah status DARI DATABASE LOKAL ANDA
                // (hasil dari check_payment_status.php)
                const dbStatus = data.order_status;

                // KASUS 1: Pembayaran SUKSES
                // (Status di DB sudah 'belum_dicetak' ATAU 'processed' dll)
                if (dbStatus !== 'waiting_payment' && dbStatus !== 'cancelled' && dbStatus !== 'error') {
                    // BERHASIL! Tampilkan pesan sukses & redirect
                    loadingSpinner.classList.add('hidden');
                    successMessage.classList.remove('hidden');
                    setTimeout(() => { window.location.href = profileUrl; }, 1500);
                } 
                // KASUS 2: Pembayaran GAGAL
                else if (dbStatus === 'cancelled' || dbStatus === 'error') {
                    // GAGAL. Tampilkan pesan gagal & redirect
                    loadingSpinner.classList.add('hidden');
                    failMessage.classList.remove('hidden');
                    setTimeout(() => { window.location.href = profileUrl; }, 2000);
                }
                // KASUS 3: Pembayaran MASIH PENDING
                // (Status di DB masih 'waiting_payment')
                else {
                    // Belum terupdate, polling lagi
                    setTimeout(pollStatus, 2000); 
                }
            } else {
                // Gagal fetch (error 500 dll), coba lagi
                setTimeout(pollStatus, 2000);
            }
        })
        .catch(error => {
            console.error('Error polling:', error);
            setTimeout(pollStatus, 2000);
        });
    }

    // ============================================================
    // PERBAIKAN LOGIKA:
    // ============================================================
    if (initialStatus === 'error') {
        // Jika status awal sudah error, tidak perlu polling, langsung redirect
        window.location.href = profileUrl;
    } else {
        // Jika status awal 'success' ATAU 'pending', MULAI POLLING
        pollStatus();
    }
});
</script>

</body>
</html>