<?php

declare(strict_types=1);

namespace App\Services;

final class IconFontCssBuilder
{
    /** @param array<int, array<string, mixed>> $icons */
    public function build(array $sprite, array $icons, string $woff2Url, string $woffUrl): string
    {
        $prefix = (string)$sprite['slug'];
        $family = 'Glyph-' . substr((string)$sprite['public_hash'], 0, 12);
        $mappings = [];

        foreach ($icons as $icon) {
            $className = $prefix . '-' . (string)$icon['symbol_id'];
            $mappings[] = sprintf(
                ".%s::before { content: \"\\%x\"; }",
                $className,
                (int)$icon['codepoint']
            );
        }

        $css = "@font-face {\n";
        $css .= "  font-family: \"{$family}\";\n";
        $css .= '  src: url("' . $woff2Url . '") format("woff2"),' . "\n";
        $css .= '       url("' . $woffUrl . '") format("woff");' . "\n";
        $css .= "  font-style: normal;\n  font-weight: normal;\n  font-display: block;\n}\n\n";

        if ($mappings !== []) {
            $css .= ".{$prefix} {\n";
            $css .= "  display: inline-block;\n  font-family: \"{$family}\" !important;\n";
            $css .= "  font-style: normal;\n  font-weight: normal;\n  font-variant: normal;\n";
            $css .= "  line-height: 1;\n  speak: never;\n  text-rendering: auto;\n  text-transform: none;\n";
            $css .= "  -webkit-font-smoothing: antialiased;\n  -moz-osx-font-smoothing: grayscale;\n}\n\n";
            $css .= implode("\n", $mappings) . "\n";
        }

        return $css;
    }
}
