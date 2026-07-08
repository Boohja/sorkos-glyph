<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\IconRepository;
use App\Repositories\SpriteRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\Database;
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

        $sprite = $this->sprites($f3)->create((int)$currentUser['id'], 'New sprite', 'sprite', '', 'pretty');

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

        $f3->set('title', $sprite['name']);
        $f3->set('appName', $config['app']['name'] ?? 'Glyph');
        $f3->set('currentUser', $currentUser);
        $f3->set('csrfToken', (new CsrfService())->token());
        $f3->set('authLoginUrl', '/auth/login');
        $f3->set('sprite', $sprite);
        $f3->set('icons', $icons);
        $f3->set('cdnUrl', $this->absoluteUrl($config, '/cdn/sprites/' . $sprite['public_hash'] . '.svg'));
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
        $outputMode = (string)$f3->get('POST.output_mode');

        $this->sprites($f3)->update($spriteId, (int)$currentUser['id'], $name, $slug, $description, $outputMode);
        $f3->reroute('/sprites/' . $publicHash);
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
}
