<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Enums\ListPageType;
use App\Models\EmailList;
use App\Models\ListPage;
use App\Models\ListPageDefault;

/**
 * Resolves the effective content of a public list page (spec §2.2.12 / §3.16):
 * a 3-layer merge, most specific wins —
 *
 *   1. per-list override (list_pages, WP-08 editor)
 *   2. per-install admin default (list_page_defaults, WP-09 §3.16 admin page)
 *   3. hardcoded translatable default (lang/{locale}/lists.php pages.defaults)
 *
 * WP-09 renders the pages publicly via PublicPageRenderer; this class is the
 * single source of truth for effective content.
 */
class ListPageResolver
{
    /** Effective HTML content for the page (stored override or default). */
    public function content(EmailList $list, ListPageType $type): string
    {
        $stored = $this->record($list, $type)?->content;

        if ($stored !== null && trim($stored) !== '') {
            return $stored;
        }

        return $this->defaultContent($type);
    }

    /** Effective subject line (email page types only, F-2.2.12-001). */
    public function subject(EmailList $list, ListPageType $type): ?string
    {
        if (! $type->isEmail()) {
            return null;
        }

        $stored = $this->record($list, $type)?->subject;

        if ($stored !== null && trim($stored) !== '') {
            return $stored;
        }

        return $this->defaultSubject($type);
    }

    /** Per-page custom CSS injected into the public render (F-1.5-017). */
    public function customCss(EmailList $list, ListPageType $type): ?string
    {
        $stored = $this->record($list, $type)?->custom_css;

        return ($stored !== null && trim($stored) !== '') ? $stored : null;
    }

    /** Per-page custom JS injected into the public render (F-1.5-017). */
    public function customJs(EmailList $list, ListPageType $type): ?string
    {
        $stored = $this->record($list, $type)?->custom_js;

        return ($stored !== null && trim($stored) !== '') ? $stored : null;
    }

    /** Per-page redirect override; null = render the page (F-2.2.12-005). */
    public function redirectUrl(EmailList $list, ListPageType $type): ?string
    {
        $stored = $this->record($list, $type)?->redirect_url;

        return ($stored !== null && trim($stored) !== '') ? $stored : null;
    }

    public function record(EmailList $list, ListPageType $type): ?ListPage
    {
        return ListPage::withoutTenancy()
            ->where('list_id', $list->id)
            ->where('type', $type->value)
            ->first();
    }

    /** Effective default: admin per-install default, else translatable (§3.16). */
    public function defaultContent(ListPageType $type): string
    {
        if ($type->isRedirectOnly()) {
            return '';
        }

        $admin = $this->defaultRecord($type)?->content;

        if ($admin !== null && trim($admin) !== '') {
            return $admin;
        }

        return __($type->defaultContentKey());
    }

    /** Effective default subject: admin default, else translatable (§3.16). */
    public function defaultSubject(ListPageType $type): ?string
    {
        if (! $type->isEmail()) {
            return null;
        }

        $admin = $this->defaultRecord($type)?->subject;

        if ($admin !== null && trim($admin) !== '') {
            return $admin;
        }

        return __($type->defaultSubjectKey());
    }

    /** The per-install admin default row, if the operator saved one (§3.16). */
    public function defaultRecord(ListPageType $type): ?ListPageDefault
    {
        return ListPageDefault::query()->where('type', $type->value)->first();
    }

    /** Persist the per-install admin default for a page type (F-3.16-001). */
    public function saveDefault(ListPageType $type, ?string $content, ?string $subject): ListPageDefault
    {
        return ListPageDefault::query()->updateOrCreate(
            ['type' => $type->value],
            [
                'content' => $content,
                'subject' => $type->isEmail() ? $subject : null,
            ],
        );
    }

    /** Drop the admin default → back to the translatable layer (§3.16). */
    public function resetDefault(ListPageType $type): void
    {
        ListPageDefault::query()->where('type', $type->value)->delete();
    }

    /** Persist a customization (creates or updates the override row). */
    public function save(EmailList $list, ListPageType $type, ?string $content, ?string $subject, ?string $redirectUrl, ?string $customCss = null, ?string $customJs = null): ListPage
    {
        return ListPage::withoutTenancy()->updateOrCreate(
            ['list_id' => $list->id, 'type' => $type->value],
            [
                'customer_id' => $list->customer_id,
                'content' => $content,
                'subject' => $type->isEmail() ? $subject : null,
                'redirect_url' => $type->supportsRedirect() ? $redirectUrl : null,
                'custom_css' => $type->isEmail() || $type->isRedirectOnly() ? null : $customCss,
                'custom_js' => $type->isEmail() || $type->isRedirectOnly() ? null : $customJs,
            ],
        );
    }

    /** "Reset to default": drop the override row (F-2.2.12-004). */
    public function reset(EmailList $list, ListPageType $type): void
    {
        ListPage::withoutTenancy()
            ->where('list_id', $list->id)
            ->where('type', $type->value)
            ->delete();
    }

    /**
     * Merge tags for the editor reference panel (F-2.2.12-003), grouped for
     * display. WP-13's merge-tag registry supersedes this as the canonical
     * source (D-22).
     *
     * @return array<string, list<string>> group label key => tags
     */
    public static function mergeTags(): array
    {
        return [
            'subscriber' => [
                '[SUBSCRIBER_EMAIL]', '[SUBSCRIBER_UID]', '[CONFIRM_URL]',
                '[UNSUBSCRIBE_URL]', '[UPDATE_PROFILE_URL]', '[PREFERENCES_URL]',
                '[RESUBSCRIBE_URL]',
            ],
            'list' => [
                '[LIST_NAME]', '[LIST_UID]', '[LIST_FROM_NAME]', '[LIST_FROM_EMAIL]',
            ],
            'company' => [
                '[COMPANY_NAME]', '[COMPANY_FULL_ADDRESS]', '[COMPANY_PHONE]', '[COMPANY_WEBSITE]',
            ],
        ];
    }
}
