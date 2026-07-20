<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\IconRepository;
use App\Repositories\FontArtifactRepository;
use App\Repositories\SpriteRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\Database;
use App\Services\IconFontGenerator;
use App\Services\SlugService;
use Base;
use Template;

final class SpriteController
{
    public function create(Base $f3): void
    {
        $currentUser = $this->requireUser($f3);
        if ($currentUser === null) {
            return;
        }

        if (!$this->verifyCsrf($f3)) {
            $this->message($f3, 'Create failed', 'The create request could not be verified.', 400);
            return;
        }

        $sprite = $this->sprites($f3)->create((int)$currentUser['id'], 'New collection', 'icon', '');

        $f3->reroute('/sprites/' . $sprite['public_hash']);
    }

    public function edit(Base $f3): void
    {
        $currentUser = $this->requireUser($f3);
        if ($currentUser === null) {
            return;
        }

        $publicHash = (string)$f3->get('PARAMS.hash');
        $sprite = $this->sprites($f3)->findForUserByPublicHash($publicHash, (int)$currentUser['id']);
        if ($sprite === null) {
            $this->message($f3, 'Sprite not found', 'That sprite does not exist or is not available to this account.', 404);
            return;
        }

        $spriteId = (int)$sprite['id'];
        $icons = array_map([$this, 'prepareIcon'], $this->icons($f3)->listForSprite($spriteId, (int)$currentUser['id']));
        $config = $f3->get('CONFIG');
        $artifactRepo = $this->artifacts($f3);
        $font = $artifactRepo->findForSprite($spriteId);
        if ($icons !== [] && ($font === null
            || (int)$font['source_version'] !== (int)$sprite['public_version']
            || (string)$font['builder_version'] !== IconFontGenerator::BUILDER_VERSION)) {
            $this->fontGenerator($f3)->generate($sprite);
            $font = $artifactRepo->findForSprite($spriteId);
        }

        $f3->set('title', $sprite['name']);
        $f3->set('appName', $config['app']['name'] ?? 'Glyph');
        $f3->set('currentUser', $currentUser);
        $f3->set('csrfToken', (new CsrfService())->token());
        $f3->set('authLoginUrl', '/auth/login');
        $f3->set('sprite', $sprite);
        $f3->set('icons', $icons);
        $preparedFont = $this->prepareFont($font);
        $f3->set('font', $preparedFont);
        $f3->set('fontProblemIcons', $this->fontProblemIcons($icons, $preparedFont));
        $f3->set('fontCdnUrl', $this->absoluteUrl($config, '/cdn/fonts/' . $sprite['public_hash'] . '.css'));
        $f3->set('fontWoff2CdnUrl', $this->fontAssetCdnUrl($config, $sprite, $preparedFont, 'woff2'));
        $f3->set('fontWoffCdnUrl', $this->fontAssetCdnUrl($config, $sprite, $preparedFont, 'woff'));
        $f3->set('exampleSymbolId', (string)($icons[0]['symbol_id'] ?? 'icon-id'));
        $f3->set('exampleClass', (string)$sprite['slug'] . '-' . (string)($icons[0]['symbol_id'] ?? 'icon-id'));
        $f3->set('content', 'sprite-edit.html');

        echo Template::instance()->render('layout.html');
    }

    public function update(Base $f3): void
    {
        $currentUser = $this->requireUser($f3);
        if ($currentUser === null) {
            return;
        }

        if (!$this->verifyCsrf($f3)) {
            $this->message($f3, 'Update failed', 'The update request could not be verified.', 400);
            return;
        }

        $publicHash = (string)$f3->get('PARAMS.hash');
        $sprite = $this->sprites($f3)->findForUserByPublicHash($publicHash, (int)$currentUser['id']);
        if ($sprite === null) {
            $this->message($f3, 'Sprite not found', 'That sprite does not exist or is not available to this account.', 404);
            return;
        }

        $spriteId = (int)$sprite['id'];
        $name = trim((string)$f3->get('POST.name'));
        if ($name === '') {
            $name = 'Untitled sprite';
        }

        $slug = (new SlugService())->fromString((string)($f3->get('POST.slug') ?: $name));
        $description = trim((string)$f3->get('POST.description'));
        $spriteRepo = $this->sprites($f3);
        $spriteRepo->update($spriteId, (int)$currentUser['id'], $name, $slug, $description);
        $this->fontGenerator($f3)->generate($spriteRepo->findForUser($spriteId, (int)$currentUser['id']));
        $f3->reroute('/sprites/' . $publicHash);
    }

    public function retryFont(Base $f3): void
    {
        $currentUser = $this->requireUser($f3);
        if ($currentUser === null) {
            return;
        }
        if (!$this->verifyCsrf($f3)) {
            $this->message($f3, 'Retry failed', 'The font generation request could not be verified.', 400);
            return;
        }
        $publicHash = (string)$f3->get('PARAMS.hash');
        $sprite = $this->sprites($f3)->findForUserByPublicHash($publicHash, (int)$currentUser['id']);
        if ($sprite !== null) {
            $this->fontGenerator($f3)->generate($sprite);
        }
        $f3->reroute('/sprites/' . $publicHash . '#font');
    }

    public function delete(Base $f3): void
    {
        $currentUser = $this->requireUser($f3);
        if ($currentUser === null) {
            return;
        }

        if (!$this->verifyCsrf($f3)) {
            $this->message($f3, 'Delete failed', 'The delete request could not be verified.', 400);
            return;
        }

        $publicHash = (string)$f3->get('PARAMS.hash');
        $sprite = $this->sprites($f3)->findForUserByPublicHash($publicHash, (int)$currentUser['id']);
        if ($sprite !== null) {
            $this->sprites($f3)->softDelete((int)$sprite['id'], (int)$currentUser['id']);
        }

        $f3->reroute('/sprites');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requireUser(Base $f3): ?array
    {
        $pdo = Database::connection($f3->get('DB_CONFIG'));
        $auth = new AuthService($f3->get('CONFIG'), new UserRepository($pdo));
        $currentUser = $auth->currentUser();

        if ($currentUser === null) {
            $f3->reroute('/auth/login');
            return null;
        }

        return $currentUser;
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

    /**
     * @param array<string, mixed> $icon
     * @return array<string, mixed>
     */
    private function prepareIcon(array $icon): array
    {
        $messages = json_decode((string)($icon['warnings_json'] ?? ''), true);
        $icon['warnings'] = is_array($messages['warnings'] ?? null) ? $messages['warnings'] : [];
        $icon['notes'] = is_array($messages['notes'] ?? null) ? $messages['notes'] : [];
        $icon['messages'] = $this->visibleIconMessages($icon['warnings'], $icon['notes']);
        $svgSource = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="'
            . (string)$icon['view_box'] . '">' . "\n  "
            . trim((string)$icon['symbol_markup']) . "\n</svg>";
        $icon['svg_source_escaped'] = htmlspecialchars($svgSource, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return $icon;
    }

    /**
     * @param array<int, string> $warnings
     * @param array<int, string> $notes
     * @return array<int, string>
     */
    private function visibleIconMessages(array $warnings, array $notes): array
    {
        $hiddenNotes = [
            'Removed fixed width and height so the symbol scales through viewBox.' => true,
            'Converted icon colors to currentColor.' => true,
        ];

        $messages = $warnings;
        foreach ($notes as $note) {
            if (!isset($hiddenNotes[$note])) {
                $messages[] = $note;
            }
        }

        return array_values(array_unique($messages));
    }

    /** @param array<string, mixed>|null $font @return array<string, mixed> */
    private function prepareFont(?array $font): array
    {
        if ($font === null) {
            return ['status' => 'empty', 'error' => null];
        }
        $error = json_decode((string)($font['error_json'] ?? ''), true);
        $font['error'] = is_array($error) ? $error : null;
        $font['woff2_size_label'] = $this->fileSizeLabel((int)($font['woff2_size'] ?? 0));
        $font['woff_size_label'] = $this->fileSizeLabel((int)($font['woff_size'] ?? 0));
        return $font;
    }

    /**
     * Reuse the regular icon card data for font errors without the cleanup
     * badges shown in the Icons tab.
     *
     * @param array<int, array<string, mixed>> $icons
     * @param array<string, mixed> $font
     * @return array<int, array<string, mixed>>
     */
    private function fontProblemIcons(array $icons, array $font): array
    {
        $problemRows = is_array($font['error']['icons'] ?? null) ? $font['error']['icons'] : [];
        if ($problemRows === []) {
            return [];
        }

        $iconsBySymbolId = [];
        foreach ($icons as $icon) {
            $iconsBySymbolId[(string)$icon['symbol_id']] = $icon;
        }

        $problemIcons = [];
        foreach ($problemRows as $problemRow) {
            if (!is_array($problemRow)) {
                continue;
            }

            $symbolId = (string)($problemRow['symbol_id'] ?? '');
            if (!isset($iconsBySymbolId[$symbolId])) {
                continue;
            }

            $icon = $iconsBySymbolId[$symbolId];
            $icon['warnings'] = [];
            $icon['notes'] = [];
            $icon['messages'] = [];
            $problemIcons[] = $icon;
        }

        return $problemIcons;
    }

    private function fileSizeLabel(int $bytes): string
    {
        return $bytes >= 1024 ? number_format($bytes / 1024, 1) . ' KB' : $bytes . ' B';
    }

    private function message(Base $f3, string $title, string $body, int $status): void
    {
        $config = $f3->get('CONFIG');
        $f3->status($status);
        $f3->set('title', $title);
        $f3->set('appName', $config['app']['name'] ?? 'Glyph');
        $f3->set('currentUser', null);
        $f3->set('csrfToken', (new CsrfService())->token());
        $f3->set('authLoginUrl', '/auth/login');
        $f3->set('messageTitle', $title);
        $f3->set('messageBody', $body);
        $f3->set('content', 'message.html');

        echo Template::instance()->render('layout.html');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function absoluteUrl(array $config, string $path): string
    {
        $baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');

        return ($baseUrl !== '' ? $baseUrl : '') . $path;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $sprite
     * @param array<string, mixed> $font
     */
    private function fontAssetCdnUrl(array $config, array $sprite, array $font, string $extension): string
    {
        $hash = (string)($font[$extension . '_hash'] ?? '');
        if (($font['status'] ?? '') !== 'ready' || $hash === '') {
            return '';
        }

        return $this->absoluteUrl(
            $config,
            '/cdn/fonts/' . $sprite['public_hash'] . '/' . $hash . '.' . $extension
        );
    }

}
