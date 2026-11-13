<?php

declare(strict_types=1);

namespace App\Services\Suppression;

use App\Models\EmailList;
use App\Models\SuppressionList;
use App\Models\SuppressionListEmail;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

/**
 * Mutation helpers for suppression-list members (§2.9.11): pasted-blob
 * parsing, chunked duplicate-safe inserts, copy-from-EmailList, and the
 * maintained emails_count counter (F-2.9.11-005). The queued import/export
 * jobs and the Filament actions all funnel through here.
 */
class SuppressionListEmails
{
    private const CHUNK = 500;

    /**
     * Split a pasted blob and keep valid, lower-cased, unique addresses.
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
     * Add addresses, ignoring duplicates. Returns rows actually inserted.
     *
     * @param  list<string>  $emails
     */
    public function addMany(SuppressionList $list, array $emails): int
    {
        $now = now();
        $inserted = 0;

        foreach (array_chunk(array_values(array_unique($emails)), self::CHUNK) as $chunk) {
            $rows = [];

            foreach ($chunk as $email) {
                $email = mb_strtolower(trim($email));

                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                $rows[] = [
                    'suppression_list_id' => $list->id,
                    'email' => $email,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                $inserted += DB::table((new SuppressionListEmail)->getTable())->insertOrIgnore($rows);
            }
        }

        $this->refreshCount($list);

        return $inserted;
    }

    /**
     * Copy every address of an EmailList into the suppression list
     * (F-2.9.11-002 "import from list"). Returns rows actually inserted.
     */
    public function importFromEmailList(SuppressionList $list, EmailList $source): int
    {
        $inserted = 0;

        Subscriber::withoutTenancy()
            ->where('list_id', $source->id)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($subscribers) use ($list, &$inserted): void {
                $inserted += $this->addManyWithoutRecount($list, $subscribers->pluck('email')->all());
            });

        $this->refreshCount($list);

        return $inserted;
    }

    public function refreshCount(SuppressionList $list): void
    {
        SuppressionList::withoutTenancy()
            ->whereKey($list->id)
            ->update(['emails_count' => $list->emails()->count()]);
    }

    /** @param list<string> $emails */
    private function addManyWithoutRecount(SuppressionList $list, array $emails): int
    {
        $now = now();
        $rows = [];

        foreach (array_unique($emails) as $email) {
            $email = mb_strtolower(trim((string) $email));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $rows[] = [
                    'suppression_list_id' => $list->id,
                    'email' => $email,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows === []
            ? 0
            : DB::table((new SuppressionListEmail)->getTable())->insertOrIgnore($rows);
    }
}
