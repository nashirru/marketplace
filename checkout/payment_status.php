<?php
// File: checkout/payment_status.php
// FULL SCRIPT DENGAN ANIMASI CSS

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

<!-- 
============================================================
STYLE BARU DENGAN ANIMASI (CSS)
============================================================
-->
<style>
    /* Keyframes untuk animasi ikon "Pop In" (Success) */
    @keyframes popIn {
        0% {
            transform: scale(0.7);
            opacity: 0;
        }
        60% {
            transform: scale(1.1);
            opacity: 1;
        }
        100% {
            transform: scale(1);
            opacity: 1;
        }
    }

    /* Keyframes untuk animasi ikon "Shake" (Fail/Error) */
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-8px); }
        20%, 40%, 60%, 80% { transform: translateX(8px); }
    }

    /* Keyframes untuk "Glow" di sekitar ikon */
    @keyframes pulseGlow {
        0% {
            box-shadow: 0 0 0 0 rgba(var(--glow-color), 0.4);
        }
        70% {
            box-shadow: 0 0 0 25px rgba(var(--glow-color), 0);
        }
        100% {
            box-shadow: 0 0 0 0 rgba(var(--glow-color), 0);
        }
    }

    /* Wrapper untuk ikon agar bisa diberi 'glow' */
    .icon-container {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-left: auto;
        margin-right: auto;
        margin-bottom: 1.5rem; /* mb-6 */
        /* Terapkan animasi glow */
        animation: pulseGlow 1.8s infinite cubic-bezier(0.66, 0, 0, 1);
    }

    /*
     * Terapkan animasi ke setiap status
     */

    /* 1. Status Loading / Pending */
    #loading-spinner .icon-container {
        --glow-color: 99, 102, 241; /* text-indigo-600 */
        background-color: rgba(var(--glow-color), 0.1);
    }
    #loading-spinner .animate-text {
        /* Animasi pulse bawaan Tailwind */
        animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }
    @keyframes pulse { 50% { opacity: .5; } }


    /* 2. Status Success */
    #success-message .icon-container {
        --glow-color: 34, 197, 94; /* text-green-500 */
        background-color: rgba(var(--glow-color), 0.1);
    }
    #success-message i {
        /* Terapkan animasi popIn */
        animation: popIn 0.5s ease-out forwards;
    }

    /* 3. Status Fail / Unfinish (Timeout) */
    #fail-message .icon-container {
        --glow-color: 239, 68, 68; /* text-red-500 */
        background-color: rgba(var(--glow-color), 0.1);
    }
    #fail-message i {
        /* Terapkan animasi shake */
        animation: shake 0.6s ease-out forwards;
    }
</style>

<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">

    <!-- Card utama dibuat lebih premium dengan shadow-2xl -->
    <div class="bg-white p-8 sm:p-12 rounded-2xl shadow-2xl text-center max-w-md w-full overflow-hidden">
        
        <!-- Status: Loading / Pending (Unfinish) -->
        <div id="loading-spinner">
            <div class="icon-container">
                <i class="fas fa-spinner fa-spin text-indigo-600 text-5xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-2">Verifikasi Pembayaran...</h1>
            <p class="text-gray-600 animate-text">
                Kami sedang mengkonfirmasi pembayaran Anda untuk pesanan 
                <strong class="text-indigo-700">#<?= htmlspecialchars($order_number ?? $order_id) ?></strong>.
            </p>
            <p class="text-gray-500 text-sm mt-4 animate-text">Mohon jangan tutup halaman ini.</p>
        </div>
        
        <!-- Status: Success (Muncul saat polling berhasil) -->
        <div id="success-message" class="hidden">
             <div class="icon-container">
                <i class="fas fa-check-circle text-green-500 text-6xl"></i>
             </div>
             <h1 class="text-2xl font-bold text-gray-800 mb-2">Pembayaran Berhasil!</h1>
             <p class="text-gray-600">Terima kasih. Anda akan diarahkan ke halaman pesanan...</p>
        </div>

        <!-- Status: Fail / Unfinish (Muncul saat polling timeout) -->
         <div id="fail-message" class="hidden">
            <div class="icon-container">
                <i class="fas fa-times-circle text-red-500 text-6xl"></i>
            </div>
             <h1 class="text-2xl font-bold text-gray-800 mb-2">Verifikasi Gagal</h1>
             <p class="text-gray-600">Status pesanan Anda belum berubah. Mengarahkan Anda kembali ke halaman pesanan...</p>
         </div>
    </div>

<!-- 
============================================================
LOGIKA JAVASCRIPT (TIDAK DIUBAH)
Sistem polling ini tidak diubah, hanya tampilan CSS di atasnya.
============================================================
-->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const orderId = <?= $order_id ?>;
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
            setTimeout(() => { window.location.href = profileUrl; }, 2500); // Beri waktu user membaca
            return;
        }

        const formData = new FormData();
        formData.append('order_id', orderId);

        // Tambahkan cache buster untuk memastikan data baru
        const cacheBuster = new Date().getTime();
        fetch('<?= BASE_URL ?>/checkout/check_payment_status.php?v=' + cacheBuster, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const dbStatus = data.order_status;

                // KASUS 1: Pembayaran SUKSES
                if (dbStatus !== 'waiting_payment' && dbStatus !== 'cancelled' && dbStatus !== 'error') {
                    // BERHASIL! Tampilkan pesan sukses & redirect
                    loadingSpinner.classList.add('hidden');
                    failMessage.classList.add('hidden'); // Sembunyikan fail (jika ada)
                    successMessage.classList.remove('hidden');
                    setTimeout(() => { window.location.href = profileUrl; }, 2000); // Beri waktu user membaca
                } 
                // KASUS 2: Pembayaran GAGAL
                else if (dbStatus === 'cancelled' || dbStatus === 'error') {
                    // GAGAL. Tampilkan pesan gagal & redirect
                    loadingSpinner.classList.add('hidden');
                    successMessage.classList.add('hidden'); // Sembunyikan success (jika ada)
                    failMessage.classList.remove('hidden');
                    setTimeout(() => { window.location.href = profileUrl; }, 2500);
                }
                // KASUS 3: Pembayaran MASIH PENDING
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

    // Logika utama saat halaman dimuat
    if (initialStatus === 'error') {
        // Jika status awal sudah error (misal dari Midtrans),
        // tampilkan pesan Gagal, lalu redirect
        loadingSpinner.classList.add('hidden');
        failMessage.classList.remove('hidden');
        setTimeout(() => { window.location.href = profileUrl; }, 2500);
    } else {
        // Jika status awal 'success' ATAU 'pending', MULAI POLLING
        // Keduanya akan ditangani oleh pollStatus
        pollStatus();
    }
});
</script>

</body>
</html>