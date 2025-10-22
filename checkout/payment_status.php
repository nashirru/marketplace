<?php
// File: checkout/payment_status.php
// PERBAIKAN: Halaman ini sekarang "pintar" dan akan mengecek status DB

require_once '../config/config.php';
require_once '../sistem/sistem.php'; 
require_once '../partial/partial.php'; 

// Ambil status dari URL query parameter
$status = $_GET['status'] ?? 'pending'; 
$message = $_GET['message'] ?? null; 
$order_id = (int)($_GET['order_id'] ?? 0); // Ambil order_id

$page_title = "Status Pembayaran";
$icon_class = 'fa-clock text-yellow-500';
$title_text = "Pembayaran Tertunda";
$message_text = $message ?? "Pembayaran Anda sedang diproses atau menunggu penyelesaian.";
$bg_color = 'bg-yellow-50';
$border_color = 'border-yellow-400';
$button_text = "Cek Status Pesanan";
$icon_animation = 'animate-spin'; 

// Variabel untuk polling
$enable_polling = false;

switch ($status) {
    case 'success':
        $icon_class = 'fa-spinner text-blue-500'; // Ganti jadi spinner
        $title_text = "Mengonfirmasi Pembayaran...";
        $message_text = "Harap tunggu, kami sedang memverifikasi pembayaran Anda. Jangan tutup halaman ini.";
        $bg_color = 'bg-blue-50';
        $border_color = 'border-blue-400';
        $button_text = "Memverifikasi...";
        $icon_animation = 'animate-spin';
        $enable_polling = ($order_id > 0); // Aktifkan polling jika status sukses dan ada order_id
        break;
    case 'pending':
        // Biarkan seperti default, tapi aktifkan polling
        $title_text = "Pembayaran Tertunda";
        $message_text = $message ?? "Selesaikan pembayaran Anda. Kami akan menunggu konfirmasi.";
        $enable_polling = ($order_id > 0);
        break;
    case 'error':
        $icon_class = 'fa-times-circle text-red-500';
        $title_text = "Pembayaran Gagal";
        $message_text = $message ?? "Maaf, terjadi kesalahan saat memproses pembayaran Anda. Silakan coba lagi.";
        $bg_color = 'bg-red-50';
        $border_color = 'border-red-400';
        $button_text = "Kembali ke Pesanan";
        $icon_animation = 'animate-shake'; 
        break;
}

// URL tujuan redirect
$redirect_url = BASE_URL . '/profile/profile.php?tab=orders';

?>
<!DOCTYPE html>
<html lang="id">
<?php page_head($page_title . ' - ' . get_setting($conn, 'store_name'), $conn); ?>
<style>
    @keyframes bounce {
        0%, 100% { transform: translateY(-10%); animation-timing-function: cubic-bezier(0.8,0,1,1); }
        50% { transform: translateY(0); animation-timing-function: cubic-bezier(0,0,0.2,1); }
    }
    .animate-bounce { animation: bounce 1s infinite; }

    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
        20%, 40%, 60%, 80% { transform: translateX(5px); }
    }
    .animate-shake { animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both; }

    .status-card {
        opacity: 0;
        transform: scale(0.9);
        animation: fadeInScale 0.5s ease-out forwards;
    }
     @keyframes fadeInScale {
        from { opacity: 0; transform: scale(0.9); }
        to { opacity: 1; transform: scale(1); }
    }
    #redirect-countdown { display: none; } /* Sembunyikan countdown default */
</style>
<body class="bg-gray-100 flex flex-col min-h-screen">

    <?php navbar($conn); ?>

    <main class="flex-grow flex items-center justify-center p-4">
        <div class="status-card max-w-md w-full <?= $bg_color ?> rounded-xl shadow-lg border-l-4 <?= $border_color ?> p-6 sm:p-8 text-center" id="status-card">
            <div class="text-6xl mb-5" id="status-icon-container">
                <i class="fas <?= $icon_class ?> <?= $icon_animation ?>" id="status-icon"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-2" id="status-title"><?= htmlspecialchars($title_text) ?></h1>
            <p class="text-gray-600 mb-6" id="status-message"><?= htmlspecialchars($message_text) ?></p>
            <a href="<?= $redirect_url ?>" class="inline-block bg-indigo-600 text-white font-semibold py-2 px-6 rounded-lg hover:bg-indigo-700 transition shadow" id="status-button">
                <?= htmlspecialchars($button_text) ?>
            </a>
            <p class="text-xs text-gray-400 mt-4" id="redirect-countdown">Anda akan diarahkan dalam <span id="countdown">5</span> detik...</p>
        </div>
    </main>

    <?php footer($conn); ?>

    <script>
        const enablePolling = <?= $enable_polling ? 'true' : 'false' ?>;
        const orderId = <?= $order_id ?>;
        const redirectUrl = '<?= $redirect_url ?>';
        const checkStatusUrl = '<?= BASE_URL ?>/checkout/check_payment_status.php';

        let pollingInterval;
        let countdownInterval;
        let attempts = 0;
        const maxAttempts = 15; // Maks 30 detik

        const statusCard = document.getElementById('status-card');
        const iconContainer = document.getElementById('status-icon-container');
        const icon = document.getElementById('status-icon');
        const title = document.getElementById('status-title');
        const message = document.getElementById('status-message');
        const button = document.getElementById('status-button');
        const countdownDisplay = document.getElementById('redirect-countdown');

        function startRedirectCountdown() {
            countdownDisplay.style.display = 'block';
            let seconds = 5;
            countdownInterval = setInterval(() => {
                seconds--;
                document.getElementById('countdown').textContent = seconds;
                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    window.location.href = redirectUrl;
                }
            }, 1000);
        }

        async function checkOrderStatus() {
            if (attempts >= maxAttempts) {
                clearInterval(pollingInterval);
                title.textContent = 'Konfirmasi Tertunda';
                message.textContent = 'Kami masih memproses pesanan Anda. Silakan cek halaman "Pesanan Saya" untuk update terbaru.';
                icon.className = 'fas fa-clock text-yellow-500';
                statusCard.className = statusCard.className.replace(/bg-blue-50 border-blue-400/, 'bg-yellow-50 border-yellow-400');
                button.textContent = 'Lihat Pesanan Saya';
                startRedirectCountdown();
                return;
            }
            
            attempts++;
            
            try {
                const formData = new FormData();
                formData.append('order_id', orderId);
                
                const response = await fetch(checkStatusUrl, { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    // Cek jika status BUKAN lagi 'waiting_payment'
                    if (result.order_status && result.order_status !== 'waiting_payment') {
                        clearInterval(pollingInterval);
                        
                        if (result.order_status === 'belum_dicetak' || result.order_status === 'processed') {
                            // SUKSES
                            title.textContent = 'Pembayaran Berhasil!';
                            message.textContent = 'Terima kasih! Pembayaran Anda telah dikonfirmasi.';
                            icon.className = 'fas fa-check-circle text-green-500 animate-bounce';
                            statusCard.className = statusCard.className.replace(/bg-blue-50 border-blue-400/, 'bg-green-50 border-green-400');
                            button.textContent = 'Lihat Pesanan Saya';
                        } else {
                            // GAGAL (Cancelled, Expire, dll)
                            title.textContent = 'Pembayaran Gagal';
                            message.textContent = 'Pembayaran Anda dibatalkan atau kedaluwarsa.';
                            icon.className = 'fas fa-times-circle text-red-500 animate-shake';
                            statusCard.className = statusCard.className.replace(/bg-blue-50 border-blue-400/, 'bg-red-50 border-red-400');
                            button.textContent = 'Kembali ke Pesanan';
                        }
                        
                        startRedirectCountdown();
                    }
                    // Jika masih 'waiting_payment', biarkan polling berlanjut
                }
            } catch (error) {
                console.error('Polling error:', error);
                // Biarkan polling berlanjut
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (enablePolling && orderId > 0) {
                // Mulai polling 2 detik setelah halaman dimuat
                pollingInterval = setInterval(checkOrderStatus, 2000);
            } else {
                // Jika tidak ada polling (misal status=error), langsung mulai countdown
                startRedirectCountdown();
            }
        });
    </script>

</body>
</html>