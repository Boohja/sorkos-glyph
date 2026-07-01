<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

final class SvgSanitizer
{
    private const ALLOWED_ELEMENTS = [
        'svg' => true,
        'symbol' => true,
        'g' => true,
        'path' => true,
        'circle' => true,
        'rect' => true,
        'line' => true,
        'polyline' => true,
        'polygon' => true,
        'ellipse' => true,
        'title' => true,
        'desc' => true,
    ];

    private const BLOCKED_ELEMENTS = [
        'script' => true,
        'foreignobject' => true,
        'iframe' => true,
        'object' => true,
        'embed' => true,
        'image' => true,
        'video' => true,
        'audio' => true,
        'canvas' => true,
        'use' => true,
        'style' => true,
        'link' => true,
        'meta' => true,
        'base' => true,
    ];

    private const ALLOWED_ATTRIBUTES = [
        'id' => true,
        'class' => true,
        'd' => true,
        'fill' => true,
        'fill-rule' => true,
        'clip-rule' => true,
        'stroke' => true,
        'stroke-width' => true,
        'stroke-linecap' => true,
        'stroke-linejoin' => true,
        'stroke-miterlimit' => true,
        'stroke-dasharray' => true,
        'stroke-dashoffset' => true,
        'opacity' => true,
        'fill-opacity' => true,
        'stroke-opacity' => true,
        'transform' => true,
        'x' => true,
        'y' => true,
        'x1' => true,
        'y1' => true,
        'x2' => true,
        'y2' => true,
        'cx' => true,
        'cy' => true,
        'r' => true,
        'rx' => true,
        'ry' => true,
        'width' => true,
        'height' => true,
        'points' => true,
    ];

    private const VISIBLE_ELEMENTS = [
        'path' => true,
        'circle' => true,
        'rect' => true,
        'line' => true,
        'polyline' => true,
        'polygon' => true,
        'ellipse' => true,
    ];

    /**
     * @param array<string, mixed> $file
     * @param array<int, string> $existingSymbolIds
     * @return array<string, mixed>
     */
    public function sanitize(array $file, array $existingSymbolIds = [], bool $useCurrentColor = false): array
    {
        $filename = (string)($file['filename'] ?? 'icon.svg');
        $content = (string)($file['content'] ?? '');
        $warnings = [];
        $notes = [];

        if ($content === '') {
            return $this->failure($filename, 'Empty SVG file.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->preserveWhiteSpace = false;
        $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $errors !== []) {
            return $this->failure($filename, 'Invalid SVG XML.');
        }

        $root = $document->documentElement;
        if (!$root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            return $this->failure($filename, 'Root element must be an SVG.');
        }

        $viewBox = $this->viewBox($root, $warnings, $notes);
        if ($viewBox === null) {
            return $this->failure($filename, 'SVG must include a viewBox or numeric width and height.');
        }

        if ($root->hasAttribute('width') || $root->hasAttribute('height')) {
            $notes[] = 'Removed fixed width and height so the symbol scales through viewBox.';
        }

        $output = new DOMDocument('1.0', 'UTF-8');
        $fragment = $output->createDocumentFragment();
        $stats = [
            'visible' => 0,
            'title' => false,
            'colors' => [],
        ];

        foreach ($root->childNodes as $child) {
            $clean = $this->cleanNode($child, $output, $warnings, $notes, $stats, $useCurrentColor);
            if ($clean instanceof DOMNode) {
                $fragment->appendChild($clean);
            }
        }

        if ($stats['visible'] === 0) {
            return $this->failure($filename, 'No visible SVG shapes found.');
        }

        if (count($stats['colors']) > 1) {
            $warnings[] = 'Multiple hard-coded colors detected.';
        }

        $symbolMarkup = '';
        foreach ($fragment->childNodes as $child) {
            $symbolMarkup .= $output->saveXML($child);
        }

        return [
            'ok' => true,
            'filename' => $filename,
            'symbol_id' => $this->symbolId($filename, $existingSymbolIds),
            'title' => $this->titleFromFilename($filename),
            'viewBox' => $viewBox,
            'symbol_markup' => $symbolMarkup,
            'warnings' => array_values(array_unique($warnings)),
            'notes' => array_values(array_unique($notes)),
        ];
    }

    /**
     * @param array<int, string> $warnings
     * @param array<int, string> $notes
     * @param array<string, mixed> $stats
     */
    private function cleanNode(DOMNode $node, DOMDocument $output, array &$warnings, array &$notes, array &$stats, bool $useCurrentColor): ?DOMNode
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = trim((string)$node->textContent);
            return $text !== '' ? $output->createTextNode($text) : null;
        }

        if (!$node instanceof DOMElement) {
            return null;
        }

        $name = strtolower($node->localName);

        if (isset(self::BLOCKED_ELEMENTS[$name])) {
            $warnings[] = 'Removed unsupported element: ' . $node->localName . '.';
            return null;
        }

        if (!isset(self::ALLOWED_ELEMENTS[$name]) || $name === 'svg' || $name === 'symbol') {
            $warnings[] = 'Removed unsupported element: ' . $node->localName . '.';
            return null;
        }

        if (isset(self::VISIBLE_ELEMENTS[$name])) {
            $stats['visible']++;
        }

        if ($name === 'title' || $name === 'desc') {
            $stats['title'] = true;
        }

        $clean = $output->createElement($node->localName);

        if ($node->hasAttributes()) {
            foreach ($node->attributes as $attribute) {
                $attrName = strtolower($attribute->nodeName);
                $value = trim($attribute->nodeValue ?? '');

                if (!$this->attributeAllowed($attrName, $value)) {
                    $warnings[] = 'Removed unsupported attribute: ' . $attribute->nodeName . '.';
                    continue;
                }

                if ($useCurrentColor && ($attrName === 'fill' || $attrName === 'stroke') && strtolower($value) !== 'none' && strtolower($value) !== 'currentcolor') {
                    $value = 'currentColor';
                    $notes[] = 'Converted icon colors to currentColor.';
                }

                if (($attrName === 'fill' || $attrName === 'stroke') && !in_array(strtolower($value), ['none', 'currentcolor'], true)) {
                    $stats['colors'][strtolower($value)] = true;
                }

                $clean->setAttribute($attribute->nodeName, $value);
            }
        }

        foreach ($node->childNodes as $child) {
            $cleanChild = $this->cleanNode($child, $output, $warnings, $notes, $stats, $useCurrentColor);
            if ($cleanChild instanceof DOMNode) {
                $clean->appendChild($cleanChild);
            }
        }

        return $clean;
    }

    private function attributeAllowed(string $name, string $value): bool
    {
        if (str_starts_with($name, 'on')) {
            return false;
        }

        if (in_array($name, ['href', 'xlink:href', 'src', 'style'], true)) {
            return false;
        }

        if (!isset(self::ALLOWED_ATTRIBUTES[$name])) {
            return false;
        }

        $lowerValue = strtolower($value);
        return !str_contains($lowerValue, 'javascript:')
            && !str_contains($lowerValue, 'data:')
            && !str_contains($lowerValue, 'url(');
    }

    /**
     * @param array<int, string> $warnings
     * @param array<int, string> $notes
     */
    private function viewBox(DOMElement $root, array &$warnings, array &$notes): ?string
    {
        if ($root->hasAttribute('viewBox')) {
            $viewBox = trim($root->getAttribute('viewBox'));
            if (preg_match('/^-?\d*\.?\d+\s+-?\d*\.?\d+\s+\d*\.?\d+\s+\d*\.?\d+$/', $viewBox)) {
                return $viewBox;
            }
        }

        $width = $this->numericDimension($root->getAttribute('width'));
        $height = $this->numericDimension($root->getAttribute('height'));

        if ($width !== null && $height !== null) {
            $notes[] = 'Generated viewBox from numeric width and height.';
            return '0 0 ' . $this->formatNumber($width) . ' ' . $this->formatNumber($height);
        }

        $warnings[] = 'Missing viewBox.';
        return null;
    }

    private function numericDimension(string $value): ?float
    {
        $value = trim($value);
        if (preg_match('/^(\d*\.?\d+)(px)?$/', $value, $matches)) {
            return (float)$matches[1];
        }

        return null;
    }

    private function formatNumber(float $number): string
    {
        return rtrim(rtrim(sprintf('%.4F', $number), '0'), '.');
    }

    /**
     * @param array<int, string> $existing
     */
    private function symbolId(string $filename, array $existing): string
    {
        $base = strtolower(pathinfo($filename, PATHINFO_FILENAME));
        $base = preg_replace('/[\s_]+/', '-', $base) ?? '';
        $base = preg_replace('/[^a-z0-9-]+/', '', $base) ?? '';
        $base = trim($base, '-');

        if ($base === '' || !preg_match('/^[a-z]/', $base)) {
            $base = 'icon-' . $base;
        }

        $used = array_fill_keys($existing, true);
        $candidate = $base;
        $counter = 2;

        while (isset($used[$candidate])) {
            $candidate = $base . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function titleFromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $title = str_replace(['-', '_'], ' ', $base);
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;

        return ucwords(trim($title));
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $filename, string $message): array
    {
        return [
            'ok' => false,
            'filename' => $filename,
            'errors' => [$message],
        ];
    }
}
