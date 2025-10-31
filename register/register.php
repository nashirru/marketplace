<?php
// File: register/register.php
include '../config/config.php';
include '../sistem/sistem.php';

// Fungsi untuk menghasilkan string captcha acak
function generate_captcha_text($length = 5) {
    $characters = 'ABCDEFGHIJKLMNPQRSTUVWXYZ123456789';
    $captcha_text = '';
    for ($i = 0; $i < $length; $i++) {
        $captcha_text .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $captcha_text;
}

// Inisialisasi Captcha di session
if (!isset($_SESSION['captcha_text'])) {
    $_SESSION['captcha_text'] = generate_captcha_text();
}

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = sanitize_input($_POST['name']);
    $email = sanitize_input($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $captcha_input = strtoupper(sanitize_input($_POST['captcha']));

    // 1. Validasi Captcha
    if ($captcha_input !== $_SESSION['captcha_text']) {
        $error = 'Kode Captcha tidak sesuai.';
        // Regenerate captcha baru setelah salah input
        $_SESSION['captcha_text'] = generate_captcha_text();
    } elseif ($password !== $confirm_password) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        $stmt_check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt_check_email->bind_param("s", $email);
        $stmt_check_email->execute();
        $stmt_check_email->store_result();

        if ($stmt_check_email->num_rows > 0) {
            $error = 'Email sudah terdaftar.';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt_insert = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            $stmt_insert->bind_param("sss", $name, $email, $hashed_password);

            if ($stmt_insert->execute()) {
                // Hapus captcha dari session setelah berhasil
                unset($_SESSION['captcha_text']);
                set_flashdata('success', 'Registrasi berhasil! Silakan login.');
                redirect('/login/login.php');
            } else {
                $error = "Registrasi gagal, silakan coba lagi.";
            }
            $stmt_insert->close();
        }
        $stmt_check_email->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .captcha-code {
            background-color: #f3f4f6;
            color: #374151;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 0.3em;
            text-decoration: line-through;
            text-decoration-color: #9ca3af;
            text-decoration-thickness: 2px;
            user-select: none;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="w-full max-w-md bg-white p-8 rounded-lg shadow-md">
        <h2 class="text-2xl font-bold text-center text-gray-800 mb-6">Buat Akun Baru</h2>
        <?php if ($error): ?>
            <div class="p-4 mb-4 text-sm text-red-700 bg-red-100 rounded-lg" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form action="<?= BASE_URL ?>/register/register.php" method="POST">
            <div class="mb-4">
                <label for="name" class="block text-sm font-medium text-gray-700">Nama Lengkap</label>
                <input type="text" id="name" name="name" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>
            <div class="mb-4">
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input type="email" id="email" name="email" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>
            <div class="mb-4">
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" id="password" name="password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>
             <div class="mb-4">
                <label for="confirm_password" class="block text-sm font-medium text-gray-700">Konfirmasi Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>
            
            <!-- Captcha Section -->
            <div class="mb-6">
                <label for="captcha" class="block text-sm font-medium text-gray-700">Verifikasi Kode</label>
                <div class="flex items-center space-x-4 mt-1">
                    <div class="captcha-code">
                        <span><?= htmlspecialchars($_SESSION['captcha_text']) ?></span>
                    </div>
                    <input type="text" id="captcha" name="captcha" autocomplete="off" required class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Masukkan kode">
                </div>
            </div>

            <div>
                <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Daftar
                </button>
            </div>
        </form>
        <p class="mt-6 text-center text-sm text-gray-600">
            Sudah punya akun? <a href="<?= BASE_URL ?>/login/login.php" class="font-medium text-indigo-600 hover:text-indigo-500">Masuk di sini</a>
        </p>
    </div>
</body>
</html>