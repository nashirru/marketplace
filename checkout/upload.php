<?php
// File: checkout/upload.php
include '../config/config.php';
include '../sistem/sistem.php';
check_login();

define('UPLOAD_DIR_PROOF', '../assets/images/proof/');

// Pastikan direktori ada, jika tidak buat
if (!is_dir(UPLOAD_DIR_PROOF)) {
    mkdir(UPLOAD_DIR_PROOF, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_proof'])) {
    $order_uid = sanitize_input($_POST['order_uid']);
    $user_id = $_SESSION['user_id'];
    
    // --- PERBAIKAN UTAMA ---
    // Memeriksa apakah bukti pembayaran sudah pernah diunggah (NULL) atau belum.
    // Ini lebih andal daripada memeriksa status 'waiting_payment'.
    $stmt_check = $conn->prepare("SELECT id FROM orders WHERE order_uid = ? AND user_id = ? AND payment_proof IS NULL");
    $stmt_check->bind_param("si", $order_uid, $user_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    if ($result_check->num_rows == 0) {
        // Pesan error diperbarui agar lebih jelas
        set_flash_message('error', 'Pesanan tidak valid atau bukti pembayaran sudah pernah diunggah.');
        redirect('/profile/profile.php');
    }
    
    $order = $result_check->fetch_assoc();
    $order_id = $order['id']; // Dapatkan ID numerik untuk nama file

    // Proses upload file
    if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] == 0) {
        $file = $_FILES['payment_proof'];
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($file['type'], $allowed_types)) {
            set_flash_message('error', 'Format file tidak didukung. Harap unggah JPG, PNG, atau GIF.');
            redirect('/checkout/invoice.php?uid=' . $order_uid);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'proof_' . $order_id . '_' . uniqid() . '.' . $extension;
        $target_file = UPLOAD_DIR_PROOF . $filename;

        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            // Update database menggunakan order_uid
            $stmt_update = $conn->prepare("UPDATE orders SET payment_proof = ?, status = 'waiting_approval' WHERE order_uid = ?");
            $stmt_update->bind_param("ss", $filename, $order_uid);
            
            if ($stmt_update->execute()) {
                set_flash_message('success', 'Bukti pembayaran berhasil diunggah. Pesanan Anda akan segera kami proses.');
                redirect('/profile/profile.php');
            } else {
                set_flash_message('error', 'Gagal menyimpan data ke database.');
                redirect('/checkout/invoice.php?uid=' . $order_uid);
            }
        } else {
            set_flash_message('error', 'Gagal mengunggah file.');
            redirect('/checkout/invoice.php?uid=' . $order_uid);
        }

    } else {
        set_flash_message('error', 'Tidak ada file yang diunggah atau terjadi kesalahan.');
        redirect('/checkout/invoice.php?uid=' . $order_uid);
    }
} else {
    redirect('/');
}
?>