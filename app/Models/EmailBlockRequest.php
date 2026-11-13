<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BlockRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A public "never email me again" request (spec §1.10 / §3.19). Global —
 * block requests are install-wide, not tenant-scoped. `uid` backs the signed
 * confirmation URL emailed to the requester.
 */
class EmailBlockRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => BlockRequestStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->uid ??= (string) Str::uuid();
            $request->email = mb_strtolower(trim((string) $request->email));
        });
    }
}
