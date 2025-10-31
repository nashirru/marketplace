<?php
// File: profile/process_actions.php
// Menangani aksi dari halaman profil (update password, manajemen alamat)

require_once '../config/config.php';
require_once '../sistem/sistem.php';

check_login(); // Pastikan user sudah login

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$redirect_tab = 'settings'; // Default redirect jika aksi tidak sesuai

try {
    switch ($action) {
        // --- PENGATURAN AKUN ---
        case 'update_profile':
            $redirect_tab = 'settings';
            $name = sanitize_input($_POST['name'] ?? '');
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            // Update nama
            if (!empty($name)) {
                $stmt_name = $conn->prepare("UPDATE users SET name = ? WHERE id = ?");
                $stmt_name->bind_param("si", $name, $user_id);
                $stmt_name->execute();
                $stmt_name->close();
            } else {
                throw new Exception("Nama tidak boleh kosong.");
            }

            // Update password jika diisi
            if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
                if (empty($current_password)) throw new Exception("Masukkan password saat ini untuk mengubah password.");
                if (empty($new_password)) throw new Exception("Password baru tidak boleh kosong.");
                if (strlen($new_password) < 6) throw new Exception("Password baru minimal 6 karakter.");
                if ($new_password !== $confirm_password) throw new Exception("Konfirmasi password baru tidak cocok.");

                // Verifikasi password saat ini
                $user_data = get_user_by_id($conn, $user_id); // Fungsi ini sudah mengambil hash password
                if (!$user_data || !password_verify($current_password, $user_data['password'])) {
                    throw new Exception("Password saat ini salah.");
                }

                // Hash password baru dan update
                $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt_pass = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt_pass->bind_param("si", $hashed_new_password, $user_id);
                $stmt_pass->execute();
                $stmt_pass->close();

                set_flashdata('success', 'Profil dan password berhasil diperbarui.');
            } else {
                set_flashdata('success', 'Nama profil berhasil diperbarui.');
            }
            break;

        // --- MANAJEMEN ALAMAT ---
        case 'save_address': // Menggabungkan save dan update
            $redirect_tab = 'addresses';
            $address_id = (int)($_POST['address_id'] ?? 0); // Jika 0 berarti tambah baru
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            $address_data = [
                'full_name' => sanitize_input($_POST['full_name'] ?? ''),
                'phone_number' => sanitize_input($_POST['phone_number'] ?? ''),
                'province' => sanitize_input($_POST['province'] ?? ''),
                'city' => sanitize_input($_POST['city'] ?? ''),
                'subdistrict' => sanitize_input($_POST['subdistrict'] ?? ''),
                'postal_code' => sanitize_input($_POST['postal_code'] ?? ''),
                'address_line_1' => sanitize_input($_POST['address_line_1'] ?? ''),
                'address_line_2' => sanitize_input($_POST['address_line_2'] ?? ''),
                'is_default' => $is_default, // Langsung gunakan nilai dari checkbox
            ];

            // Validasi dasar
            if (empty($address_data['full_name']) || empty($address_data['phone_number']) || empty($address_data['province']) || empty($address_data['city']) || empty($address_data['address_line_1'])) {
                throw new Exception("Harap isi semua field alamat yang wajib (*).");
            }

            // Panggil fungsi simpan/update alamat dari sistem.php
            $saved_id = save_user_address($conn, $user_id, $address_data, $address_id);

            if ($saved_id) {
                set_flashdata('success', 'Alamat berhasil disimpan.');
            } else {
                throw new Exception("Gagal menyimpan alamat.");
            }
            break;

        case 'delete_address':
            $redirect_tab = 'addresses';
            $address_id_to_delete = (int)($_POST['address_id'] ?? 0);
            if ($address_id_to_delete <= 0) throw new Exception("ID Alamat tidak valid.");

            // Hapus alamat (pastikan hanya bisa hapus milik sendiri)
            $stmt = $conn->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $address_id_to_delete, $user_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                set_flashdata('success', 'Alamat berhasil dihapus.');
            } else {
                set_flashdata('warning', 'Alamat tidak ditemukan atau gagal dihapus.');
            }
            $stmt->close();
            break;

        case 'set_default_address':
            $redirect_tab = 'addresses';
            $address_id_to_set = (int)($_POST['address_id'] ?? 0);
            if ($address_id_to_set <= 0) throw new Exception("ID Alamat tidak valid.");

            // Panggil fungsi set default dari sistem.php
            if (set_default_user_address($conn, $user_id, $address_id_to_set)) {
                set_flashdata('success', 'Alamat utama berhasil diubah.');
            } else {
                throw new Exception("Gagal mengatur alamat utama.");
            }
            break;

        default:
            set_flashdata('error', 'Aksi tidak dikenal.');
            break;
    }

} catch (Exception $e) {
    // Tangkap error dan tampilkan sebagai flash message
    set_flashdata('error', 'Terjadi kesalahan: ' . $e->getMessage());
}

// Redirect kembali ke tab yang sesuai di halaman profil
redirect('/profile/profile.php?tab=' . $redirect_tab);