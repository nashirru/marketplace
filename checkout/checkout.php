<?php
// File: checkout/checkout.php
// PERBAIKAN: Mengarahkan onSuccess dan onPending ke halaman payment_status.php

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';
require_once '../midtrans/config_midtrans.php';

check_login();

$user_id = $_SESSION['user_id'];
$addresses = get_user_addresses($conn, $user_id);
$cart = get_cart_items($conn, $user_id);

if (empty($cart['items'])) {
    set_flashdata('info', 'Keranjang Anda kosong. Silakan belanja terlebih dahulu.');
    redirect('/cart/cart.php');
}

$default_address = $cart['default_address'];
$page_title = 'Checkout - ' . (get_setting($conn, 'store_name') ?? 'Warok Kite');
?>

<!DOCTYPE html>
<html lang="id">
<?php page_head($page_title, $conn); ?>
<script type="text/javascript"
        src="https://app.sandbox.midtrans.com/snap/snap.js?v=<?= time() ?>" 
        data-client-key="<?= htmlspecialchars(\Midtrans\Config::$clientKey); ?>"></script>
<style>
#submit-button:disabled { background-color: #9ca3af; cursor: not-allowed; }
</style>
<body class="bg-gray-100">

<div id="loading-overlay" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-75 z-[9999] flex justify-center items-center text-white text-xl transition-opacity duration-300 opacity-0 pointer-events-none">
    <p><i class="fas fa-spinner fa-spin mr-3"></i>Mempersiapkan pembayaran...</p>
</div>

<?php navbar($conn); ?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold text-center mb-8">Checkout</h1>
    <?php flash_message(); ?>

    <!-- Form action mengarah ke V2 untuk bypass cache -->
    <form action="<?= BASE_URL ?>/checkout/checkout_process.php" method="POST" id="checkout-form">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow-md">
                <h2 class="text-xl font-bold mb-4">Alamat Pengiriman</h2>
                <?php if (!empty($addresses)): ?>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2">Gunakan Alamat Tersimpan</label>
                    <select name="existing_address" id="existing_address" class="w-full p-2 border rounded-md">
                        <option value="0">-- Isi Alamat Baru --</option>
                        <?php
                        
                        $has_default = false;
                        foreach ($addresses as $addr) {
                            if ($addr['is_default']) {
                                $has_default = true;
                                break;
                            }
                        }

                        $is_first = true;
                        foreach ($addresses as $addr): 
                            $selected = false;
                            
                            if ($addr['is_default']) {
                                $selected = true;
                            } elseif (!$has_default && $is_first) {
                                $selected = true;
                            }
                        ?>
                        <option value="<?= $addr['id'] ?>" <?= ($selected ? 'selected' : '') ?> data-details='<?= htmlspecialchars(json_encode($addr), ENT_QUOTES, 'UTF-8') ?>'>
                            <?= htmlspecialchars($addr['full_name']) ?> - <?= htmlspecialchars($addr['address_line_1']) ?>, <?= htmlspecialchars($addr['city']) ?>
                        </option>
                        <?php 
                            $is_first = false;
                        endforeach; 
                        ?>
                    </select>
                </div>
                <?php endif; ?>
                <div id="address-form-fields">
                     <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div><label for="full_name" class="block text-sm font-medium text-gray-700 mb-1">Nama Lengkap</label><input type="text" id="full_name" name="full_name" class="w-full p-2 border rounded-md" required></div>
                        <div><label for="phone_number" class="block text-sm font-medium text-gray-700 mb-1">Nomor Telepon</label><input type="tel" id="phone_number" name="phone_number" class="w-full p-2 border rounded-md" required></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                        <div><label for="province" class="block text-sm font-medium text-gray-700 mb-1">Provinsi</label><input type="text" id="province" name="province" class="w-full p-2 border rounded-md" required></div>
                        <div><label for="city" class="block text-sm font-medium text-gray-700 mb-1">Kota/Kabupaten</label><input type="text" id="city" name="city" class="w-full p-2 border rounded-md" required></div>
                         <div><label for="postal_code" class="block text-sm font-medium text-gray-700 mb-1">Kode Pos</label><input type="text" id="postal_code" name="postal_code" class="w-full p-2 border rounded-md"></div>
                    </div>
                     <div class="mb-4"><label for="subdistrict" class="block text-sm font-medium text-gray-700 mb-1">Kecamatan</label><input type="text" id="subdistrict" name="subdistrict" class="w-full p-2 border rounded-md"></div>
                    <div class="mb-4"><label for="address_line_1" class="block text-sm font-medium text-gray-700 mb-1">Alamat Lengkap</label><textarea id="address_line_1" name="address_line_1" rows="3" class="w-full p-2 border rounded-md" placeholder="Nama jalan, nomor rumah, RT/RW" required></textarea></div>
                    <div class="mb-4"><label for="address_line_2" class="block text-sm font-medium text-gray-700 mb-1">Catatan (Opsional)</label><input type="text" id="address_line_2" name="address_line_2" class="w-full p-2 border rounded-md" placeholder="Cth: Blok/unit no., patokan"></div>
                    <div class="flex items-center" id="is-default-container"><input type="checkbox" id="is_default" name="is_default" class="h-4 w-4 text-indigo-600 border-gray-300 rounded"><label for="is_default" class="ml-2 block text-sm text-gray-900">Jadikan alamat utama</label></div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-md h-fit sticky top-8">
                <h2 class="text-xl font-bold mb-4">Ringkasan Pesanan</h2>
                <div class="space-y-4">
                    <?php foreach ($cart['items'] as $item) : ?>
                    <div class="flex items-center space-x-4">
                        <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-16 h-16 object-cover rounded-md">
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($item['name']) ?></p>
                            <p class="text-xs text-gray-500">Qty: <?= $item['quantity'] ?></p>
                        </div>
                        <span class="text-sm font-semibold"><?= format_rupiah($item['subtotal']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="space-y-2 pt-4 border-t mt-4">
                    <div class="flex justify-between text-gray-700"><span>Subtotal Produk</span><span><?= format_rupiah($cart['subtotal']) ?></span></div>
                    <div class="flex justify-between text-gray-700"><span>Ongkir</span><span>Ditanggung pembeli</span></div>
                    <div class="flex justify-between text-xl font-bold text-indigo-800 pt-2 border-t mt-2"><span>TOTAL</span><span><?= format_rupiah($cart['subtotal']) ?></span></div>
                </div>
                <button type="submit" id="submit-button" class="mt-6 w-full px-4 py-3 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 transition">Lanjutkan ke Pembayaran</button>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAddress = document.getElementById('existing_address');
    const formFields = document.getElementById('address-form-fields');
    const defaultAddrData = <?= json_encode($default_address) ?>;
    
    // ============================================================
    // PERBAIKAN: Definisikan URL dasar ini di atas
    // ============================================================
    const paymentStatusUrlBase = '<?= BASE_URL ?>/checkout/payment_status.php';

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
            document.getElementById('is-default-container').style.display = 'none';
            
            const selectedOption = selectAddress.options[selectAddress.selectedIndex];
            if (selectedOption && selectedOption.dataset.details) {
                const details = JSON.parse(selectedOption.dataset.details);
                fillForm(details); 
            }
        } else {
            formFields.style.display = 'block';
            document.getElementById('is-default-container').style.display = 'flex';
            fillForm({});
        }
    }

    if(selectAddress) {
        selectAddress.addEventListener('change', toggleFormFields);
        toggleFormFields(); 
    } else if (defaultAddrData) {
        fillForm(defaultAddrData);
    }

    const checkoutForm = document.getElementById('checkout-form');
    const submitButton = document.getElementById('submit-button');
    const loadingOverlay = document.getElementById('loading-overlay');
    let dbOrderId = null;

    submitButton.addEventListener('click', async function(event) {
        event.preventDefault();

        if (formFields.style.display === 'block') {
             if (!checkoutForm.checkValidity()) {
                checkoutForm.reportValidity();
                return;
            }
        }

        loadingOverlay.classList.remove('opacity-0', 'pointer-events-none');
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Memproses...';

        try {
            const formData = new FormData(checkoutForm);
            const response = await fetch(checkoutForm.action, { method: 'POST', body: formData });
            
            const contentType = response.headers.get("content-type");
            if (!contentType || !contentType.includes("application/json")) {
                const errorText = await response.text();
                throw new Error("Terjadi error server. Response: " + errorText);
            }

            const result = await response.json();

            if (!response.ok) {
                if (result.redirect_to_cart === true) {
                    alert(result.message);
                    window.location.href = '<?= BASE_URL ?>/cart/cart.php';
                } else {
                    throw new Error(result.message || 'Terjadi kesalahan di server.');
                }
                return;
            }

            if (result.success && result.snap_token) {
                dbOrderId = result.db_order_id;
                let paymentHandled = false;

                window.snap.pay(result.snap_token, {
                    onSuccess: function(res){
                        paymentHandled = true;
                        // ============================================================
                        // PERBAIKAN: Arahkan ke poller, bukan profil
                        // ============================================================
                        window.location.href = `${paymentStatusUrlBase}?status=success&order_id=${dbOrderId}&message=${encodeURIComponent('Pembayaran berhasil! Memverifikasi...')}`;
                    },
                    onPending: function(res){
                        paymentHandled = true;
                        // ============================================================
                        // PERBAIKAN: Arahkan ke poller, bukan profil
                        // ============================================================
                        window.location.href = `${paymentStatusUrlBase}?status=pending&order_id=${dbOrderId}&message=${encodeURIComponent('Pembayaran Anda tertunda. Memverifikasi...')}`;
                    },
                    onError: function(res){
                        paymentHandled = true;
                        // ============================================================
                        // PERBAIKAN: Arahkan ke poller, bukan profil
                        // ============================================================
                        window.location.href = `${paymentStatusUrlBase}?status=error&order_id=${dbOrderId}&message=${encodeURIComponent('Pembayaran gagal, silakan coba lagi.')}`;
                    },
                    onClose: function(){
                        if (!paymentHandled) { 
                            loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                            submitButton.disabled = false;
                            submitButton.innerHTML = 'Lanjutkan ke Pembayaran';
                        }
                    }
                });

            } else {
                throw new Error(result.message || 'Gagal mendapatkan token pembayaran.');
            }

        } catch (error) {
            console.error(error);
            alert('Gagal: ' + error.message);
            loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
            submitButton.disabled = false;
            submitButton.innerHTML = 'Lanjutkan ke Pembayaran';
        }
    });
});
</script>

<?php footer($conn); ?>
</body>
</html>