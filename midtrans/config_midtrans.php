<?php
require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('midtrans_load_dotenv')) {
    function midtrans_load_dotenv($path) {
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }

            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $name = trim(substr($trimmed, 0, $eqPos));
            $value = trim(substr($trimmed, $eqPos + 1));
            if ($name === '') {
                continue;
            }

            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }

            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
            }
            if (!array_key_exists($name, $_SERVER)) {
                $_SERVER[$name] = $value;
            }
            if (getenv($name) === false) {
                putenv($name . '=' . $value);
            }
        }
    }
}

$projectRoot = dirname(__DIR__);
midtrans_load_dotenv($projectRoot . '/.env');
midtrans_load_dotenv($projectRoot . '/.env.local');

$serverKey = getenv('MIDTRANS_SERVER_KEY');
$clientKey = getenv('MIDTRANS_CLIENT_KEY');
$isProductionEnv = getenv('MIDTRANS_IS_PRODUCTION');
$isSanitizedEnv = getenv('MIDTRANS_IS_SANITIZED');
$is3dsEnv = getenv('MIDTRANS_IS_3DS');

\Midtrans\Config::$serverKey = is_string($serverKey) ? trim($serverKey) : '';
\Midtrans\Config::$clientKey = is_string($clientKey) ? trim($clientKey) : '';
\Midtrans\Config::$isProduction = filter_var($isProductionEnv ?? 'false', FILTER_VALIDATE_BOOLEAN);
\Midtrans\Config::$isSanitized = filter_var($isSanitizedEnv ?? 'true', FILTER_VALIDATE_BOOLEAN);
\Midtrans\Config::$is3ds = filter_var($is3dsEnv ?? 'true', FILTER_VALIDATE_BOOLEAN);

if (!function_exists('midtrans_snap_js_url')) {
    function midtrans_snap_js_url() {
        return \Midtrans\Config::$isProduction
            ? 'https://app.midtrans.com/snap/snap.js'
            : 'https://app.sandbox.midtrans.com/snap/snap.js';
    }
}
