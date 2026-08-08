<?php
/**
 * A simplified autoloader for the bundled Google API client library.
 *
 * @package    Wpcs_Price_Tracker
 * @subpackage Wpcs_Price_Tracker/vendor
 * @since      2.2.0
 */

spl_autoload_register(function ($class) {
    $prefix = 'Google_\\';
    $base_dir = __DIR__ . '/google/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('_', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
