<?php
// File: checkout/checkout.php
// HANYA menampilkan form alamat dan ringkasan pesanan.

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

check_login();

$user_id = $_SESSION['user_id'];

// --- LOGIKA HANYA UNTUK MENAMPILKAN HALAMAN ---
$addresses = get_user_addresses($conn, $user_id);
$cart = get_cart_items($conn, $user_id);

// Pengecekan keranjang dilakukan di sini sebelum halaman ditampilkan
if (empty($cart['items'])) {
    set_flashdata('info', 'Keranjang Anda kosong. Silakan belanja terlebih dahulu.');
    redirect('/cart/cart.php');
}

$subtotal = $cart['subtotal'];
$cart_items = $cart['items'];
$default_address = $cart['default_address'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Checkout - Warok Kíte</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-100">
    <?php top_header($conn); ?>
    <?php navbar($conn); ?>

    <div class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-center mb-8">Checkout</h1>
        <?php display_flash_messages(); ?>
        
        <form action="checkout_process.php" method="POST" id="checkout-form">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Kolom Kiri: Alamat Pengiriman -->
                <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-xl font-bold mb-4">Alamat Pengiriman</h2>
                    
                    <?php if (!empty($addresses)): ?>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2">Gunakan Alamat Tersimpan</label>
                            <select name="existing_address" id="existing_address" class="w-full p-2 border rounded-md">
                                <option value="0">-- Isi Alamat Baru --</option>
                                <?php foreach ($addresses as $addr): ?>
                                    <option value="<?= $addr['id'] ?>" <?= ($addr['is_default'] ? 'selected' : '') ?> data-details='<?= htmlspecialchars(json_encode($addr), ENT_QUOTES, 'UTF-8') ?>'>
                                        <?= htmlspecialchars($addr['full_name']) ?> - <?= htmlspecialchars($addr['address_line_1']) ?>, <?= htmlspecialchars($addr['city']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div id="address-form-fields">
                         <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-700 mb-1">Nama Lengkap</label>
                                <input type="text" id="full_name" name="full_name" class="w-full p-2 border rounded-md" required>
                            </div>
                            <div>
                                <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-1">Nomor Telepon</label>
                                <input type="tel" id="phone_number" name="phone_number" class="w-full p-2 border rounded-md" required>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label for="province" class="block text-sm font-medium text-gray-700 mb-1">Provinsi</label>
                                <input type="text" id="province" name="province" class="w-full p-2 border rounded-md" required>
                            </div>
                            <div>
                                <label for="city" class="block text-sm font-medium text-gray-700 mb-1">Kota/Kabupaten</label>
                                <input type="text" id="city" name="city" class="w-full p-2 border rounded-md" required>
                            </div>
                             <div>
                                <label for="postal_code" class="block text-sm font-medium text-gray-700 mb-1">Kode Pos</label>
                                <input type="text" id="postal_code" name="postal_code" class="w-full p-2 border rounded-md">
                            </div>
                        </div>
                         <div class="mb-4">
                            <label for="subdistrict" class="block text-sm font-medium text-gray-700 mb-1">Kecamatan</label>
                            <input type="text" id="subdistrict" name="subdistrict" class="w-full p-2 border rounded-md">
                        </div>
                        <div class="mb-4">
                            <label for="address_line_1" class="block text-sm font-medium text-gray-700 mb-1">Alamat Lengkap</label>
                            <textarea id="address_line_1" name="address_line_1" rows="3" class="w-full p-2 border rounded-md" placeholder="Nama jalan, nomor rumah, RT/RW" required></textarea>
                        </div>
                        <div class="mb-4">
                            <label for="address_line_2" class="block text-sm font-medium text-gray-700 mb-1">Catatan (Opsional)</label>
                            <input type="text" id="address_line_2" name="address_line_2" class="w-full p-2 border rounded-md" placeholder="Cth: Blok/unit no., patokan">
                        </div>
                        <div class="flex items-center" id="is-default-container">
                            <input type="checkbox" id="is_default" name="is_default" class="h-4 w-4 text-indigo-600 border-gray-300 rounded">
                            <label for="is_default" class="ml-2 block text-sm text-gray-900">Jadikan alamat utama</label>
                        </div>
                    </div>
                </div>

                <!-- Kolom Kanan: Ringkasan Pesanan -->
                <div class="bg-white p-6 rounded-lg shadow-md h-fit">
                    <h2 class="text-xl font-bold mb-4">Ringkasan Pesanan</h2>
                    <div class="space-y-4">
                        <?php foreach ($cart_items as $item) : ?>
                            <div class="flex items-center space-x-4">
                                <img src="/assets/images/produk/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-16 h-16 object-cover rounded-md">
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($item['name']) ?></p>
                                    <p class="text-xs text-gray-500">Qty: <?= $item['quantity'] ?></p>
                                </div>
                                <span class="text-sm font-semibold"><?= format_rupiah($item['subtotal']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="space-y-2 pt-4 border-t mt-4">
                        <div class="flex justify-between text-gray-700"><span>Subtotal Produk</span><span><?= format_rupiah($subtotal) ?></span></div>
                        <div class="flex justify-between text-gray-700"><span>Biaya Pengiriman</span><span>Gratis</span></div>
                        <div class="flex justify-between text-xl font-bold text-indigo-800 pt-2 border-t mt-2"><span>TOTAL</span><span><?= format_rupiah($subtotal) ?></span></div>
                    </div>

                    <button type="submit" class="mt-6 w-full px-4 py-3 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 transition">
                       Lanjutkan ke Pembayaran
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selectAddress = document.getElementById('existing_address');
            const formFields = document.getElementById('address-form-fields');
            
            function fillForm(data) {
                document.getElementById('full_name').value = data.full_name || '';
                document.getElementById('phone_number').value = data.phone_number || '';
                document.getElementById('province').value = data.province || '';
                document.getElementById('city').value = data.city || '';
                document.getElementById('subdistrict').value = data.subdistrict || '';
                document.getElementById('postal_code').value = data.postal_code || '';
                document.getElementById('address_line_1').value = data.address_line_1 || '';
                document.getElementById('address_line_2').value = data.address_line_2 || '';
            }

            function toggleFormFields() {
                if (selectAddress && selectAddress.value !== '0') {
                    formFields.style.display = 'none';
                    const selectedOption = selectAddress.options[selectAddress.selectedIndex];
                    const details = JSON.parse(selectedOption.dataset.details);
                    fillForm(details);
                } else {
                    formFields.style.display = 'block';
                    fillForm({}); 
                }
            }

            if(selectAddress) {
                selectAddress.addEventListener('change', toggleFormFields);
                const selectedOption = selectAddress.options[selectAddress.selectedIndex];
                if (selectedOption && selectedOption.value !== '0') {
                     toggleFormFields();
                } else {
                     const defaultAddr = <?= json_encode($default_address) ?>;
                     if(defaultAddr) fillForm(defaultAddr);
                }
            }
        });
    </script>
    
    <?php footer($conn); ?>
</body>
</html>