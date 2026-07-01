<?php

declare(strict_types=1);

namespace App\Services;

final class SpriteBuilder
{
    /**
     * @param array<int, array<string, mixed>> $icons
     */
    public function build(array $icons, string $mode = 'pretty'): string
    {
        $pretty = $mode !== 'minified';

        if ($pretty) {
            $lines = ['<svg xmlns="http://www.w3.org/2000/svg" style="display:none">'];
            foreach ($icons as $icon) {
                $lines[] = sprintf(
                    '  <symbol id="%s" viewBox="%s">',
                    $this->escapeAttribute((string)$icon['symbol_id']),
                    $this->escapeAttribute((string)$icon['viewBox'])
                );

                $markup = $this->withCurrentColor(trim((string)$icon['symbol_markup']));
                if ($markup !== '') {
                    foreach (preg_split('/\R/', $markup) ?: [] as $line) {
                        $lines[] = '    ' . trim($line);
                    }
                }

                $lines[] = '  </symbol>';
            }
            $lines[] = '</svg>';

            return implode("\n", $lines) . "\n";
        }

        $output = '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">';
        foreach ($icons as $icon) {
            $output .= sprintf(
                '<symbol id="%s" viewBox="%s">%s</symbol>',
                $this->escapeAttribute((string)$icon['symbol_id']),
                $this->escapeAttribute((string)$icon['viewBox']),
                preg_replace('/>\s+</', '><', $this->withCurrentColor(trim((string)$icon['symbol_markup']))) ?? ''
            );
        }

        return $output . '</svg>';
    }

    private function withCurrentColor(string $markup): string
    {
        return preg_replace_callback('/\s(fill|stroke)="([^"]*)"/i', static function (array $matches): string {
            $value = strtolower(trim($matches[2]));

            if ($value === 'none' || $value === 'currentcolor') {
                return ' ' . $matches[1] . '="' . $matches[2] . '"';
            }

            return ' ' . $matches[1] . '="currentColor"';
        }, $markup) ?? $markup;
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
