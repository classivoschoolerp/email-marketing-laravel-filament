<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\SpintaxSnippetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Saved Spintax snippet (spec §2.3.3 F-2.3.3-023) — a reusable `{a|b|c}`
 * fragment insertable into any campaign's content. Tenant-scoped.
 */
class SpintaxSnippet extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<SpintaxSnippetFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $snippet): void {
            $snippet->uid ??= (string) Str::uuid();
        });
    }
}
