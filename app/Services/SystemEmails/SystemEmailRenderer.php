<?php

declare(strict_types=1);

namespace App\Services\SystemEmails;

use App\Models\SystemEmailTemplate;
use InvalidArgumentException;
use Throwable;

/**
 * Resolves the effective content of every §3.15 system-sent email (WP-20):
 * the admin override row in system_email_templates wins; a missing row, a
 * blank column or enabled=false falls back to the translatable code default
 * in lang/{locale}/system-emails.php — the same layering ListPageResolver
 * applies to list pages. [TAGS] are substituted with HTML-escaped values in
 * both subject and body, for defaults and overrides alike, so admin-authored
 * content can use the exact tags documented per key.
 *
 * Disabled semantics (documented): enabled=false means "ignore the stored
 * customization, use the default" — the email is still sent. §3.15 rows
 * define editable content, not delivery kill switches.
 */
class SystemEmailRenderer
{
    /**
     * Every §3.15 key with the merge tags its template may use. The tag list
     * doubles as the admin editor's per-key reference panel.
     *
     * @var array<string, list<string>>
     */
    public const KEYS = [
        'registration-confirmation' => ['[APP_NAME]', '[CONFIRM_URL]', '[EXPIRES_HOURS]'],
        'customer-welcome' => ['[APP_NAME]', '[FIRST_NAME]', '[LAST_NAME]', '[LOGIN_URL]'],
        'password-reset' => ['[APP_NAME]', '[RESET_URL]', '[EXPIRES_MINUTES]'],
        'password-changed' => ['[APP_NAME]'],
        'email-change-verification' => ['[APP_NAME]', '[CONFIRM_URL]', '[EXPIRES_HOURS]'],
        'twofa-recovery-codes' => ['[APP_NAME]'],
        'campaign-finished' => ['[APP_NAME]', '[CAMPAIGN_NAME]', '[SENT_COUNT]', '[FAILED_COUNT]', '[TOTAL_COUNT]'],
        'campaign-approval-request' => ['[APP_NAME]', '[CAMPAIGN_NAME]', '[CUSTOMER_NAME]', '[REVIEW_URL]'],
        'campaign-approved' => ['[APP_NAME]', '[CAMPAIGN_NAME]'],
        'campaign-blocked' => ['[APP_NAME]', '[CAMPAIGN_NAME]', '[REASON]'],
        // §3.7 F-3.7-004 — sent back from the approval queue for edits.
        'campaign-changes-requested' => ['[APP_NAME]', '[CAMPAIGN_NAME]', '[REASON]'],
        'quota-warning' => ['[APP_NAME]', '[USED]', '[LIMIT]', '[PERCENT]'],
        'quota-reached' => ['[APP_NAME]', '[USED]', '[LIMIT]', '[PERCENT]'],
        'server-validation' => ['[APP_NAME]', '[SERVER_NAME]', '[ACTIVATION_URL]'],
        'sending-domain-verification' => ['[APP_NAME]', '[DOMAIN_NAME]', '[RESULT]', '[DETAILS]'],
        'export-ready' => ['[APP_NAME]', '[EXPORT_NAME]', '[DOWNLOAD_URL]', '[EXPIRES_LINE]'],
        'import-finished' => ['[APP_NAME]', '[LIST_NAME]', '[IMPORTED]', '[UPDATED]', '[SKIPPED]', '[FAILED]'],
        'block-request-confirmation' => ['[APP_NAME]', '[CONFIRM_URL]'],
        'admin-heartbeat-stale' => ['[APP_NAME]', '[LAST_SEEN]'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::KEYS);
    }

    /** @return list<string> the merge tags documented for a key */
    public static function tags(string $key): array
    {
        return self::KEYS[$key] ?? [];
    }

    /**
     * Effective subject + HTML body for a key with merge tags substituted.
     * URL-valued tags are trusted verbatim inside href attributes but still
     * HTML-escaped; every other value is escaped too.
     *
     * @param  array<string, string|int|float|null>  $tags  tag name (without
     *         brackets) => raw value, e.g. ['CAMPAIGN_NAME' => $name]
     */
    public function resolve(string $key, array $tags = []): RenderedSystemEmail
    {
        if (! array_key_exists($key, self::KEYS)) {
            throw new InvalidArgumentException("Unknown system email key [{$key}].");
        }

        $row = $this->override($key);
        $useOverride = $row !== null && $row->enabled;

        $subject = $useOverride && $this->filled($row->subject)
            ? (string) $row->subject
            : $this->defaultSubject($key);

        $body = $useOverride && $this->filled($row->body)
            ? (string) $row->body
            : $this->defaultBody($key);

        $map = $this->tagMap($tags);

        return new RenderedSystemEmail(strtr($subject, $map), strtr($body, $map));
    }

    /** The translatable code default subject for a key. */
    public function defaultSubject(string $key): string
    {
        return __('system-emails.defaults.'.$key.'.subject');
    }

    /** The translatable code default HTML body for a key. */
    public function defaultBody(string $key): string
    {
        return __('system-emails.defaults.'.$key.'.body');
    }

    /**
     * The stored admin override row, if any. Guarded so queued mail can
     * still render before the WP-20 migration ran (installer safety).
     */
    public function override(string $key): ?SystemEmailTemplate
    {
        try {
            return SystemEmailTemplate::query()->where('key', $key)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /** Persist the admin customization for a key (F-3.15-016). */
    public function saveOverride(string $key, ?string $subject, ?string $body, bool $enabled = true): SystemEmailTemplate
    {
        if (! array_key_exists($key, self::KEYS)) {
            throw new InvalidArgumentException("Unknown system email key [{$key}].");
        }

        return SystemEmailTemplate::query()->updateOrCreate(
            ['key' => $key],
            ['subject' => $subject, 'body' => $body, 'enabled' => $enabled],
        );
    }

    /** "Reset to default": drop the override row (F-3.15-016). */
    public function resetOverride(string $key): void
    {
        SystemEmailTemplate::query()->where('key', $key)->delete();
    }

    /**
     * @param  array<string, string|int|float|null>  $tags
     * @return array<string, string> '[TAG]' => escaped value
     */
    private function tagMap(array $tags): array
    {
        $map = ['[APP_NAME]' => e($this->appName())];

        foreach ($tags as $name => $value) {
            $map['['.strtoupper($name).']'] = e((string) ($value ?? ''));
        }

        return $map;
    }

    /** Site name from the §3.25.1 settings, app.name pre-migration. */
    private function appName(): string
    {
        try {
            $name = \App\Services\Settings\SettingsReader::common()->siteName();

            if (trim($name) !== '') {
                return $name;
            }
        } catch (Throwable) {
            // settings table not migrated yet
        }

        return (string) config('app.name');
    }

    private function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
