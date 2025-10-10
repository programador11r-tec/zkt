<?php
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // allow Config\ too
        if (strncmp('Config\\', $class, 7) === 0) {
            $relative = substr($class, 7);
            $file = __DIR__ . '/../config/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) require $file;
        }
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
