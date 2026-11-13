<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BounceServerResource\Pages;

use App\Filament\Admin\Resources\BounceServerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBounceServer extends CreateRecord
{
    protected static string $resource = BounceServerResource::class;
}
