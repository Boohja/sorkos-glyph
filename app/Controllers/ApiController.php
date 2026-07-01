<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\IconRepository;
use App\Repositories\SpriteRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\Database;
use App\Services\SpriteBuilder;
use App\Services\SvgSanitizer;
use Base;

final class ApiController
{
    public function sanitize(Base $f3): void
    {
        $this->json(['ok' => true, 'icons' => $this->sanitizeUploads($f3)]);
    }

    public function addIcons(Base $f3): void
    {
        $currentUser = $this->currentUser($f3);
        if ($currentUser === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Authentication required.']]], 401);
            return;
        }

        if (!$this->verifyCsrf($f3)) {
            $this->json(['ok' => false, 'errors' => [['message' => 'The upload request could not be verified.']]], 400);
            return;
        }

        $spriteId = (int)$f3->get('PARAMS.id');
        $spriteRepo = $this->sprites($f3);
        $sprite = $spriteRepo->findForUser($spriteId, (int)$currentUser['id']);
        if ($sprite === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Sprite not found.']]], 404);
            return;
        }

        $iconRepo = $this->icons($f3);
        $existing = array_map(static fn (array $icon): string => (string)$icon['symbol_id'], $iconRepo->listForSprite($spriteId, (int)$currentUser['id']));
        $results = $this->sanitizeUploads($f3, $existing);
        $added = 0;

        foreach ($results as $result) {
            if (($result['ok'] ?? false) === true) {
                $iconRepo->add($spriteId, (int)$currentUser['id'], $result);
                $added++;
            }
        }

        if ($added > 0) {
            $spriteRepo->touch($spriteId, (int)$currentUser['id']);
        }

        $this->json(['ok' => true, 'added' => $added, 'icons' => $results]);
    }

    public function updateIcon(Base $f3): void
    {
        $currentUser = $this->currentUser($f3);
        if ($currentUser === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Authentication required.']]], 401);
            return;
        }

        if (!$this->verifyCsrf($f3)) {
            $this->json(['ok' => false, 'errors' => [['message' => 'The icon update could not be verified.']]], 400);
            return;
        }

        $symbolId = trim((string)$f3->get('POST.symbol_id'));
        if (!preg_match('/^[a-z][a-z0-9_-]{0,119}$/', $symbolId)) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Symbol ID must start with a letter and use letters, numbers, dashes, or underscores.']]], 400);
            return;
        }

        $iconId = (int)$f3->get('PARAMS.id');
        $iconRepo = $this->icons($f3);
        $existingIcon = $iconRepo->findForUser($iconId, (int)$currentUser['id']);
        if ($existingIcon === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Icon not found.']]], 404);
            return;
        }

        $title = array_key_exists('title', $_POST)
            ? trim((string)$f3->get('POST.title'))
            : ($existingIcon['title'] ?? null);
        $sortOrder = array_key_exists('sort_order', $_POST)
            ? (int)$f3->get('POST.sort_order')
            : (int)$existingIcon['sort_order'];

        $updated = $iconRepo->update(
            $iconId,
            (int)$currentUser['id'],
            $symbolId,
            $title !== '' ? $title : null,
            $sortOrder
        );

        $this->json(['ok' => $updated]);
    }

    public function updateIcons(Base $f3): void
    {
        $currentUser = $this->currentUser($f3);
        if ($currentUser === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Authentication required.']]], 401);
            return;
        }

        if (!$this->verifyCsrf($f3)) {
            $this->json(['ok' => false, 'errors' => [['message' => 'The icon update could not be verified.']]], 400);
            return;
        }

        $spriteId = (int)$f3->get('PARAMS.id');
        $spriteRepo = $this->sprites($f3);
        $sprite = $spriteRepo->findForUser($spriteId, (int)$currentUser['id']);
        if ($sprite === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Sprite not found.']]], 404);
            return;
        }

        $payload = json_decode((string)$f3->get('POST.icons'), true);
        if (!is_array($payload) || $payload === []) {
            $this->json(['ok' => false, 'errors' => [['message' => 'No icon changes were provided.']]], 400);
            return;
        }

        $iconRepo = $this->icons($f3);
        $updated = [];

        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }

            $iconId = (int)($item['id'] ?? 0);
            $symbolId = trim((string)($item['symbol_id'] ?? ''));

            if (!preg_match('/^[a-z][a-z0-9_-]{0,119}$/', $symbolId)) {
                $this->json(['ok' => false, 'errors' => [['message' => 'Symbol ID must start with a letter and use letters, numbers, dashes, or underscores.']]], 400);
                return;
            }

            $existingIcon = $iconRepo->findForUser($iconId, (int)$currentUser['id']);
            if ($existingIcon === null || (int)$existingIcon['sprite_id'] !== $spriteId) {
                $this->json(['ok' => false, 'errors' => [['message' => 'Icon not found.']]], 404);
                return;
            }

            $iconRepo->update(
                $iconId,
                (int)$currentUser['id'],
                $symbolId,
                $existingIcon['title'] ?? null,
                (int)$existingIcon['sort_order']
            );

            $savedIcon = $iconRepo->findForUser($iconId, (int)$currentUser['id']);
            $updated[] = [
                'id' => $iconId,
                'symbol_id' => $savedIcon ? (string)$savedIcon['symbol_id'] : $symbolId,
            ];
        }

        if ($updated !== []) {
            $spriteRepo->touch($spriteId, (int)$currentUser['id']);
        }

        $this->json(['ok' => true, 'icons' => $updated]);
    }

    public function deleteIcon(Base $f3): void
    {
        $currentUser = $this->currentUser($f3);
        if ($currentUser === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Authentication required.']]], 401);
            return;
        }

        if (!$this->verifyCsrf($f3)) {
            $this->json(['ok' => false, 'errors' => [['message' => 'The delete request could not be verified.']]], 400);
            return;
        }

        $deleted = $this->icons($f3)->delete((int)$f3->get('PARAMS.id'), (int)$currentUser['id']);
        $this->json(['ok' => $deleted]);
    }

    public function downloadSavedSprite(Base $f3): void
    {
        $currentUser = $this->currentUser($f3);
        if ($currentUser === null) {
            http_response_code(401);
            echo 'Authentication required.';
            return;
        }

        $spriteId = (int)$f3->get('PARAMS.id');
        $sprite = $this->sprites($f3)->findForUser($spriteId, (int)$currentUser['id']);
        if ($sprite === null) {
            http_response_code(404);
            echo 'Sprite not found.';
            return;
        }

        $icons = array_map(static function (array $icon): array {
            return [
                'symbol_id' => $icon['symbol_id'],
                'viewBox' => $icon['view_box'],
                'symbol_markup' => $icon['symbol_markup'],
            ];
        }, $this->icons($f3)->listForSprite($spriteId, (int)$currentUser['id']));

        $spriteXml = (new SpriteBuilder())->build($icons, (string)$sprite['output_mode']);
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9-]+/', '-', (string)$sprite['slug']) . '.svg"');
        echo $spriteXml;
    }

    public function buildSprite(Base $f3): void
    {
        $payload = json_decode((string)$f3->get('BODY'), true);

        if (!is_array($payload)) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Invalid JSON payload.']]], 400);
            return;
        }

        $icons = $payload['icons'] ?? [];
        if (!is_array($icons) || $icons === []) {
            $this->json(['ok' => false, 'errors' => [['message' => 'No icons were provided.']]], 400);
            return;
        }

        $validated = [];
        foreach ($icons as $icon) {
            if (!is_array($icon)) {
                continue;
            }

            $symbolId = (string)($icon['symbol_id'] ?? '');
            $viewBox = (string)($icon['viewBox'] ?? '');
            $markup = (string)($icon['symbol_markup'] ?? '');

            if (!preg_match('/^[a-z][a-z0-9_-]{0,119}$/', $symbolId)) {
                $this->json(['ok' => false, 'errors' => [['message' => 'Invalid symbol id: ' . $symbolId]]], 400);
                return;
            }

            if (!preg_match('/^-?\d*\.?\d+\s+-?\d*\.?\d+\s+\d*\.?\d+\s+\d*\.?\d+$/', $viewBox)) {
                $this->json(['ok' => false, 'errors' => [['message' => 'Invalid viewBox for ' . $symbolId]]], 400);
                return;
            }

            if ($markup === '') {
                $this->json(['ok' => false, 'errors' => [['message' => 'Empty symbol markup for ' . $symbolId]]], 400);
                return;
            }

            $validated[] = [
                'symbol_id' => $symbolId,
                'viewBox' => $viewBox,
                'symbol_markup' => $markup,
            ];
        }

        $mode = (string)($payload['mode'] ?? 'pretty');
        $sprite = (new SpriteBuilder())->build($validated, $mode === 'minified' ? 'minified' : 'pretty');

        $this->json([
            'ok' => true,
            'sprite' => $sprite,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function uploadedFiles(string $field): array
    {
        if (empty($_FILES[$field])) {
            return [];
        }

        $files = $_FILES[$field];
        if (!is_array($files['name'])) {
            return [$files];
        }

        $normalized = [];
        foreach ($files['name'] as $index => $name) {
            $normalized[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $existingSymbolIds
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeUploads(Base $f3, array $existingSymbolIds = []): array
    {
        $config = $f3->get('CONFIG');
        $limits = $config['uploads'] ?? [];
        $maxFileSize = (int)($limits['max_file_size_bytes'] ?? 200000);
        $maxFiles = (int)($limits['max_files_per_batch'] ?? 50);
        $files = $this->uploadedFiles('icons');

        if ($files === []) {
            return [['ok' => false, 'filename' => '', 'errors' => ['No SVG files were uploaded.']]];
        }

        if (count($files) > $maxFiles) {
            return [['ok' => false, 'filename' => '', 'errors' => ['Too many files in this batch.']]];
        }

        $sanitizer = new SvgSanitizer();
        $results = [];
        $symbolIds = $existingSymbolIds;

        foreach ($files as $file) {
            $filename = (string)$file['name'];

            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $results[] = ['ok' => false, 'filename' => $filename, 'errors' => ['Upload failed.']];
                continue;
            }

            if ((int)$file['size'] > $maxFileSize) {
                $results[] = ['ok' => false, 'filename' => $filename, 'errors' => ['SVG exceeds the configured file size limit.']];
                continue;
            }

            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'svg') {
                $results[] = ['ok' => false, 'filename' => $filename, 'errors' => ['Only .svg files are accepted.']];
                continue;
            }

            $content = file_get_contents((string)$file['tmp_name']);
            $result = $sanitizer->sanitize([
                'filename' => $filename,
                'content' => $content !== false ? $content : '',
            ], $symbolIds, true);

            if (($result['ok'] ?? false) === true) {
                $symbolIds[] = (string)$result['symbol_id'];
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentUser(Base $f3): ?array
    {
        $pdo = Database::connection($f3->get('DB_CONFIG'));
        $auth = new AuthService($f3->get('CONFIG'), new UserRepository($pdo));

        return $auth->currentUser();
    }

    private function verifyCsrf(Base $f3): bool
    {
        return (new CsrfService())->verify($f3->get('POST.csrf_token'));
    }

    private function sprites(Base $f3): SpriteRepository
    {
        return new SpriteRepository(Database::connection($f3->get('DB_CONFIG')));
    }

    private function icons(Base $f3): IconRepository
    {
        return new IconRepository(Database::connection($f3->get('DB_CONFIG')));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
