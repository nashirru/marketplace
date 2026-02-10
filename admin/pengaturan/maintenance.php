<?php
// File: admin/pengaturan/maintenance.php
// Halaman ini di-include oleh admin.php

if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

// Ambil pengaturan saat ini dari variabel global $settings (sudah di-load oleh admin.php)
global $settings_cache; // Menggunakan $settings_cache dari sistem.php
$current_mode = $settings_cache['maintenance_mode'] ?? 'off';
$current_message = $settings_cache['maintenance_message'] ?? 'Kami sedang melakukan pemeliharaan. Mohon kembali lagi nanti.';
?>

<div class="max-w-2xl mx-auto">
    <div class="bg-white p-6 sm:p-8 rounded-xl shadow-md">
        <form action="?page=mode_maintenance" method="POST">
            
            <div class="space-y-6">
                
                <!-- Pilihan Status Maintenance -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status Maintenance</label>
                    <div class="flex rounded-lg shadow-sm">
                        <!-- Opsi "Tidak Aktif" -->
                        <label class="relative flex-1 p-3 sm:p-4 border border-gray-300 rounded-l-lg cursor-pointer focus-within:z-10 focus-within:ring-2 focus-within:ring-indigo-500
                                      <?= ($current_mode == 'off') ? 'bg-indigo-50 border-indigo-500 z-10' : 'hover:bg-gray-50' ?>">
                            <input type="radio" name="maintenance_mode" value="off" class="sr-only" 
                                   <?= ($current_mode == 'off') ? 'checked' : '' ?>>
                            <div class="flex items-center">
                                <i class="fas fa-toggle-off text-2xl <?= ($current_mode == 'off') ? 'text-indigo-600' : 'text-gray-400' ?> mr-3"></i>
                                <div>
                                    <span class="block font-semibold <?= ($current_mode == 'off') ? 'text-indigo-900' : 'text-gray-900' ?>">Tidak Aktif</span>
                                    <span class="block text-sm <?= ($current_mode == 'off') ? 'text-indigo-700' : 'text-gray-500' ?>">Situs dapat diakses publik.</span>
                                </div>
                            </div>
                        </label>
                        
                        <!-- Opsi "Aktif" -->
                        <label class="relative flex-1 p-3 sm:p-4 border-t border-b border-r border-gray-300 rounded-r-lg cursor-pointer focus-within:z-10 focus-within:ring-2 focus-within:ring-red-500
                                      <?= ($current_mode == 'on') ? 'bg-red-50 border-red-500 z-10' : 'hover:bg-gray-50' ?>">
                             <input type="radio" name="maintenance_mode" value="on" class="sr-only"
                                    <?= ($current_mode == 'on') ? 'checked' : '' ?>>
                            <div class="flex items-center">
                                <i class="fas fa-toggle-on text-2xl <?= ($current_mode == 'on') ? 'text-red-600' : 'text-gray-400' ?> mr-3"></i>
                                <div>
                                    <span class="block font-semibold <?= ($current_mode == 'on') ? 'text-red-900' : 'text-gray-900' ?>">Aktif</span>
                                     <span class="block text-sm <?= ($current_mode == 'on') ? 'text-red-700' : 'text-gray-500' ?>">Situs akan ditutup untuk publik.</span>
                                </div>
                            </div>
                        </label>
                    </div>
                    <?php if ($current_mode == 'on'): ?>
                        <p class="mt-2 text-sm text-red-600 font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i> Mode Maintenance sedang AKTIF. Hanya admin yang bisa mengakses situs.</p>
                    <?php endif; ?>
                </div>

                <!-- Pesan Maintenance -->
                <div>
                    <label for="maintenance_message" class="block text-sm font-medium text-gray-700">Pesan Maintenance</label>
                    <textarea id="maintenance_message" name="maintenance_message" rows="4" 
                              class="mt-1 block w-full border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm p-3"
                              placeholder="Contoh: Kami sedang melakukan input stok. Mohon kembali lagi nanti."><?= htmlspecialchars($current_message) ?></textarea>
                    <p class="mt-2 text-xs text-gray-500">Pesan ini akan ditampilkan kepada pengunjung saat mode maintenance aktif. Sesuai permintaan Anda, beritahu mereka bahwa Anda sedang input stok.</p>
                </div>

            </div>

            <!-- Tombol Simpan -->
            <div class="border-t mt-8 pt-6">
                <button type="submit" name="save_maintenance_settings" 
                        class="w-full sm:w-auto flex justify-center py-2 px-6 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    <i class="fas fa-save mr-2 mt-0.5"></i> Simpan Pengaturan
                </button>
            </div>

        </form>
    </div>
</div>