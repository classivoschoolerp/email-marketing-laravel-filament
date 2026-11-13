<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The public list page types (spec §1.5 / §2.2.12): the 11 P1 pages plus the
 * two P2 pages added by WP-42 — the preference center (F-1.5-012) and the
 * resubscribe page (F-1.5-013). Two of them are emails rather than web pages
 * (double opt-in confirm + welcome) and carry a subject line. Content
 * defaults are translatable and live in lang/{locale}/lists.php under
 * pages.defaults; ListPageResolver merges them with per-list overrides stored
 * in list_pages, so every page here is editable per list and per install.
 */
enum ListPageType: string
{
    case SubscribeForm = 'subscribe-form';
    case SubscribePending = 'subscribe-pending';
    case SubscribeConfirm = 'subscribe-confirm';
    case SubscribeConfirmEmail = 'subscribe-confirm-email';
    case WelcomeEmail = 'welcome-email';
    case UpdateProfile = 'update-profile';
    case UnsubscribeForm = 'unsubscribe-form';
    case UnsubscribeConfirm = 'unsubscribe-confirm';
    case UnsubscribeSuccess = 'unsubscribe-success';
    case Redirect = 'redirect';
    case ListNotFound = 'list-not-found';
    // WP-42 P2 (§1.5 F-1.5-012/013).
    case PreferenceCenter = 'preference-center';
    case Resubscribe = 'resubscribe';

    public function label(): string
    {
        return __('lists.pages.types.'.$this->value);
    }

    /** Email page types carry a subject line (F-2.2.12-001). */
    public function isEmail(): bool
    {
        return in_array($this, [self::SubscribeConfirmEmail, self::WelcomeEmail], true);
    }

    /**
     * The redirect override page stores only a URL — no editable content
     * (spec §1.5 "Subscribe/unsubscribe redirect URL").
     */
    public function isRedirectOnly(): bool
    {
        return $this === self::Redirect;
    }

    /** Web pages support a per-page redirect override (F-2.2.12-005). */
    public function supportsRedirect(): bool
    {
        return ! $this->isEmail();
    }

    /** Lang key holding the default content template. */
    public function defaultContentKey(): string
    {
        return 'lists.pages.defaults.'.$this->value.'.content';
    }

    /** Lang key holding the default subject (email types only). */
    public function defaultSubjectKey(): string
    {
        return 'lists.pages.defaults.'.$this->value.'.subject';
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
