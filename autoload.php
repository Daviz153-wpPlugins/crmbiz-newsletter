<?php
defined('ABSPATH') || exit;

spl_autoload_register(function (string $class): void {
    $prefix = 'CRMBizNewsletter\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = CRMBIZ_NL_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});
