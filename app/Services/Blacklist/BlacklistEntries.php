<?php

declare(strict_types=1);

namespace App\Services\Blacklist;

use App\Models\GlobalBlacklistEmail;
use Illuminate\Support\Facades\DB;

/**
 * Mutation helpers for global blacklist rows (§2.9.10/§3.17): parse pasted
 * blobs, chunked duplicate-safe inserts (the email column is unique), and
 * domain-row normalisation. Both panels (customer + admin) and the queued
 * CSV import funnel through here.
 */
class BlacklistEntries
{
    private const CHUNK = 500;

    /**
     * Split a pasted blob on newlines/commas/semicolons/whitespace and keep
     * the syntactically valid, lower-cased, de-duplicated addresses.
     *
     * @return list<string>
     */
    public function parse(string $text): array
    {
        $candidates = preg_split('/[\s,;]+/', $text) ?: [];
        $emails = [];

        foreach ($candidates as $candidate) {
            $email = mb_strtolower(trim($candidate));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[$email] = $email;
            }
        }

        return array_values($emails);
    }

    /**
     * Insert addresses (or domains) ignoring rows that already exist.
     * Returns the number of rows actually inserted.
     *
     * @param  list<string>  $emails
     */
    public function addMany(
        array $emails,
        ?string $reason,
        string $source,
        ?int $customerId,
        bool $isDomain = false,
    ): int {
        $now = now();
        $inserted = 0;

        foreach (array_chunk(array_values(array_unique($emails)), self::CHUNK) as $chunk) {
            $rows = [];

            foreach ($chunk as $email) {
                $rows[] = [
                    'email' => ltrim(mb_strtolower(trim($email)), '@'),
                    'is_domain' => $isDomain,
                    'reason' => $reason,
                    'source' => $source,
                    'customer_id' => $customerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $inserted += DB::table((new GlobalBlacklistEmail)->getTable())->insertOrIgnore($rows);
        }

        return $inserted;
    }
}
