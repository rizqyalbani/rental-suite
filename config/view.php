<?php

return [

    'paths' => [
        resource_path('views'),
    ],

    'compiled' => (function () {
        $default = realpath(storage_path('framework/views'));
        if ($default && is_writable($default)) {
            return $default;
        }
        $fallback = sys_get_temp_dir() . '/rental-views';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0755, true);
        }
        return $fallback;
    })(),

];
