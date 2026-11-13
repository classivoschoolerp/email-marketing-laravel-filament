<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BounceRuleResource\Pages;

use App\Filament\Admin\Resources\BounceRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBounceRule extends CreateRecord
{
    protected static string $resource = BounceRuleResource::class;
}
