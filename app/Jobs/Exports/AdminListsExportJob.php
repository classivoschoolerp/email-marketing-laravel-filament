<?php

declare(strict_types=1);

namespace App\Jobs\Exports;

use App\Jobs\Concerns\IdempotentJob;
use App\Mail\ExportReadyMail;
use App\Models\EmailList;
use App\Models\User;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use League\Csv\Writer;

/**
 * Queued CSV export of the §3.6 lists overview (F-3.6-005, WP-18): every
 * list across all customers with its per-status subscriber counts. Mirrors
 * the WP-17 admin log export flow — file on the private disk, signed
 * expiring download link mailed to the requesting admin, permission
 * re-checked by the download controller on top of the signature.
 */
class AdminListsExportJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public const DIRECTORY = 'admin/overview-exports';

    public int $timeout = 3600;

    public function __construct(
        public readonly int $userId,
        public readonly string $token,
    ) {
        $this->onQueue(Queues::EXPORTS);
    }

    public static function relativePath(string $token): string
    {
        return self::DIRECTORY.'/lists-'.$token.'.csv';
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);

        $path = self::relativePath($this->token);
        $writer = Writer::createFromPath($disk->path($path), 'w+');

        $writer->insertOne(array_map(
            fn (string $key): string => __('dashboards.lists_overview.export.headers.'.$key),
            ['customer', 'name', 'uid', 'total', 'confirmed', 'unconfirmed', 'unsubscribed', 'bounced', 'opt_in', 'opt_out', 'visible', 'created_at'],
        ));

        $lists = EmailList::withoutTenancy()
            ->with('customer')
            ->withCount([
                'subscribers as subscribers_count',
                'subscribers as confirmed_count' => fn ($q) => $q->where('status', 'confirmed'),
                'subscribers as unconfirmed_count' => fn ($q) => $q->where('status', 'unconfirmed'),
                'subscribers as unsubscribed_count' => fn ($q) => $q->where('status', 'unsubscribed'),
                'subscribers as bounced_count' => fn ($q) => $q->where('status', 'bounced'),
            ])
            ->orderBy('id');

        foreach ($lists->cursor() as $list) {
            $writer->insertOne([
                $list->customer?->email,
                $list->name,
                $list->uid,
                (int) $list->subscribers_count,
                (int) $list->confirmed_count,
                (int) $list->unconfirmed_count,
                (int) $list->unsubscribed_count,
                (int) $list->bounced_count,
                $list->opt_in->value,
                $list->opt_out->value,
                $list->visible ? '1' : '0',
                (string) $list->created_at,
            ]);
        }

        $expiresAt = now()->addDays(7);

        Mail::to($user->email)->send(new ExportReadyMail(
            __('dashboards.lists_overview.export.name'),
            URL::temporarySignedRoute('overviews.lists-export.download', $expiresAt, [
                'token' => $this->token,
            ]),
            $expiresAt->toDayDateTimeString(),
        ));
    }

    public function idempotencyKey(): string
    {
        return 'admin-lists-export:'.$this->token;
    }
}
