<?php
// File: checkout/upload.php
require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

// Wajib login untuk akses halaman ini
check_login();

define('UPLOAD_DIR_PROOF', '../assets/images/proof/');

// Pastikan direktori ada
if (!is_dir(UPLOAD_DIR_PROOF)) {
    mkdir(UPLOAD_DIR_PROOF, 0777, true);
}

$user_id = $_SESSION['user_id'];
$order_hash = $_GET['hash'] ?? '';

// Ambil data order berdasarkan HASH dan USER_ID yang sedang login
$order_data = null;
if (!empty($order_hash)) {
    $stmt = $conn->prepare("
        SELECT o.*, pm.name as payment_method_name, pm.details as payment_details 
        FROM orders o
        LEFT JOIN payment_methods pm ON o.payment_method_id = pm.id
        WHERE o.order_hash = ? AND o.user_id = ?
    ");
    $stmt->bind_param("si", $order_hash, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $order_data = $result->fetch_assoc();
    }
    $stmt->close();
}

// Jika order tidak ditemukan atau hash kosong, redirect dengan pesan error
if (!$order_data) {
    set_flashdata('error', 'Pesanan tidak ditemukan atau Anda tidak memiliki akses ke halaman ini.');
    redirect('/profile/profile.php');
}

// Proses upload bukti pembayaran
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_proof'])) {
    
    // Cek lagi apakah user yang post adalah pemilik order
    if ($order_data['user_id'] != $user_id) {
        set_flashdata('error', 'Akses tidak sah.');
        redirect('/profile/profile.php');
    }
    
    if (!empty($order_data['payment_proof'])) {
        set_flashdata('error', 'Bukti pembayaran sudah pernah diunggah.');
        redirect("/checkout/upload.php?hash=" . $order_hash);
    }

    if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] == 0) {
        $file = $_FILES['payment_proof'];
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed_types)) {
            set_flashdata('error', 'Format file tidak didukung (hanya JPG, PNG, GIF, WEBP).');
            redirect("/checkout/upload.php?hash=" . $order_hash);
        }

        if ($file['size'] > 5 * 1024 * 1024) { // Max 5MB
            set_flashdata('error', 'Ukuran file terlalu besar (Maksimal 5MB).');
            redirect("/checkout/upload.php?hash=" . $order_hash);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'proof_' . $order_data['id'] . '_' . time() . '.' . $extension;
        $target_file = UPLOAD_DIR_PROOF . $filename;

        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            $stmt_update = $conn->prepare("UPDATE orders SET payment_proof = ?, status = 'waiting_approval' WHERE id = ? AND user_id = ?");
            $stmt_update->bind_param("sii", $filename, $order_data['id'], $user_id);
            
            if ($stmt_update->execute()) {
                set_flashdata('success', 'Bukti pembayaran berhasil diunggah. Pesanan Anda akan segera kami proses.');
                redirect('/profile/profile.php');
            } else {
                set_flashdata('error', 'Gagal menyimpan data ke database.');
            }
        } else {
            set_flashdata('error', 'Gagal mengunggah file.');
        }
    } else {
        set_flashdata('error', 'Tidak ada file yang dipilih atau terjadi kesalahan saat upload.');
    }
    redirect("/checkout/upload.php?hash=" . $order_hash);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Bukti Pembayaran - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php navbar($conn); ?>

    <div class="container mx-auto p-4 md:p-8 max-w-3xl">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <div class="text-center mb-6">
                <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
                <h1 class="text-3xl font-bold text-gray-800">Pesanan Berhasil Dibuat!</h1>
                <p class="text-gray-600 mt-2">Nomor Pesanan: <span class="font-bold text-indigo-600"><?= htmlspecialchars($order_data['order_number']) ?></span></p>
            </div>

            <?php flash_message(); ?>

            <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-6 mb-6">
                <h2 class="text-xl font-bold text-indigo-800 mb-4 flex items-center"><i class="fas fa-credit-card mr-2"></i> Informasi Pembayaran</h2>
                <div class="space-y-3">
                    <div class="flex justify-between"><span class="text-gray-700 font-medium">Total Pembayaran:</span><span class="font-bold text-indigo-600 text-2xl"><?= format_rupiah($order_data['total']) ?></span></div>
                </div>
                <?php if (!empty($order_data['payment_details'])): ?>
                    <div class="mt-4 pt-4 border-t border-indigo-200">
                        <h3 class="font-semibold text-gray-800 mb-2">Silakan transfer ke rekening berikut:</h3>
                        <div class="bg-white p-4 rounded-lg border border-indigo-100"><pre class="text-sm text-gray-700 whitespace-pre-wrap font-sans"><?= htmlspecialchars($order_data['payment_details']) ?></pre></div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($order_data['payment_proof'])): ?>
            <div class="bg-white border border-gray-200 rounded-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center"><i class="fas fa-upload mr-2 text-indigo-600"></i> Upload Bukti Pembayaran</h2>
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Pilih Foto Bukti Transfer *</label>
                        <label for="payment_proof" class="flex flex-col items-center justify-center w-full h-64 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100 transition">
                            <div class="flex flex-col items-center justify-center pt-5 pb-6 text-center">
                                <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3"></i>
                                <p class="mb-2 text-sm text-gray-500"><span class="font-semibold">Klik untuk upload</span></p>
                                <p class="text-xs text-gray-500">PNG, JPG, GIF, WEBP (MAX. 5MB)</p>
                                <p class="text-xs text-gray-400 mt-2" id="file-name"></p>
                            </div>
                            <input id="payment_proof" name="payment_proof" type="file" class="hidden" accept="image/*" required onchange="showFileName(this)">
                        </label>
                    </div>
                    <div class="flex gap-4">
                        <button type="submit" name="upload_proof" class="flex-1 px-6 py-3 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 transition shadow-lg"><i class="fas fa-check mr-2"></i> Konfirmasi Pembayaran</button>
                        <a href="<?= BASE_URL ?>/profile/profile.php" class="px-6 py-3 border border-gray-300 text-gray-700 font-semibold rounded-lg hover:bg-gray-50 transition">Upload Nanti</a>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-6 text-center">
                <i class="fas fa-shield-check text-4xl mb-3"></i>
                <h2 class="text-xl font-bold">Terima Kasih!</h2>
                <p class="mt-2">Bukti pembayaran Anda sudah kami terima dan akan segera diverifikasi oleh admin.</p>
                <a href="<?= BASE_URL ?>/profile/profile.php" class="mt-4 inline-block font-semibold underline">Kembali ke Profil</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function showFileName(input) {
            const fileNameEl = document.getElementById('file-name');
            if (input.files && input.files[0]) {
                fileNameEl.textContent = 'File: ' + input.files[0].name;
            } else {
                fileNameEl.textContent = '';
            }
        }
    </script>

    <?php footer($conn); ?>
</body>
</html>