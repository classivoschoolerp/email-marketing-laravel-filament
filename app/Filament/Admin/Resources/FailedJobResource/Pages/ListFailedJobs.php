<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FailedJobResource\Pages;

use App\Filament\Admin\Resources\FailedJobResource;
use Filament\Resources\Pages\ListRecords;

/** Failed queue jobs index — see FailedJobResource (F-0.4-003 / F-3.27-012). */
class ListFailedJobs extends ListRecords
{
    protected static string $resource = FailedJobResource::class;
}
