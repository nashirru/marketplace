<?php
// File: admin/pesanan/cetak_resi_process.php
// Halaman interstitial: tampilkan "Resi sedang diproses" sebelum redirect ke generator PDF.

include '../../config/config.php';
include '../../sistem/sistem.php';

check_admin();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$targetPath = BASE_URL . '/admin/pesanan/cetak_resi.php';

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Memproses Resi...</title>
    <style>
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#0b1220;color:#e5e7eb}
        .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
        .card{max-width:520px;width:100%;background:#111827;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:22px}
        .row{display:flex;gap:14px;align-items:center}
        .spinner{width:18px;height:18px;border-radius:999px;border:3px solid rgba(255,255,255,.18);border-top-color:#60a5fa;animation:spin .9s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}
        h1{font-size:16px;margin:0 0 8px 0}
        p{margin:0;color:#9ca3af;font-size:13px;line-height:1.5}
        .hint{margin-top:10px;font-size:12px;color:#94a3b8}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="row">
            <div class="spinner" aria-hidden="true"></div>
            <div>
                <h1>Resi sedang diproses...</h1>
                <p>Tunggu sebentar, sistem sedang menyiapkan file resi untuk dicetak.</p>
            </div>
        </div>
        <div class="hint">Jika tab ini tidak otomatis berpindah, pastikan pop-up tidak diblokir.</div>
    </div>
</div>

<?php if ($method === 'POST'): ?>
    <form id="auto-submit" method="POST" action="<?= h($targetPath) ?>">
        <?php
        foreach ($_POST as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    echo '<input type="hidden" name="' . h($key) . '[]" value="' . h($v) . '">' . "\n";
                }
            } else {
                echo '<input type="hidden" name="' . h($key) . '" value="' . h($value) . '">' . "\n";
            }
        }
        ?>
    </form>
    <script>
        setTimeout(() => document.getElementById('auto-submit').submit(), 250);
    </script>
<?php else: ?>
    <?php
        $query = http_build_query($_GET);
        $targetUrl = $targetPath . ($query ? ('?' . $query) : '');
    ?>
    <script>
        setTimeout(() => { window.location.replace(<?= json_encode($targetUrl) ?>); }, 250);
    </script>
    <noscript>
        <meta http-equiv="refresh" content="0;url=<?= h($targetUrl) ?>">
    </noscript>
<?php endif; ?>
</body>
</html>

