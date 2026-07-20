<?php

return [
    'app' => [
        'env' => 'local',
        'debug' => true,
        'base_url' => 'https://glyph.test',
        'name' => 'Glyph',
    ],

    'security' => [
        'session_name' => 'glyph_session',
        'session_secure' => true,
        'session_httponly' => true,
        'session_samesite' => 'Lax',
        'csrf_key' => 'change-me',
    ],

    'uploads' => [
        'max_file_size_bytes' => 200000,
        'max_files_per_batch' => 50,
        'max_icons_per_guest_sprite' => 100,
        'max_icons_per_saved_sprite' => 500,
    ],

    'cdn_rate_limits' => [
        'per_ip_sprite_limit' => 120,
        'per_ip_sprite_window_seconds' => 60,
        'per_ip_limit' => 600,
        'per_ip_window_seconds' => 600,
        'per_sprite_limit' => 5000,
        'per_sprite_window_seconds' => 86400,
    ],

    'font_generator' => [
        'python_binary' => 'python',
        'script_path' => dirname(__DIR__) . '/bin/build_icon_font.py',
        'storage_path' => dirname(__DIR__) . '/storage/fonts',
    ],

    'auth' => [
        'base_url' => 'https://auth.test',
        'client_id' => 'glyph-local-client-id',
        'client_secret' => 'glyph-local-client-secret',
        'scopes' => ['profile', 'email'],
    ],
];
