<?php
// File: login/login.php
include '../config/config.php';
include '../sistem/sistem.php';

// Jika sudah login, redirect ke homepage
if (isset($_SESSION['user_id'])) {
    redirect('/');
    exit;
}

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = sanitize_input($_POST['email']);
    $password = sanitize_input($_POST['password']);

    if (empty($email) || empty($password)) {
        $error_message = 'Email dan password tidak boleh kosong.';
    } else {
        $stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];

                // --- Logika penggabungan keranjang (Cart Merging) ---
                if (!empty($_SESSION['cart'])) {
                    $user_id = $user['id'];
                    foreach ($_SESSION['cart'] as $product_id => $quantity) {
                        $stock_res = $conn->query("SELECT stock FROM products WHERE id = $product_id");
                        $stock = ($stock_res->num_rows > 0) ? $stock_res->fetch_assoc()['stock'] : 0;

                        if ($stock > 0) {
                            $stmt_check = $conn->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
                            $stmt_check->bind_param("ii", $user_id, $product_id);
                            $stmt_check->execute();
                            $res_check = $stmt_check->get_result();

                            if ($res_check->num_rows > 0) {
                                $existing = $res_check->fetch_assoc();
                                $new_quantity = $existing['quantity'] + $quantity;
                                $new_quantity = ($new_quantity > $stock) ? $stock : $new_quantity;
                                $stmt_update = $conn->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
                                $stmt_update->bind_param("iii", $new_quantity, $user_id, $product_id);
                                $stmt_update->execute();
                            } else {
                                $new_quantity = ($quantity > $stock) ? $stock : $quantity;
                                $stmt_insert = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
                                
                                // --- PERBAIKAN UTAMA: Urutan variabel disesuaikan dengan query SQL ---
                                // Query:    user_id, product_id, quantity
                                // SEBELUM:  $user_id, $new_quantity, $product_id (SALAH)
                                // SESUDAH:  $user_id, $product_id, $new_quantity (BENAR)
                                $stmt_insert->bind_param("iii", $user_id, $product_id, $new_quantity);
                                
                                $stmt_insert->execute(); // Ini adalah baris 58 yang menyebabkan error
                            }
                        }
                    }
                    unset($_SESSION['cart']); // Hapus keranjang guest
                }

                // --- Logika redirect cerdas ---
                if (isset($_SESSION['redirect_to'])) {
                    $redirect_url = $_SESSION['redirect_to'];
                    unset($_SESSION['redirect_to']);
                    $path_to_redirect = str_replace(BASE_URL, '', $redirect_url);
                    redirect($path_to_redirect);
                } elseif ($user['role'] == 'admin') {
                    redirect('/admin/admin.php');
                } else {
                    redirect('/');
                }
                exit;

            } else {
                $error_message = 'Email atau password salah.';
            }
        } else {
            $error_message = 'Email atau password salah.';
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen">

    <div class="w-full max-w-md bg-white p-8 rounded-lg shadow-md">
        <h1 class="text-2xl font-bold text-center text-gray-800 mb-2">Selamat Datang Kembali</h1>
        <p class="text-center text-gray-600 mb-6">Silakan login untuk melanjutkan.</p>

        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded-md mb-4 text-sm"><?= $error_message ?></div>
        <?php endif; ?>
        
        <?= flash_message('info') ?>

        <form action="" method="POST">
            <div class="mb-4">
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input type="email" id="email" name="email" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500">
            </div>
            <div class="mb-6">
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" id="password" name="password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500">
            </div>
            <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded-md hover:bg-indigo-700">Login</button>
        </form>
        <p class="text-center text-sm text-gray-600 mt-4">
            Belum punya akun? <a href="<?= BASE_URL ?>/register/register.php" class="font-medium text-indigo-600 hover:text-indigo-500">Daftar di sini</a>
        </p>
    </div>

</body>
</html>