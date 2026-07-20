<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FontArtifactRepository;
use App\Repositories\IconRepository;

final class IconFontGenerator
{
    public const BUILDER_VERSION = 'icon-font-v2';

    /** @param array<string, mixed> $config */
    public function __construct(
        private IconRepository $icons,
        private FontArtifactRepository $artifacts,
        private array $config,
        private string $root
    ) {
    }

    /** @param array<string, mixed> $sprite */
    public function generate(array $sprite): void
    {
        $spriteId = (int)$sprite['id'];
        $sourceVersion = (int)$sprite['public_version'];
        $icons = $this->icons->listForSprite($spriteId, (int)$sprite['user_id']);

        if ($icons === []) {
            $this->artifacts->clear($spriteId);
            return;
        }

        $this->icons->ensureCodepoints($spriteId);
        $icons = $this->icons->listForSprite($spriteId, (int)$sprite['user_id']);
        $this->artifacts->markPending($spriteId, $sourceVersion, self::BUILDER_VERSION);

        $fontConfig = is_array($this->config['font_generator'] ?? null) ? $this->config['font_generator'] : [];
        $python = trim((string)($fontConfig['python_binary'] ?? 'python'));
        $script = (string)($fontConfig['script_path'] ?? ($this->root . '/bin/build_icon_font.py'));

        if ($python === '' || !is_file($script) || !function_exists('proc_open')) {
            $this->fail($sprite, 'generator_unavailable', 'The icon font generator is not configured on this server.');
            return;
        }

        $workingDirectory = $this->root . '/tmp/font-' . bin2hex(random_bytes(8));
        if (!mkdir($workingDirectory, 0775, true) && !is_dir($workingDirectory)) {
            $this->fail($sprite, 'storage_error', 'A temporary font workspace could not be created.');
            return;
        }

        $inputPath = $workingDirectory . '/input.json';
        $payload = [
            'family' => 'Glyph-' . substr((string)$sprite['public_hash'], 0, 12),
            'icons' => array_map(static fn (array $icon): array => [
                'id' => (int)$icon['id'],
                'symbol_id' => (string)$icon['symbol_id'],
                'codepoint' => (int)$icon['codepoint'],
                'view_box' => (string)$icon['view_box'],
                'symbol_markup' => (string)$icon['symbol_markup'],
            ], $icons),
        ];

        if (file_put_contents($inputPath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            $this->removeDirectory($workingDirectory);
            $this->fail($sprite, 'storage_error', 'The font build input could not be written.');
            return;
        }

        $command = [$python, $script, $inputPath, $workingDirectory];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, $this->root, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            $this->removeDirectory($workingDirectory);
            $this->fail($sprite, 'generator_unavailable', 'The configured icon font generator could not be started.');
            return;
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $result = json_decode(trim($stdout), true);

        if ($exitCode !== 0 || !is_array($result) || empty($result['ok'])) {
            $error = is_array($result['error'] ?? null) ? $result['error'] : [
                'code' => 'generation_failed',
                'message' => $stderr !== '' ? trim($stderr) : 'The icon font could not be generated.',
            ];
            $this->removeDirectory($workingDirectory);
            $this->artifacts->markFailed($spriteId, $sourceVersion, self::BUILDER_VERSION, $error);
            return;
        }

        $woff2 = $workingDirectory . '/font.woff2';
        $woff = $workingDirectory . '/font.woff';
        if (!is_file($woff2) || !is_file($woff)) {
            $this->removeDirectory($workingDirectory);
            $this->fail($sprite, 'generation_failed', 'The generator did not produce the expected font files.');
            return;
        }

        try {
            [$woff2Hash, $woff2Size] = $this->storeArtifact($woff2, 'woff2');
            [$woffHash, $woffSize] = $this->storeArtifact($woff, 'woff');
            $this->artifacts->markReady($spriteId, $sourceVersion, self::BUILDER_VERSION, $woff2Hash, $woff2Size, $woffHash, $woffSize);
        } catch (\RuntimeException $exception) {
            $this->fail($sprite, 'storage_error', $exception->getMessage());
        } finally {
            $this->removeDirectory($workingDirectory);
        }
    }

    public function artifactPath(string $hash, string $extension): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || !in_array($extension, ['woff', 'woff2'], true)) {
            return '';
        }

        return $this->storageRoot() . '/' . substr($hash, 0, 2) . '/' . $hash . '.' . $extension;
    }

    /** @param array<string, mixed> $sprite */
    private function fail(array $sprite, string $code, string $message): void
    {
        $this->artifacts->markFailed((int)$sprite['id'], (int)$sprite['public_version'], self::BUILDER_VERSION, [
            'code' => $code,
            'message' => $message,
        ]);
    }

    /** @return array{0: string, 1: int} */
    private function storeArtifact(string $sourcePath, string $extension): array
    {
        $hash = hash_file('sha256', $sourcePath);
        $size = filesize($sourcePath);
        if ($hash === false || $size === false) {
            throw new \RuntimeException('The generated font could not be inspected.');
        }

        $directory = $this->storageRoot() . '/' . substr($hash, 0, 2);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The font artifact directory could not be created.');
        }

        $destination = $directory . '/' . $hash . '.' . $extension;
        if (!is_file($destination) && !copy($sourcePath, $destination)) {
            throw new \RuntimeException('The generated font could not be stored.');
        }

        return [$hash, (int)$size];
    }

    private function storageRoot(): string
    {
        $fontConfig = is_array($this->config['font_generator'] ?? null) ? $this->config['font_generator'] : [];
        return rtrim((string)($fontConfig['storage_path'] ?? ($this->root . '/storage/fonts')), '/\\');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                @unlink($directory . '/' . $item);
            }
        }
        @rmdir($directory);
    }
}
