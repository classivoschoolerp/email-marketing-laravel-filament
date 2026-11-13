<?php

declare(strict_types=1);

namespace App\Services\Content\InboxPreview;

/**
 * The provider an install without credentials actually gets (spec §2.3.5
 * F-2.3.5-006, WP-46), and the D-12 default.
 *
 * It renders NOTHING and says so. It exists so that every consumer — the job,
 * the UI, the tests — has a provider object to talk to instead of a null
 * check, and so that "no inbox preview here" is a stated result rather than a
 * crash or, worse, a fabricated screenshot. The one screenshot this build CAN
 * produce without a rendering farm is the derived SVG in
 * App\Services\Templates\TemplateThumbnails, which is explicitly not a
 * client render either.
 */
final class NullProvider implements InboxPreviewProvider
{
    public const KEY = 'null';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('inbox_preview.providers.null');
    }

    public function configured(): bool
    {
        return false;
    }

    public function clients(): array
    {
        return [];
    }

    public function submit(string $subject, string $html, ?string $text, array $clients = []): string
    {
        throw new InboxPreviewException(__('inbox_preview.errors.not_configured'));
    }

    public function results(string $remoteId): InboxPreviewResult
    {
        return InboxPreviewResult::failed(__('inbox_preview.errors.not_configured'));
    }
}
