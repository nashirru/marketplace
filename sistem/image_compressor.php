<?php
// File: sistem/image_compressor.php
// Programmer IQ 180: High Efficiency Image Compressor
// Features: WebP Support, Transparency Preservation, Memory Management

function compressImage($source, $destination, $quality) {
    // 1. Validasi Input
    if (!file_exists($source)) {
        return false;
    }

    // 2. Pastikan Folder Tujuan Ada (Recursive)
    $dir = dirname($destination);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            // Log error jika gagal buat folder
            error_log("Failed to create directory: $dir");
            return false;
        }
    }

    // 3. Ambil Info Gambar
    $info = @getimagesize($source);
    if (!$info) return false;
    
    $mime = $info['mime'];
    
    // 4. Manajemen Memori: Tingkatkan untuk gambar besar (High IQ Move)
    // Hitung perkiraan memori yang dibutuhkan (Width * Height * Channels * 1.8 overhead)
    // Tapi amannya set ke 512M untuk hosting standard
    ini_set('memory_limit', '512M');

    // 5. Load Gambar ke Memori
    $image = null;
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $image = @imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = @imagecreatefrompng($source);
            // Setup Alpha Channel untuk PNG
            imagealphablending($image, true); 
            imagesavealpha($image, true);
            break;
        case 'image/gif':
            $image = @imagecreatefromgif($source);
            break;
        case 'image/webp':
            $image = @imagecreatefromwebp($source);
            break;
        default:
            return false; // Tipe tidak didukung
    }

    if (!$image) return false;

    // 6. Simpan Gambar (Kompresi)
    $dest_ext = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
    $result = false;

    try {
        if ($dest_ext == 'png') {
            // PNG Quality: 0 (no compress) - 9 (max compress)
            // Konversi skala 0-100 (quality input) ke 9-0 (png quality)
            $pngQuality = round((100 - $quality) / 10);
            $pngQuality = max(0, min(9, $pngQuality)); // Clamp
            
            // Pertahankan transparansi
            imagealphablending($image, false);
            imagesavealpha($image, true);
            
            $result = imagepng($image, $destination, $pngQuality);
            
        } elseif ($dest_ext == 'webp') {
            // WebP Quality: 0-100
            // Pertahankan transparansi
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
            
            $result = imagewebp($image, $destination, $quality);
            
        } else {
            // JPG / JPEG: Quality 0-100
            // Handle Transparansi -> Putih (JPG tidak dukung transparan)
            if ($mime == 'image/png' || $mime == 'image/gif' || $mime == 'image/webp') {
                $width = imagesx($image);
                $height = imagesy($image);
                $bg = imagecreatetruecolor($width, $height);
                
                // Isi background putih
                $white = imagecolorallocate($bg, 255, 255, 255);
                imagefill($bg, 0, 0, $white);
                
                // Copy gambar transparan ke background putih
                imagecopy($bg, $image, 0, 0, 0, 0, $width, $height);
                imagedestroy($image); // Hapus gambar asli dari memori
                $image = $bg; // Ganti pointer
            }
            
            $result = imagejpeg($image, $destination, $quality);
        }
    } catch (Exception $e) {
        error_log("Compression Exception: " . $e->getMessage());
        $result = false;
    }

    // 7. Bersihkan Memori
    if ($image instanceof GdImage || is_resource($image)) {
        imagedestroy($image);
    }

    return $result;
}
?>