<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailBlockRequestResource\Pages;

use App\Filament\Admin\Resources\EmailBlockRequestResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The §3.19 block-request queue. The subheading surfaces the auto-approve
 * setting state (F-3.19-003, config-backed — see config/emp-public.php).
 */
class ListEmailBlockRequests extends ListRecords
{
    protected static string $resource = EmailBlockRequestResource::class;

    public function getSubheading(): ?string
    {
        return config('emp-public.block_requests.auto_approve')
            ? __('public.block_requests.admin.auto_approve_on')
            : __('public.block_requests.admin.auto_approve_off');
    }
}
