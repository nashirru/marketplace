<?php
echo 'SAPI: ' . php_sapi_name() . PHP_EOL;
echo 'Loaded php.ini: ' . php_ini_loaded_file() . PHP_EOL;
echo 'curl loaded: ' . (extension_loaded('curl') ? 'YES' : 'NO') . PHP_EOL;
