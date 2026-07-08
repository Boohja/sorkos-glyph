<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\IconRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\SpriteRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CdnRateLimiter;
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
            $this->sprites($f3)->touch((int)$existingIcon['sprite_id'], (int)$currentUser['id']);
        }

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

        $iconId = (int)$f3->get('PARAMS.id');
        $existingIcon = $this->icons($f3)->findForUser($iconId, (int)$currentUser['id']);
        $deleted = $this->icons($f3)->delete($iconId, (int)$currentUser['id']);
        if ($deleted && $existingIcon !== null) {
            $this->sprites($f3)->touch((int)$existingIcon['sprite_id'], (int)$currentUser['id']);
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

        if ($this->isCrossSiteSubresourceRequest($f3)) {
            http_response_code(403);
            header('Cache-Control: no-store');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Use the public CDN endpoint for sprite references.';
            return;
        }

        $limit = $this->checkSpriteDeliveryLimit($f3, $publicHash);
        if (!$limit['allowed']) {
            $this->rateLimited($limit['retry_after']);
            return;
        }

        $spriteId = (int)$sprite['id'];
        $icons = array_map(static function (array $icon): array {
            return [
                'symbol_id' => $icon['symbol_id'],
                'viewBox' => $icon['view_box'],
                'symbol_markup' => $icon['symbol_markup'],
            ];
        }, $this->icons($f3)->listForSprite($spriteId, (int)$currentUser['id']));

        $spriteXml = (new SpriteBuilder())->build($icons, (string)$sprite['output_mode']);
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9-]+/', '-', (string)$sprite['slug']) . '.svg"');
        echo $spriteXml;
    }

    public function cdnSprite(Base $f3): void
    {
        $publicHash = (string)$f3->get('PARAMS.hash');
        if (!preg_match('/^[A-Za-z0-9]{32,128}$/', $publicHash)) {
            http_response_code(404);
            echo 'Sprite not found.';
            return;
        }

        $sprite = $this->sprites($f3)->findPublicByHash($publicHash);
        if ($sprite === null) {
            http_response_code(404);
            echo 'Sprite not found.';
            return;
        }

        $this->sendCdnCacheHeaders($sprite);
        if ($this->isClientCacheFresh($sprite)) {
            http_response_code(304);
            return;
        }

        $limit = $this->checkSpriteDeliveryLimit($f3, $publicHash);
        if (!$limit['allowed']) {
            $this->rateLimited($limit['retry_after']);
            return;
        }

        $icons = array_map(static function (array $icon): array {
            return [
                'symbol_id' => $icon['symbol_id'],
                'viewBox' => $icon['view_box'],
                'symbol_markup' => $icon['symbol_markup'],
            ];
        }, $this->icons($f3)->listForSprite((int)$sprite['id'], (int)$sprite['user_id']));

        header('Content-Type: image/svg+xml; charset=utf-8');
        echo (new SpriteBuilder())->build($icons, (string)$sprite['output_mode']);
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
     * @param array<string, mixed> $sprite
     */
    private function sendCdnCacheHeaders(array $sprite): void
    {
        header('Cache-Control: public, max-age=300, stale-while-revalidate=86400');
        header('ETag: ' . $this->spriteEtag($sprite));

        $lastModified = strtotime((string)$sprite['updated_at']);
        if ($lastModified !== false) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        }
    }

    /**
     * @param array<string, mixed> $sprite
     */
    private function isClientCacheFresh(array $sprite): bool
    {
        $etag = $this->spriteEtag($sprite);
        $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return true;
        }

        $ifModifiedSince = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        $lastModified = strtotime((string)$sprite['updated_at']);

        return $ifModifiedSince !== false && $lastModified !== false && $ifModifiedSince >= $lastModified;
    }

    /**
     * @param array<string, mixed> $sprite
     */
    private function spriteEtag(array $sprite): string
    {
        return '"' . hash('sha256', (string)$sprite['public_hash'] . ':' . (string)$sprite['public_version']) . '"';
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
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: ' . $retryAfter);
        echo 'Too many requests. Please try again later.';
    }

    private function isCrossSiteSubresourceRequest(Base $f3): bool
    {
        $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) {
            return true;
        }

        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && !$this->sameConfiguredOrigin($f3, $origin)) {
            return true;
        }

        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');

        return $referer !== '' && !$this->sameConfiguredOrigin($f3, $referer);
    }

    private function sameConfiguredOrigin(Base $f3, string $url): bool
    {
        $config = $f3->get('CONFIG');
        $baseUrl = (string)($config['app']['base_url'] ?? '');
        $baseParts = parse_url($baseUrl);
        $urlParts = parse_url($url);

        if (!is_array($baseParts) || !is_array($urlParts)) {
            return false;
        }

        $baseScheme = strtolower((string)($baseParts['scheme'] ?? ''));
        $urlScheme = strtolower((string)($urlParts['scheme'] ?? ''));
        $baseHost = strtolower((string)($baseParts['host'] ?? ''));
        $urlHost = strtolower((string)($urlParts['host'] ?? ''));
        $basePort = (int)($baseParts['port'] ?? ($baseScheme === 'https' ? 443 : 80));
        $urlPort = (int)($urlParts['port'] ?? ($urlScheme === 'https' ? 443 : 80));

        return $baseScheme === $urlScheme && $baseHost === $urlHost && $basePort === $urlPort;
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
