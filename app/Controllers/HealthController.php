<?php

declare(strict_types=1);

namespace App\Controllers;

use Base;

final class HealthController
{
    public function show(Base $f3): void
    {
        $config = $f3->get('CONFIG');

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'app' => $config['app']['name'] ?? 'Glyph',
        ], JSON_UNESCAPED_SLASHES);
    }
}
