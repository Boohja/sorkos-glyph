<?php

declare(strict_types=1);

namespace App\Services;

final class SlugService
{
    public function fromString(string $value, string $fallback = 'sprite'): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[\s_]+/', '-', $slug) ?? '';
        $slug = preg_replace('/[^a-z0-9-]+/', '', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            return $fallback;
        }

        return substr($slug, 0, 140);
    }
}
