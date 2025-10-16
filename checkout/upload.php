<?php
// File: checkout/upload.php
require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

check_login();

define('UPLOAD_DIR_PROOF', '../assets/images/proof/');

// Pastikan direktori ada
if (!is_dir(UPLOAD_DIR_PROOF)) {
    mkdir(UPLOAD_DIR_PROOF, 0777, true);
}

$user_id = $_SESSION['user_id'];
// ✅ PERBAIKAN: Mengambil 'hash' dari URL, bukan 'id'
$order_hash = $_GET['hash'] ?? '';

// Ambil data order berdasarkan HASH
$order_data = null;
$payment_method_details = '';

if (!empty($order_hash)) {
    $stmt = $conn->prepare("
        SELECT o.*, pm.name as payment_method_name, pm.details as payment_details 
        FROM orders o
        LEFT JOIN payment_methods pm ON o.payment_method_id = pm.id
        WHERE o.order_hash = ? AND o.user_id = ?
    ");
    // ✅ PERBAIKAN: Bind parameter menggunakan order_hash
    $stmt->bind_param("si", $order_hash, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $order_data = $result->fetch_assoc();
        $payment_method_details = $order_data['payment_details'] ?? '';
    }
    $stmt->close();
}

// Jika order tidak ditemukan
if (!$order_data) {
    set_flashdata('error', 'Pesanan tidak ditemukan atau Anda tidak memiliki akses.');
    redirect('/profile/profile.php');
}

// Proses upload bukti pembayaran
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_proof'])) {
    
    // Cek apakah bukti pembayaran sudah pernah diunggah
    if (!empty($order_data['payment_proof'])) {
        set_flashdata('error', 'Bukti pembayaran sudah pernah diunggah untuk pesanan ini.');
        redirect('/profile/profile.php');
    }

    // Proses upload file
    if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] == 0) {
        $file = $_FILES['payment_proof'];
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($file['type'], $allowed_types)) {
            set_flashdata('error', 'Format file tidak didukung. Harap unggah JPG, PNG, atau GIF.');
            header("Location: " . BASE_URL . "/checkout/upload.php?hash=" . $order_hash);
            exit;
        }

        // Cek ukuran file (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            set_flashdata('error', 'Ukuran file terlalu besar. Maksimal 5MB.');
            header("Location: " . BASE_URL . "/checkout/upload.php?hash=" . $order_hash);
            exit;
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'proof_' . $order_data['id'] . '_' . time() . '.' . $extension;
        $target_file = UPLOAD_DIR_PROOF . $filename;

        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            // Update database
            $stmt_update = $conn->prepare("
                UPDATE orders 
                SET payment_proof = ?, status = 'waiting_approval' 
                WHERE order_hash = ? AND user_id = ?
            ");
             // ✅ PERBAIKAN: Bind parameter menggunakan order_hash
            $stmt_update->bind_param("ssi", $filename, $order_hash, $user_id);
            
            if ($stmt_update->execute()) {
                set_flashdata('success', 'Bukti pembayaran berhasil diunggah. Pesanan Anda akan segera diproses.');
                redirect('/profile/profile.php');
            } else {
                set_flashdata('error', 'Gagal menyimpan data ke database.');
                header("Location: " . BASE_URL . "/checkout/upload.php?hash=" . $order_hash);
                exit;
            }
        } else {
            set_flashdata('error', 'Gagal mengunggah file. Periksa permission folder.');
            header("Location: " . BASE_URL . "/checkout/upload.php?hash=" . $order_hash);
            exit;
        }

    } else {
        $error_msg = 'Tidak ada file yang diunggah.';
        if (isset($_FILES['payment_proof']['error'])) {
            switch ($_FILES['payment_proof']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $error_msg = 'File terlalu besar.';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error_msg = 'File hanya terupload sebagian.';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error_msg = 'Tidak ada file yang dipilih.';
                    break;
            }
        }
        set_flashdata('error', $error_msg);
        header("Location: " . BASE_URL . "/checkout/upload.php?hash=" . $order_hash);
        exit;
    }
}

// ✅ PERBAIKAN: Mengambil flash data dengan benar
$flash = get_flashdata();
$flash_message = $flash['message'] ?? null;
$flash_type = $flash['type'] ?? '';
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

            <?php if ($flash_message): ?>
                <div class="p-4 mb-6 rounded-lg <?php echo $flash_type === 'error' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'; ?>">
                    <?= htmlspecialchars($flash_message) ?>
                </div>
            <?php endif; ?>

            <!-- Informasi Pembayaran -->
            <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-6 mb-6">
                <h2 class="text-xl font-bold text-indigo-800 mb-4 flex items-center">
                    <i class="fas fa-credit-card mr-2"></i> Informasi Pembayaran
                </h2>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-700 font-medium">Metode Pembayaran:</span>
                        <span class="font-bold text-gray-900"><?= htmlspecialchars($order_data['payment_method_name']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-700 font-medium">Total Pembayaran:</span>
                        <span class="font-bold text-indigo-600 text-2xl"><?= format_rupiah($order_data['total']) ?></span>
                    </div>
                </div>
                
                <?php if (!empty($payment_method_details)): ?>
                    <div class="mt-4 pt-4 border-t border-indigo-200">
                        <h3 class="font-semibold text-gray-800 mb-2">Detail Rekening:</h3>
                        <div class="bg-white p-4 rounded-lg border border-indigo-100">
                            <pre class="text-sm text-gray-700 whitespace-pre-wrap"><?= htmlspecialchars($payment_method_details) ?></pre>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Form Upload Bukti Pembayaran -->
            <div class="bg-white border border-gray-200 rounded-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-upload mr-2 text-indigo-600"></i> Upload Bukti Pembayaran
                </h2>
                
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Pilih Foto Bukti Transfer *
                        </label>
                        <div class="flex items-center justify-center w-full">
                            <label for="payment_proof" class="flex flex-col items-center justify-center w-full h-64 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100 transition">
                                <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3"></i>
                                    <p class="mb-2 text-sm text-gray-500"><span class="font-semibold">Klik untuk upload</span> atau drag and drop</p>
                                    <p class="text-xs text-gray-500">PNG, JPG atau GIF (MAX. 5MB)</p>
                                    <p class="text-xs text-gray-400 mt-2" id="file-name"></p>
                                </div>
                                <input id="payment_proof" name="payment_proof" type="file" class="hidden" accept="image/*" required onchange="showFileName(this)">
                            </label>
                        </div>
                    </div>

                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-yellow-600 mt-1 mr-3"></i>
                            <div class="text-sm text-yellow-800">
                                <p class="font-semibold mb-1">Penting:</p>
                                <ul class="list-disc list-inside space-y-1">
                                    <li>Pastikan foto bukti transfer jelas dan terbaca</li>
                                    <li>Foto harus menampilkan nama pengirim, jumlah, dan tanggal transfer</li>
                                    <li>Pesanan akan diproses setelah pembayaran diverifikasi oleh admin</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-4">
                        <button type="submit" name="upload_proof" class="flex-1 px-6 py-3 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 transition shadow-lg">
                            <i class="fas fa-check mr-2"></i> Upload Bukti Pembayaran
                        </button>
                        <a href="<?= BASE_URL ?>/profile/profile.php" class="px-6 py-3 border border-gray-300 text-gray-700 font-semibold rounded-lg hover:bg-gray-50 transition">
                            Upload Nanti
                        </a>
                    </div>
                </form>
            </div>

            <!-- Informasi Tambahan -->
            <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-sm text-blue-800">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Catatan:</strong> Anda dapat mengupload bukti pembayaran kapan saja melalui halaman 
                    <a href="<?= BASE_URL ?>/profile/profile.php" class="underline font-semibold">Profil Saya > Pesanan Saya</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        function showFileName(input) {
            const fileName = document.getElementById('file-name');
            if (input.files && input.files[0]) {
                fileName.textContent = 'File terpilih: ' + input.files[0].name;
            }
        }
    </script>

    <?php footer($conn); ?>
</body>
</html>