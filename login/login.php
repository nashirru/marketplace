<?php
// File: login/login.php

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

// Jika pengguna sudah login, arahkan ke halaman yang sesuai
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        redirect('/admin/admin.php');
    }
    redirect('/profile/profile.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitize_input($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = 'Email dan password tidak boleh kosong.';
    } else {
        $stmt = $conn->prepare("SELECT id, name, password, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {
                // Login berhasil
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];

                merge_session_cart_to_db($conn, $user['id']);

                $redirect_url = $_SESSION['redirect_url'] ?? null;
                if ($redirect_url && $user['role'] !== 'admin') {
                    unset($_SESSION['redirect_url']); 
                    // ✅ PERBAIKAN: Hapus BASE_URL dari sini karena $redirect_url sudah berisi path yang benar
                    header("Location: " . $redirect_url);
                    exit;
                }

                if ($user['role'] === 'admin') {
                    redirect('/admin/admin.php');
                } else {
                    redirect('/profile/profile.php');
                }
            } else {
                $error = 'Email atau password salah.';
            }
        } else {
            $error = 'Email atau password salah.';
        }
        $stmt->close();
    }
}
$page_title = "Login";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - <?= get_setting($conn, 'store_name') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md">
        <div class="bg-white shadow-lg rounded-xl p-8">
            <a href="<?= BASE_URL ?>" class="flex justify-center mb-6">
                 <img src="<?= BASE_URL ?>/assets/images/settings/<?= get_setting($conn, 'store_logo') ?>" alt="Logo" class="h-12 w-auto object-contain">
            </a>
            <h2 class="text-2xl font-bold text-center text-gray-800 mb-2">Selamat Datang Kembali</h2>
            <p class="text-center text-gray-500 mb-6">Silakan masuk untuk melanjutkan.</p>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4" role="alert">
                    <span class="block sm:inline"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            
            <?php 
            // Memanggil flash_message() untuk menampilkan notifikasi dari sistem.
            flash_message(); 
            ?>

            <form action="login.php" method="POST" class="space-y-4">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Alamat Email</label>
                    <input type="email" id="email" name="email" required
                           class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                    <input type="password" id="password" name="password" required
                           class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                </div>
                <button type="submit"
                        class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Masuk
                </button>
            </form>
            <p class="mt-6 text-center text-sm text-gray-600">
                Belum punya akun?
                <a href="<?= BASE_URL ?>/register/register.php" class="font-medium text-indigo-600 hover:text-indigo-500">
                    Daftar di sini
                </a>
            </p>
        </div>
    </div>
</body>
</html>