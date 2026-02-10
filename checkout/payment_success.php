<?php
// File: checkout/payment_success.php
// Halaman konfirmasi pembayaran sukses

require_once '../config/config.php';
require_once '../sistem/sistem.php'; // Pastikan file sistem.php di-include
require_once '../partial/partial.php';

check_login(); // Pastikan user sudah login

$order_id_encoded = $_GET['order_id'] ?? null;
$order_id = null;
$order_number = null;
$message = "Pembayaran Anda telah berhasil diproses.";

if ($order_id_encoded) {
    // Gunakan fungsi decode_id yang sudah ada di sistem.php
    $order_id = decode_id($order_id_encoded);
    if ($order_id) {
        // Ambil nomor order untuk ditampilkan (opsional)
        $stmt = $conn->prepare("SELECT order_number FROM orders WHERE id = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $order_id, $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $order_number = $row['order_number'];
                $message = "Pembayaran untuk pesanan #" . htmlspecialchars($order_number) . " telah berhasil diproses.";
            }
            $stmt->close();
        } else {
             error_log("Failed to prepare statement to get order number: " . $conn->error);
        }
    } else {
         error_log("Failed to decode order ID: " . $order_id_encoded);
         $message = "Terjadi kesalahan saat memproses ID pesanan Anda.";
    }
} else {
     error_log("Order ID not found in URL for payment_success.php");
     $message = "ID Pesanan tidak ditemukan.";
}

$page_title = 'Pembayaran Berhasil - ' . (get_setting($conn, 'store_name') ?? 'Warok Kite');

// Redirect otomatis ke halaman profil setelah beberapa detik
$redirect_url = get_base_url() . '/profile/profile.php?tab=orders';
$delay = 5; // Detik

?>
<!DOCTYPE html>
<html lang="id">
<?php page_head($page_title, $conn); ?>
<body class="bg-gray-100 font-inter">
    <?php navbar($conn); ?>

    <div class="container mx-auto px-4 py-8 mt-20 min-h-screen">
        <div class="max-w-md mx-auto bg-white rounded-lg shadow-md overflow-hidden p-6 text-center">
            <svg class="w-16 h-16 mx-auto text-green-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <h1 class="text-2xl font-semibold text-gray-800 mb-2">Pembayaran Berhasil!</h1>
            <p class="text-gray-600 mb-6"><?= htmlspecialchars($message) ?></p>
            <p class="text-sm text-gray-500 mb-4">Anda akan diarahkan ke halaman profil dalam <span id="countdown"><?= $delay ?></span> detik...</p>
            <a href="<?= htmlspecialchars($redirect_url) ?>" class="inline-block bg-gradient-to-r from-blue-500 to-teal-400 hover:from-blue-600 hover:to-teal-500 text-white font-semibold py-2 px-4 rounded-lg shadow-md transition duration-300 ease-in-out">
                Lihat Pesanan Saya
            </a>
        </div>
    </div>

    <?php footer($conn); ?>

    <script>
        let seconds = <?= $delay ?>;
        const countdownElement = document.getElementById('countdown');
        const redirectUrl = '<?= htmlspecialchars($redirect_url) ?>';

        const interval = setInterval(() => {
            seconds--;
            if (countdownElement) {
                countdownElement.textContent = seconds;
            }
            if (seconds <= 0) {
                clearInterval(interval);
                window.location.href = redirectUrl;
            }
        }, 1000);
    </script>
</body>
</html>