<?php
// File: checkout/payment.php
// Halaman ini HANYA untuk menampilkan pop-up pembayaran Midtrans.

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../midtrans/config_midtrans.php';

check_login();

// Ambil snap token dari session
$snapToken = $_SESSION['snap_token'] ?? null;

// Hapus token dari session agar tidak bisa digunakan lagi jika halaman di-refresh
unset($_SESSION['snap_token']);

// Jika tidak ada token, berarti pengguna mengakses halaman ini secara ilegal
if (!$snapToken) {
    set_flashdata('error', 'Sesi pembayaran tidak valid atau telah kedaluwarsa.');
    redirect('/cart/cart.php');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proses Pembayaran</title>
    <!-- Script Midtrans Snap.js -->
    <script type="text/javascript"
            src="https://app.sandbox.midtrans.com/snap/snap.js"
            data-client-key="<?php echo \Midtrans\Config::$clientKey; ?>"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #f0f2f5; margin: 0; }
        .container { text-align: center; padding: 40px; background: white; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        p { color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Mempersiapkan Pembayaran...</h1>
        <p>Jendela pembayaran akan segera muncul. Mohon jangan menutup halaman ini.</p>
    </div>

    <script type="text/javascript">
      document.addEventListener("DOMContentLoaded", function() {
        // [PERBAIKAN] Menggunakan BASE_URL dan parameter '?tab=orders'
        const base_url = '<?= BASE_URL ?>';
        const order_page_url = base_url + '/profile/profile.php?tab=orders';

        // Panggil Snap pop-up secara otomatis
        window.snap.pay('<?php echo $snapToken; ?>', {
          onSuccess: function(result){
            /* Pembayaran sukses, arahkan ke halaman daftar pesanan */
            console.log(result);
            window.location.href = order_page_url + '&status=success';
          },
          onPending: function(result){
            /* Pembayaran pending, arahkan ke halaman daftar pesanan */
            console.log(result);
            window.location.href = order_page_url + '&status=pending';
          },
          onError: function(result){
            /* Pembayaran gagal, beri tahu pengguna */
            console.log(result);
            alert("Pembayaran gagal. Silakan coba lagi dari halaman pesanan Anda.");
            window.location.href = order_page_url;
          },
          onClose: function(){
            /* Pelanggan menutup pop-up, arahkan ke daftar pesanan */
            console.log('customer closed the popup without finishing the payment');
            alert('Anda menutup jendela pembayaran. Anda dapat melanjutkan pembayaran dari halaman "Pesanan Saya".');
            window.location.href = order_page_url;
          }
        });
      });
    </script>
</body>
</html>