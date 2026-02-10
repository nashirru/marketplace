<?php
// File: cart/cart.php
// VERSI FIXED V4: Full Support Persistence Variasi & Multi-Layer Check
// Programmer: Gemini (IQ 180)

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

$user_id = $_SESSION['user_id'] ?? 0;

// =================================================================
// 1. FUNGSI LOKAL PENGGANTI (Agar support variasi tanpa ubah sistem.php)
// =================================================================
function get_cart_items_with_variation($conn, $user_id) {
    if ($user_id > 0) {
        // UPDATE SQL: Join ke Variation table secara explicit
        // Pastikan kolom variation_id ada di tabel cart
        $sql = "SELECT c.*, 
                       p.name, p.image, p.price AS base_price, p.stock AS base_stock, p.stock_cycle_id, p.purchase_limit,
                       pv.name AS variation_name, pv.price AS variation_price, pv.stock AS variation_stock, pv.image AS variation_image
                FROM cart c
                JOIN products p ON c.product_id = p.id
                LEFT JOIN product_variations pv ON c.variation_id = pv.id
                WHERE c.user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        // Fallback untuk Guest (Session)
        $items = [];
        if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
            $ids = array_keys($_SESSION['cart']);
            if (empty($ids)) return [];
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT id as product_id, name, image, price as base_price, stock as base_stock, purchase_limit, stock_cycle_id FROM products WHERE id IN ($placeholders)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmt->execute();
            $res = $stmt->get_result();
            
            $products_map = [];
            while ($row = $res->fetch_assoc()) {
                $products_map[$row['product_id']] = $row;
            }

            foreach ($_SESSION['cart'] as $pid => $sess_item) {
                if (isset($products_map[$pid])) {
                    $row = $products_map[$pid];
                    $row['quantity'] = $sess_item['quantity'];
                    $row['variation_id'] = $sess_item['variation_id'] ?? null;
                    
                    // Logic Fetch Variation details untuk guest
                    $row['variation_name'] = null; 
                    if ($row['variation_id']) {
                        $v_sql = "SELECT name, price, stock, image FROM product_variations WHERE id = ?";
                        $v_stmt = $conn->prepare($v_sql);
                        $v_stmt->bind_param("i", $row['variation_id']);
                        $v_stmt->execute();
                        $v_res = $v_stmt->get_result()->fetch_assoc();
                        $v_stmt->close();
                        
                        if ($v_res) {
                            $row['variation_name'] = $v_res['name'];
                            $row['variation_price'] = $v_res['price'];
                            $row['variation_stock'] = $v_res['stock'];
                            $row['variation_image'] = $v_res['image'];
                        }
                    }
                    
                    $items[] = $row;
                }
            }
        }
    }

    // Normalisasi Harga & Stok & IMAGE (Variasi vs Produk Utama)
    foreach ($items as &$item) {
        if (!empty($item['variation_id']) && !empty($item['variation_name'])) {
            $item['price'] = $item['variation_price'];
            $item['stock'] = $item['variation_stock'];
            
            // LOGIKA GAMBAR VARIASI: Jika ada gambar variasi, pakai itu. Jika tidak, pakai gambar utama.
            if (!empty($item['variation_image'])) {
                $item['image'] = $item['variation_image'];
            }
            
            $item['is_variation'] = true;
        } else {
            $item['price'] = $item['base_price'];
            $item['stock'] = $item['base_stock'];
            $item['is_variation'] = false;
        }
    }
    return $items;
}

// ============================================================
// 2. VALIDASI OTOMATIS SAAT LOAD KERANJANG
// ============================================================
$cart_items_raw = get_cart_items_with_variation($conn, $user_id);
$cart_was_updated = false;
$update_messages = [];

foreach ($cart_items_raw as $item) {
    $current_qty_in_cart = $item['quantity'];
    $max_allowed_in_cart = (int)$item['stock']; // Ini sudah otomatis stok variasi atau produk utama
    $purchase_limit = (int)($item['purchase_limit'] ?? 0); 

    if ($purchase_limit > 0) {
        if ($user_id > 0) {
            $already_bought = get_user_purchase_count($conn, $user_id, $item['product_id'], $item['stock_cycle_id']);
            $pending_bought = get_user_pending_purchase_count($conn, $user_id, $item['product_id'], $item['stock_cycle_id']);
            
            $total_committed = $already_bought + $pending_bought;
            $remaining_quota = max(0, $purchase_limit - $total_committed);
            
            $max_allowed_in_cart = min($max_allowed_in_cart, $remaining_quota);
        } else {
            $max_allowed_in_cart = min($max_allowed_in_cart, $purchase_limit);
        }
    }

    if ($current_qty_in_cart > $max_allowed_in_cart) {
        $cart_was_updated = true;
        $var_label = !empty($item['variation_name']) ? " (" . $item['variation_name'] . ")" : "";

        if ($max_allowed_in_cart > 0) {
            $update_messages[] = "Jumlah '" . htmlspecialchars($item['name']) . $var_label . "' disesuaikan menjadi $max_allowed_in_cart karena melebihi stok/kuota.";
            
            if ($user_id > 0) {
                $sql_update = "UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?";
                if (!empty($item['variation_id'])) {
                    $sql_update .= " AND variation_id = " . (int)$item['variation_id'];
                } else {
                    $sql_update .= " AND (variation_id IS NULL OR variation_id = 0)";
                }
                
                $stmt = $conn->prepare($sql_update);
                $stmt->bind_param("iii", $max_allowed_in_cart, $user_id, $item['product_id']);
                $stmt->execute();
                $stmt->close();
            } else {
                $_SESSION['cart'][$item['product_id']]['quantity'] = $max_allowed_in_cart;
            }
        } else {
            $update_messages[] = "'" . htmlspecialchars($item['name']) . $var_label . "' dihapus (Stok habis/Limit tercapai).";
            
            if ($user_id > 0) {
                $sql_del = "DELETE FROM cart WHERE user_id = ? AND product_id = ?";
                if (!empty($item['variation_id'])) {
                    $sql_del .= " AND variation_id = " . (int)$item['variation_id'];
                } else {
                    $sql_del .= " AND (variation_id IS NULL OR variation_id = 0)";
                }
                $stmt = $conn->prepare($sql_del);
                $stmt->bind_param("ii", $user_id, $item['product_id']);
                $stmt->execute();
                $stmt->close();
            } else {
                unset($_SESSION['cart'][$item['product_id']]);
            }
        }
    }
}

if ($cart_was_updated) {
    set_flashdata('info', implode('<br>', $update_messages));
    redirect('/cart/cart.php');
}

// =================================================================
// 3. LOGIKA UPDATE AJAX (SUPPORT VARIATION)
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    
    function send_json_response($data) {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    $product_id = (int)($_POST['product_id'] ?? 0);
    $variation_id = isset($_POST['variation_id']) && $_POST['variation_id'] !== '' ? (int)$_POST['variation_id'] : null;
    $action = (string)($_POST['action'] ?? '');

    if ($product_id <= 0 || $action !== 'update') {
        send_json_response(['success' => false, 'message' => 'Data tidak valid.']);
    }

    $quantity = (int)($_POST['quantity'] ?? 1);
    if ($quantity < 0) $quantity = 0;

    // Ambil info produk & variasi
    if ($variation_id) {
        $stmt_check = $conn->prepare("SELECT p.name, p.purchase_limit, p.stock_cycle_id, pv.price, pv.stock FROM products p JOIN product_variations pv ON p.id = pv.product_id WHERE p.id = ? AND pv.id = ?");
        $stmt_check->bind_param("ii", $product_id, $variation_id);
    } else {
        $stmt_check = $conn->prepare("SELECT name, purchase_limit, stock_cycle_id, price, stock FROM products WHERE id = ?");
        $stmt_check->bind_param("i", $product_id);
    }
    
    $stmt_check->execute();
    $product_data = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();

    if (!$product_data) {
        send_json_response(['success' => false, 'message' => 'Produk/Variasi tidak ditemukan.']);
    }

    $max_stock = (int)$product_data['stock'];
    $purchase_limit = (int)$product_data['purchase_limit'];
    $current_price = $product_data['price'];

    $max_allowed = $max_stock;
    
    if ($purchase_limit > 0) {
        if ($user_id > 0) {
            $already_bought = get_user_purchase_count($conn, $user_id, $product_id, $product_data['stock_cycle_id']);
            $pending_bought = get_user_pending_purchase_count($conn, $user_id, $product_id, $product_data['stock_cycle_id']);
            
            $total_committed = $already_bought + $pending_bought;
            $remaining_quota = max(0, $purchase_limit - $total_committed);
            
            $max_allowed = min($max_stock, $remaining_quota);
        } else {
            $max_allowed = min($max_stock, $purchase_limit);
        }
    }

    if ($quantity > $max_allowed) {
        $quantity = $max_allowed;
        if ($quantity == 0) {
             send_json_response([
                'success' => true,
                'newQuantity' => 0,
                'message' => 'Anda telah mencapai batas. Item dihapus.'
             ]);
        }
    }
    
    if ($user_id > 0) { 
        if ($quantity > 0) {
            // Update or Insert
            $sql_exist = "SELECT id FROM cart WHERE user_id = ? AND product_id = ? AND " . ($variation_id ? "variation_id = ?" : "(variation_id IS NULL OR variation_id = 0)");
            $stmt_e = $conn->prepare($sql_exist);
            if($variation_id) $stmt_e->bind_param("iii", $user_id, $product_id, $variation_id);
            else $stmt_e->bind_param("ii", $user_id, $product_id);
            $stmt_e->execute();
            $exists = $stmt_e->get_result()->fetch_assoc();
            $stmt_e->close();

            if ($exists) {
                $sql_up = "UPDATE cart SET quantity = ? WHERE id = ?";
                $stmt = $conn->prepare($sql_up);
                $stmt->bind_param("ii", $quantity, $exists['id']);
                $stmt->execute();
                $stmt->close();
            } else {
                $sql_in = "INSERT INTO cart (user_id, product_id, variation_id, quantity) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql_in);
                $stmt->bind_param("iiii", $user_id, $product_id, $variation_id, $quantity);
                $stmt->execute();
                $stmt->close();
            }

        } else {
            // Delete
            $sql_del = "DELETE FROM cart WHERE user_id = ? AND product_id = ? AND " . ($variation_id ? "variation_id = ?" : "(variation_id IS NULL OR variation_id = 0)");
            $stmt = $conn->prepare($sql_del);
            if($variation_id) $stmt->bind_param("iii", $user_id, $product_id, $variation_id);
            else $stmt->bind_param("ii", $user_id, $product_id);
            $stmt->execute();
            $stmt->close();
        }
    } else { 
        // Guest Logic Update
        if ($quantity > 0) {
            if (isset($_SESSION['cart'][$product_id])) {
                $_SESSION['cart'][$product_id]['quantity'] = $quantity;
                // Pastikan variation_id tidak hilang
                if ($variation_id) $_SESSION['cart'][$product_id]['variation_id'] = $variation_id;
            }
        } else {
            unset($_SESSION['cart'][$product_id]);
        }
    }

    // Recalculate Totals
    $cart_items_calc = get_cart_items_with_variation($conn, $user_id);
    $total_price_calc = array_reduce($cart_items_calc, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);
    $total_items_calc = array_reduce($cart_items_calc, fn($sum, $item) => $sum + $item['quantity'], 0);
    $new_item_subtotal = $current_price * $quantity;

    send_json_response([
        'success' => true,
        'newSubtotalFormatted' => format_rupiah($new_item_subtotal),
        'newGrandTotalFormatted' => format_rupiah($total_price_calc),
        'newCartCount' => $total_items_calc,
        'newQuantity' => $quantity
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    if (($_POST['action'] ?? '') === 'remove' && ($_POST['product_id'] ?? 0) > 0) {
        $product_id = (int)$_POST['product_id'];
        $variation_id = !empty($_POST['variation_id']) ? (int)$_POST['variation_id'] : null;

        if ($user_id > 0) {
            $sql_del = "DELETE FROM cart WHERE user_id = ? AND product_id = ?";
            if ($variation_id) {
                $sql_del .= " AND variation_id = " . $variation_id;
            } else {
                $sql_del .= " AND (variation_id IS NULL OR variation_id = 0)";
            }
            $stmt = $conn->prepare($sql_del);
            $stmt->bind_param("ii", $user_id, $product_id);
            $stmt->execute();
            $stmt->close();
        } else {
            unset($_SESSION['cart'][$product_id]);
        }
        set_flashdata('success', 'Produk berhasil dihapus dari keranjang.');
    }
    redirect('/cart/cart.php');
}

$cart_items = get_cart_items_with_variation($conn, $user_id);
$total_price = array_reduce($cart_items, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);
$page_title = "Keranjang Belanja";
?>

<!DOCTYPE html>
<html lang="id">
<?php page_head($page_title . ' - ' . get_setting($conn, 'store_name'), $conn); ?>
<body class="bg-gray-100">

    <?php navbar($conn) ?>
    
    <main class="container mx-auto px-4 py-8">
        <?php flash_message(); ?>

        <h1 class="text-3xl font-bold text-gray-800 mb-6">Keranjang Belanja Anda</h1>
        
        <?php if (empty($cart_items)): ?>
            <div class="bg-white rounded-lg shadow-md p-8 text-center">
                <i class="fas fa-shopping-cart text-5xl text-gray-300 mb-4"></i>
                <h2 class="text-2xl font-semibold text-gray-700">Keranjang Anda Kosong</h2>
                <p class="text-gray-500 mt-2">Sepertinya Anda belum menambahkan produk apapun.</p>
                <a href="<?= BASE_URL ?>/" class="mt-6 inline-block bg-indigo-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-indigo-700 transition-colors">Mulai Belanja</a>
            </div>
        <?php else: ?>
            <div class="flex flex-col lg:flex-row gap-8">
                <div class="lg:w-2/3">
                    <div class="bg-white rounded-lg shadow-md">
                        <div class="hidden md:flex bg-gray-50 rounded-t-lg p-4 font-semibold text-gray-600">
                            <div class="w-2/5">Produk</div>
                            <div class="w-1/5 text-center">Harga</div>
                            <div class="w-1/5 text-center">Jumlah</div>
                            <div class="w-1/5 text-right">Subtotal</div>
                        </div>

                        <?php 
                        foreach ($cart_items as $item): 
                            $limit_text = '';
                            $max_qty_input = (int)$item['stock'];
                            $purchase_limit = (int)($item['purchase_limit'] ?? 0); 

                            if ($purchase_limit > 0) {
                                if ($user_id > 0) {
                                    $already_bought = get_user_purchase_count($conn, $user_id, $item['product_id'], $item['stock_cycle_id']);
                                    $pending_bought = get_user_pending_purchase_count($conn, $user_id, $item['product_id'], $item['stock_cycle_id']);
                                    $total_committed = $already_bought + $pending_bought;
                                    $remaining_quota = max(0, $purchase_limit - $total_committed);
                                    
                                    $limit_text = "Limit: {$purchase_limit}. Sisa kuota Anda: {$remaining_quota}";
                                    $max_qty_input = min((int)$item['stock'], $remaining_quota);
                                } else {
                                    $limit_text = "Limit pembelian: {$purchase_limit} buah";
                                    $max_qty_input = min((int)$item['stock'], $purchase_limit);
                                }
                            }

                            // Handling Variation ID untuk selector JS
                            $unique_item_key = $item['product_id'] . (isset($item['variation_id']) ? '-' . $item['variation_id'] : '');
                            $var_id_val = isset($item['variation_id']) ? $item['variation_id'] : '';
                        ?>
                            <div class="p-4 border-b flex flex-col md:flex-row items-center gap-4">
                                <div class="w-full md:w-2/5 flex items-center">
                                    <!-- FOTO PRODUK (Otomatis pakai variasi jika ada) -->
                                    <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-20 h-20 object-cover rounded-md mr-4 border border-gray-200">
                                    
                                    <div>
                                        <a href="<?= BASE_URL ?>/product/product.php?id=<?= encode_id($item['product_id']) ?>" class="font-semibold text-gray-800 hover:text-indigo-600">
                                            <?= htmlspecialchars($item['name']) ?>
                                        </a>
                                        
                                        <!-- TAMPILKAN NAMA VARIASI -->
                                        <?php if(!empty($item['variation_name'])): ?>
                                            <p class="text-xs text-indigo-700 font-medium bg-indigo-50 px-2 py-1 rounded mt-1 inline-block border border-indigo-100">
                                                Variasi: <?= htmlspecialchars($item['variation_name']) ?>
                                            </p>
                                        <?php endif; ?>

                                        <!-- STOK (Otomatis pakai stok variasi) -->
                                        <p class="text-sm text-gray-500 mt-1">Stok Tersedia: <?= $item['stock'] ?></p>
                                        
                                        <?php if (!empty($limit_text)): ?>
                                            <p class="text-xs text-red-500 font-medium mt-1"><i class="fas fa-exclamation-circle"></i> <?= $limit_text ?></p>
                                        <?php endif; ?>
                                        
                                        <form method="POST" class="mt-2">
                                            <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                            <input type="hidden" name="variation_id" value="<?= $var_id_val ?>">
                                            <input type="hidden" name="action" value="remove">
                                            <button type="submit" class="text-red-500 hover:text-red-700 text-sm font-semibold" title="Hapus Item"><i class="fas fa-trash-alt mr-1"></i> Hapus</button>
                                        </form>
                                    </div>
                                </div>
                                <div class="w-full md:w-1/5 text-left md:text-center"><span class="md:hidden font-semibold">Harga: </span><?= format_rupiah($item['price']) ?></div>
                                <div class="w-full md:w-1/5 flex items-center justify-start md:justify-center">
                                    <div class="flex items-center border border-gray-300 rounded-md">
                                        <button class="quantity-change-btn p-2 text-gray-600 hover:bg-gray-100 rounded-l-md" 
                                                data-product-id="<?= $item['product_id'] ?>" 
                                                data-variation-id="<?= $var_id_val ?>"
                                                data-change="-1">-</button>
                                        <input type="number" class="quantity-input w-12 text-center border-l border-r" 
                                            value="<?= $item['quantity'] ?>" min="0" max="<?= $max_qty_input ?>" 
                                            data-product-id="<?= $item['product_id'] ?>"
                                            data-variation-id="<?= $var_id_val ?>">
                                        <button class="quantity-change-btn p-2 text-gray-600 hover:bg-gray-100 rounded-r-md" 
                                                data-product-id="<?= $item['product_id'] ?>" 
                                                data-variation-id="<?= $var_id_val ?>"
                                                data-change="1">+</button>
                                    </div>
                                </div>
                                <div class="w-full md:w-1/5 text-right font-bold">
                                    <span class="md:hidden">Subtotal: </span>
                                    <span id="subtotal-<?= $unique_item_key ?>">
                                        <?= format_rupiah($item['price'] * $item['quantity']) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="lg:w-1/3 mt-8 lg:mt-0">
                    <div class="bg-white rounded-lg shadow-md p-6 sticky top-8">
                        <h2 class="text-xl font-bold border-b pb-4 mb-4">Ringkasan Belanja</h2>
                        <div class="flex justify-between mb-2"><span class="text-gray-600">Subtotal</span><span class="font-semibold" id="summary-subtotal"><?= format_rupiah($total_price) ?></span></div>
                        <div class="flex justify-between mb-4"><span class="text-gray-600">Ongkir</span><span class="font-semibold">Akan dihitung</span></div>
                        <div class="border-t pt-4 flex justify-between items-center"><span class="text-gray-800 font-bold text-lg">Total</span><span class="font-bold text-xl text-indigo-600" id="summary-total"><?= format_rupiah($total_price) ?></span></div>
                        <a href="<?= BASE_URL ?>/checkout/checkout.php" class="mt-6 w-full text-center block bg-indigo-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-indigo-700">Lanjutkan ke Checkout</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php footer($conn) ?>

    <script>
        function debounce(func, delay = 400) {
            let timeout;
            return (...args) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => { func.apply(this, args); }, delay);
            };
        }

        async function updateCart(productId, variationId, quantity) {
            const formData = new FormData();
            formData.append('product_id', productId);
            if(variationId) formData.append('variation_id', variationId);
            formData.append('quantity', quantity);
            formData.append('action', 'update');
            formData.append('ajax', '1');

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                if (!response.ok) throw new Error('Network response was not ok.');
                const result = await response.json();
                
                if (result.success) {
                    if (result.newQuantity == 0) {
                        window.location.reload(); 
                        return;
                    }
                    
                    const uniqueKey = productId + (variationId ? '-' + variationId : '');
                    const subtotalEl = document.getElementById(`subtotal-${uniqueKey}`);
                    if(subtotalEl) subtotalEl.textContent = result.newSubtotalFormatted;
                    
                    document.getElementById('summary-subtotal').textContent = result.newGrandTotalFormatted;
                    document.getElementById('summary-total').textContent = result.newGrandTotalFormatted;
                    
                    let selector = `.quantity-input[data-product-id="${productId}"]`;
                    if(variationId) selector += `[data-variation-id="${variationId}"]`;
                    else selector += `:not([data-variation-id]), .quantity-input[data-product-id="${productId}"][data-variation-id=""]`;
                    
                    const inputEl = document.querySelector(selector);
                    if(inputEl && inputEl.value != result.newQuantity) {
                        inputEl.value = result.newQuantity;
                        window.location.reload();
                    }

                } else {
                    alert(result.message || 'Gagal memperbarui keranjang.');
                    window.location.reload();
                }
            } catch (error) {
                console.error('Error updating cart:', error);
                alert('Terjadi kesalahan. Halaman akan dimuat ulang.');
                window.location.reload();
            }
        }

        const debouncedUpdateCart = debounce(updateCart);

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.quantity-change-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const productId = this.dataset.productId;
                    const variationId = this.dataset.variationId;
                    
                    let selector = `.quantity-input[data-product-id="${productId}"]`;
                    if(variationId) selector += `[data-variation-id="${variationId}"]`;
                    else selector += `[data-variation-id=""]`;

                    const input = document.querySelector(selector);
                    
                    if (input) {
                        let oldValue = parseInt(input.value);
                        let newValue = oldValue + parseInt(this.dataset.change);
                        const maxAllowed = parseInt(input.max);
                        const minAllowed = parseInt(input.min);

                        if (newValue < minAllowed) newValue = minAllowed;
                        if (newValue > maxAllowed) {
                             newValue = maxAllowed;
                             alert("Anda telah mencapai sisa kuota/stok maksimum untuk produk ini.");
                        }
                        
                        if (oldValue !== newValue) {
                            input.value = newValue;
                            debouncedUpdateCart(productId, variationId, newValue);
                        }
                    }
                });
            });

            document.querySelectorAll('.quantity-input').forEach(input => {
                input.addEventListener('input', function() {
                    const productId = this.dataset.productId;
                    const variationId = this.dataset.variationId;
                    let quantity = parseInt(this.value);
                    const maxAllowed = parseInt(this.max);
                    const minAllowed = parseInt(this.min);

                    if (isNaN(quantity)) return;
                    if (quantity > maxAllowed) {
                        this.value = maxAllowed;
                        alert("Anda telah mencapai sisa kuota/stok maksimum untuk produk ini.");
                    }
                    if (quantity < minAllowed) {
                         this.value = minAllowed;
                    }
                    
                    debouncedUpdateCart(productId, variationId, this.value);
                });
            });
        });
    </script>
</body>
</html>