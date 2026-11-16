<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * Router script cho PHP Built-in Web Server nhằm mô phỏng mod_rewrite của Apache/Nginx.
 */
$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Trả về false để PHP Built-in Server tự động phục vụ các file tĩnh trong public/ (CSS, JS, images...)
if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';
