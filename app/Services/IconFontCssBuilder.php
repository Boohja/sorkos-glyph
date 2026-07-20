<?php

declare(strict_types=1);

namespace App\Services;

final class IconFontCssBuilder
{
    /** @param array<int, array<string, mixed>> $icons */
    public function build(array $sprite, array $icons, string $fontUrl): string
    {
        $prefix = (string)$sprite['slug'];
        $family = 'Glyph-' . substr((string)$sprite['public_hash'], 0, 12);
        $selectors = [];
        $mappings = [];

        foreach ($icons as $icon) {
            $className = $prefix . '-' . (string)$icon['symbol_id'];
            $selectors[] = '.' . $className . '::before';
            $mappings[] = sprintf(
                ".%s::before { content: \"\\%x\"; }",
                $className,
                (int)$icon['codepoint']
            );
        }

        $css = "@font-face {\n";
        $css .= "  font-family: \"{$family}\";\n";
        $css .= '  src: url("' . $fontUrl . '") format("woff2");' . "\n";
        $css .= "  font-style: normal;\n  font-weight: normal;\n  font-display: block;\n}\n\n";

        if ($selectors !== []) {
            $css .= implode(",\n", $selectors) . " {\n";
            $css .= "  display: inline-block;\n  font-family: \"{$family}\" !important;\n";
            $css .= "  font-style: normal;\n  font-weight: normal;\n  font-variant: normal;\n";
            $css .= "  line-height: 1;\n  text-rendering: auto;\n  -webkit-font-smoothing: antialiased;\n  -moz-osx-font-smoothing: grayscale;\n}\n\n";
            $css .= implode("\n", $mappings) . "\n";
        }

        return $css;
    }
}
