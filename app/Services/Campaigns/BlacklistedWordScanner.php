<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Services\Settings\SettingsReader;

/**
 * Blacklisted/spam-word scan for subjects and content (spec §2.3.2
 * F-2.3.2-013, backed by §3.25.3 F-3.25.3-003). Case-insensitive,
 * multibyte-safe substring match. Whether a hit warns or blocks follows
 * CampaignSettings::blacklisted_words_action (flag | block).
 */
class BlacklistedWordScanner
{
    /**
     * @param  list<string>  $words
     * @return list<string> the blacklisted words found in $text
     */
    public function matches(?string $text, array $words): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        $haystack = mb_strtolower($text);

        return array_values(array_filter(
            $words,
            fn (string $word): bool => $word !== '' && mb_strpos($haystack, mb_strtolower($word)) !== false,
        ));
    }

    /** @return list<string> */
    public function subjectMatches(?string $subject): array
    {
        return $this->matches($subject, SettingsReader::campaign()->subjectBlacklistedWords());
    }

    /** @return list<string> */
    public function contentMatches(?string $content): array
    {
        return $this->matches($content, SettingsReader::campaign()->contentBlacklistedWords());
    }

    /** block mode (§3.25.3) — hits are hard validation errors. */
    public function blocks(): bool
    {
        return SettingsReader::campaign()->blacklistedWordsBlock();
    }
}
