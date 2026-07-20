<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\IconRepository;
use App\Repositories\FontArtifactRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\SpriteRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CdnRateLimiter;
use App\Services\CsrfService;
use App\Services\Database;
use App\Services\IconFontCssBuilder;
use App\Services\IconFontGenerator;
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

        $publicHash = (string)$f3->get('PARAMS.hash');
        $spriteRepo = $this->sprites($f3);
        $sprite = $spriteRepo->findForUserByPublicHash($publicHash, (int)$currentUser['id']);
        if ($sprite === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Sprite not found.']]], 404);
            return;
        }

        $spriteId = (int)$sprite['id'];
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
            $this->generateFont($f3, $spriteRepo->findForUser($spriteId, (int)$currentUser['id']));
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
        if ($updated) {
            $spriteRepo = $this->sprites($f3);
            $spriteRepo->touch((int)$existingIcon['sprite_id'], (int)$currentUser['id']);
            $this->generateFont($f3, $spriteRepo->findForUser((int)$existingIcon['sprite_id'], (int)$currentUser['id']));
        }

        $this->json(['ok' => $updated]);
    }

    public function replaceIconSource(Base $f3): void
    {
        $currentUser = $this->currentUser($f3);
        if ($currentUser === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Authentication required.']]], 401);
            return;
        }

        if (!$this->verifyCsrf($f3)) {
            $this->json(['ok' => false, 'errors' => [['message' => 'The SVG update could not be verified.']]], 400);
            return;
        }

        $iconId = (int)$f3->get('PARAMS.id');
        $iconRepo = $this->icons($f3);
        $existingIcon = $iconRepo->findForUser($iconId, (int)$currentUser['id']);
        if ($existingIcon === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Icon not found.']]], 404);
            return;
        }

        $result = $this->sanitizeSvgSource(
            $f3,
            (string)$existingIcon['symbol_id'] . '.svg',
            (string)$f3->get('POST.svg_source')
        );
        if (($result['ok'] ?? false) !== true) {
            $errors = array_map(
                static fn ($message): array => ['message' => (string)$message],
                is_array($result['errors'] ?? null) ? $result['errors'] : ['The SVG could not be processed.']
            );
            $this->json(['ok' => false, 'errors' => $errors], 422);
            return;
        }

        if (!$iconRepo->replaceSource($iconId, (int)$currentUser['id'], $result)) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Icon not found.']]], 404);
            return;
        }

        $spriteRepo = $this->sprites($f3);
        $spriteRepo->touch((int)$existingIcon['sprite_id'], (int)$currentUser['id']);
        $this->generateFont($f3, $spriteRepo->findForUser((int)$existingIcon['sprite_id'], (int)$currentUser['id']));
        $this->json(['ok' => true]);
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

        $publicHash = (string)$f3->get('PARAMS.hash');
        $spriteRepo = $this->sprites($f3);
        $sprite = $spriteRepo->findForUserByPublicHash($publicHash, (int)$currentUser['id']);
        if ($sprite === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Sprite not found.']]], 404);
            return;
        }

        $spriteId = (int)$sprite['id'];
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
            $this->generateFont($f3, $spriteRepo->findForUser($spriteId, (int)$currentUser['id']));
        }

        $this->json(['ok' => true, 'icons' => $updated]);
    }

    public function updateFontCdn(Base $f3): void
    {
        $currentUser = $this->currentUser($f3);
        if ($currentUser === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Authentication required.']]], 401);
            return;
        }

        if (!$this->verifyCsrf($f3)) {
            $this->json(['ok' => false, 'errors' => [['message' => 'The CDN update could not be verified.']]], 400);
            return;
        }

        $publicHash = (string)$f3->get('PARAMS.hash');
        $spriteRepo = $this->sprites($f3);
        $sprite = $spriteRepo->findForUserByPublicHash($publicHash, (int)$currentUser['id']);
        if ($sprite === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Sprite not found.']]], 404);
            return;
        }

        $enabled = (string)$f3->get('POST.enabled') === '1';
        $spriteRepo->setFontCdnEnabled((int)$sprite['id'], (int)$currentUser['id'], $enabled);
        $this->json(['ok' => true, 'enabled' => $enabled]);
    }

    public function dismissIconMessage(Base $f3): void
    {
        $currentUser = $this->currentUser($f3);
        if ($currentUser === null) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Authentication required.']]], 401);
            return;
        }
        if (!$this->verifyCsrf($f3)) {
            $this->json(['ok' => false, 'errors' => [['message' => 'The message update could not be verified.']]], 400);
            return;
        }

        $iconId = (int)$f3->get('PARAMS.id');
        $message = trim((string)$f3->get('POST.message'));
        $dismissed = $this->icons($f3)->dismissMessage($iconId, (int)$currentUser['id'], $message);
        if (!$dismissed) {
            $this->json(['ok' => false, 'errors' => [['message' => 'Message not found.']]], 404);
            return;
        }

        $this->json(['ok' => true]);
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

        $iconId = (int)$f3->get('PARAMS.id');
        $existingIcon = $this->icons($f3)->findForUser($iconId, (int)$currentUser['id']);
        $deleted = $this->icons($f3)->delete($iconId, (int)$currentUser['id']);
        if ($deleted && $existingIcon !== null) {
            $spriteRepo = $this->sprites($f3);
            $spriteRepo->touch((int)$existingIcon['sprite_id'], (int)$currentUser['id']);
            $this->generateFont($f3, $spriteRepo->findForUser((int)$existingIcon['sprite_id'], (int)$currentUser['id']));
        }

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

        $publicHash = (string)$f3->get('PARAMS.hash');
        $sprite = $this->sprites($f3)->findForUserByPublicHash($publicHash, (int)$currentUser['id']);
        if ($sprite === null) {
            http_response_code(404);
            echo 'Sprite not found.';
            return;
        }

        $icons = array_map(static fn (array $icon): array => [
            'symbol_id' => $icon['symbol_id'],
            'viewBox' => $icon['view_box'],
            'symbol_markup' => $icon['symbol_markup'],
        ], $this->icons($f3)->listForSprite((int)$sprite['id'], (int)$currentUser['id']));

        $spriteXml = (new SpriteBuilder())->build($icons, 'pretty');
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . (string)$sprite['slug'] . '.svg"');
        echo $spriteXml;
    }

    public function downloadIcon(Base $f3): void
    {
        $currentUser = $this->currentUser($f3);
        if ($currentUser === null) {
            http_response_code(401);
            echo 'Authentication required.';
            return;
        }

        $icon = $this->icons($f3)->findForUser((int)$f3->get('PARAMS.id'), (int)$currentUser['id']);
        if ($icon === null) {
            http_response_code(404);
            echo 'Icon not found.';
            return;
        }

        $viewBox = htmlspecialchars((string)$icon['view_box'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $viewBox . '">' . "\n"
            . trim((string)$icon['symbol_markup']) . "\n</svg>\n";

        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . (string)$icon['symbol_id'] . '.svg"');
        echo $svg;
    }

    public function downloadFont(Base $f3): void
    {
        $currentUser = $this->currentUser($f3);
        if ($currentUser === null) {
            http_response_code(401);
            echo 'Authentication required.';
            return;
        }

        $publicHash = (string)$f3->get('PARAMS.hash');
        $sprite = $this->sprites($f3)->findForUserByPublicHash($publicHash, (int)$currentUser['id']);
        if ($sprite === null) {
            http_response_code(404);
            echo 'Sprite not found.';
            return;
        }

        $extension = strtolower((string)$f3->get('PARAMS.extension'));
        if (!in_array($extension, ['woff', 'woff2'], true)) {
            http_response_code(404);
            return;
        }
        $artifact = $this->artifacts($f3)->findForSprite((int)$sprite['id']);
        $hash = is_array($artifact) && ($artifact['status'] ?? '') === 'ready'
            ? (string)$artifact[$extension . '_hash']
            : '';
        $path = $this->fontGenerator($f3)->artifactPath($hash, $extension);
        if ($path === '' || !is_file($path)) {
            http_response_code(404);
            echo 'Font not available.';
            return;
        }

        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: font/' . $extension);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . (string)$sprite['slug'] . '.' . $extension . '"');
        readfile($path);
    }

    public function downloadFontCss(Base $f3): void
    {
        $currentUser = $this->currentUser($f3);
        if ($currentUser === null) {
            http_response_code(401);
            echo 'Authentication required.';
            return;
        }
        $publicHash = (string)$f3->get('PARAMS.hash');
        $sprite = $this->sprites($f3)->findForUserByPublicHash($publicHash, (int)$currentUser['id']);
        $artifact = $sprite ? $this->artifacts($f3)->findForSprite((int)$sprite['id']) : null;
        if ($sprite === null || !is_array($artifact) || ($artifact['status'] ?? '') !== 'ready') {
            http_response_code(404);
            echo 'Font not available.';
            return;
        }
        $icons = $this->icons($f3)->listForSprite((int)$sprite['id'], (int)$currentUser['id']);
        $css = (new IconFontCssBuilder())->build($sprite, $icons, './' . (string)$sprite['slug'] . '.woff2');
        header('Cache-Control: private, no-store');
        header('Content-Type: text/css; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . (string)$sprite['slug'] . '.css"');
        echo $css;
    }

    public function cdnFontCss(Base $f3): void
    {
        $publicHash = (string)$f3->get('PARAMS.hash');
        $artifact = $this->artifacts($f3)->findPublicByHash($publicHash);
        if ($artifact === null) {
            http_response_code(404);
            echo 'Font not found.';
            return;
        }
        $etag = '"' . hash('sha256', 'font-css-v1:' . $artifact['slug'] . ':' . $artifact['woff2_hash']) . '"';
        $this->sendPublicFontAccessHeaders();
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=300, stale-while-revalidate=86400');
        header('ETag: ' . $etag);
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        $limit = $this->checkSpriteDeliveryLimit($f3, $publicHash);
        if (!$limit['allowed']) {
            $this->rateLimited($limit['retry_after']);
            return;
        }
        $icons = $this->icons($f3)->listForSprite((int)$artifact['sprite_id'], (int)$artifact['user_id']);
        $fontUrl = '/cdn/fonts/' . $publicHash . '/' . $artifact['woff2_hash'] . '.woff2';
        echo (new IconFontCssBuilder())->build($artifact, $icons, $fontUrl);
    }

    public function cdnFont(Base $f3): void
    {
        $publicHash = (string)$f3->get('PARAMS.hash');
        $requestedHash = strtolower((string)$f3->get('PARAMS.artifact'));
        $extension = strtolower((string)$f3->get('PARAMS.extension'));
        $artifact = $this->artifacts($f3)->findPublicByHash($publicHash);
        $hashKey = $extension . '_hash';
        if ($artifact === null || !in_array($extension, ['woff', 'woff2'], true)
            || !isset($artifact[$hashKey]) || !hash_equals((string)$artifact[$hashKey], $requestedHash)) {
            http_response_code(404);
            echo 'Font not found.';
            return;
        }
        $path = $this->fontGenerator($f3)->artifactPath($requestedHash, $extension);
        if ($path === '' || !is_file($path)) {
            http_response_code(404);
            return;
        }
        $limit = $this->checkSpriteDeliveryLimit($f3, $publicHash);
        if (!$limit['allowed']) {
            $this->rateLimited($limit['retry_after']);
            return;
        }
        $this->sendPublicFontAccessHeaders();
        header('Content-Type: font/' . $extension);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: "' . $requestedHash . '"');
        readfile($path);
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
        $maxFiles = (int)($limits['max_files_per_batch'] ?? 50);
        $files = $this->uploadedFiles('icons');

        if ($files === []) {
            return [['ok' => false, 'filename' => '', 'errors' => ['No SVG files were uploaded.']]];
        }

        if (count($files) > $maxFiles) {
            return [['ok' => false, 'filename' => '', 'errors' => ['Too many files in this batch.']]];
        }

        $results = [];
        $symbolIds = $existingSymbolIds;

        foreach ($files as $file) {
            $filename = (string)$file['name'];

            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $results[] = ['ok' => false, 'filename' => $filename, 'errors' => ['Upload failed.']];
                continue;
            }

            if ($this->svgSourceExceedsLimit($f3, (int)$file['size'])) {
                $results[] = ['ok' => false, 'filename' => $filename, 'errors' => ['SVG exceeds the configured file size limit.']];
                continue;
            }

            $content = file_get_contents((string)$file['tmp_name']);
            $result = $this->sanitizeSvgSource(
                $f3,
                $filename,
                $content !== false ? $content : '',
                $symbolIds,
                (int)$file['size']
            );

            if (($result['ok'] ?? false) === true) {
                $symbolIds[] = (string)$result['symbol_id'];
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param array<int, string> $existingSymbolIds
     * @return array<string, mixed>
     */
    private function sanitizeSvgSource(
        Base $f3,
        string $filename,
        string $content,
        array $existingSymbolIds = [],
        ?int $reportedSize = null
    ): array {
        $size = $reportedSize ?? strlen($content);

        if ($this->svgSourceExceedsLimit($f3, $size) || $this->svgSourceExceedsLimit($f3, strlen($content))) {
            return ['ok' => false, 'filename' => $filename, 'errors' => ['SVG exceeds the configured file size limit.']];
        }

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'svg') {
            return ['ok' => false, 'filename' => $filename, 'errors' => ['Only .svg files are accepted.']];
        }

        return (new SvgSanitizer())->sanitize([
            'filename' => $filename,
            'content' => $content,
        ], $existingSymbolIds, true);
    }

    private function svgSourceExceedsLimit(Base $f3, int $size): bool
    {
        $config = $f3->get('CONFIG');
        $limits = $config['uploads'] ?? [];
        return $size > (int)($limits['max_file_size_bytes'] ?? 200000);
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

    private function artifacts(Base $f3): FontArtifactRepository
    {
        return new FontArtifactRepository(Database::connection($f3->get('DB_CONFIG')));
    }

    private function fontGenerator(Base $f3): IconFontGenerator
    {
        return new IconFontGenerator(
            $this->icons($f3),
            $this->artifacts($f3),
            is_array($f3->get('CONFIG')) ? $f3->get('CONFIG') : [],
            (string)$f3->get('ROOT')
        );
    }

    /** @param array<string, mixed>|null $sprite */
    private function generateFont(Base $f3, ?array $sprite): void
    {
        if ($sprite !== null) {
            $this->fontGenerator($f3)->generate($sprite);
        }
    }

    private function sendPublicFontAccessHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Cross-Origin-Resource-Policy: cross-origin');
        header('Timing-Allow-Origin: *');
    }

    /**
     * @return array{allowed: bool, retry_after: int}
     */
    private function checkSpriteDeliveryLimit(Base $f3, string $publicHash): array
    {
        $config = $f3->get('CONFIG');
        $limiter = new CdnRateLimiter(
            new RateLimitRepository(Database::connection($f3->get('DB_CONFIG'))),
            is_array($config['cdn_rate_limits'] ?? null) ? $config['cdn_rate_limits'] : []
        );

        return $limiter->allow($this->clientIpAddress(), $publicHash);
    }

    private function rateLimited(int $retryAfter): void
    {
        http_response_code(429);
        $this->sendPublicFontAccessHeaders();
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: ' . $retryAfter);
        echo 'Too many requests. Please try again later.';
    }

    private function clientIpAddress(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
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
