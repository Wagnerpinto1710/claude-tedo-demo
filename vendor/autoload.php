<?php
// Minimal autoloader for PHPMailer (no Composer needed).
spl_autoload_register(static function (string $class): void {
    $prefix = 'PHPMailer\\PHPMailer\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/phpmailer/phpmailer/src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) require $file;
    }
});
