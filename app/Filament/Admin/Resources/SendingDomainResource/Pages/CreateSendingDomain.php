<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SendingDomainResource\Pages;

use App\Filament\Admin\Resources\SendingDomainResource;
use App\Services\Dkim\GenerateDkimKeypair;
use Filament\Resources\Pages\CreateRecord;

/**
 * Admin create (spec §2.9.7 F-2.9.7-001): the DKIM RSA-2048 keypair is
 * generated server-side on save (GenerateDkimKeypair with the D-37 bundled
 * openssl.cnf fallback); the private key lands in the encrypted column.
 */
class CreateSendingDomain extends CreateRecord
{
    protected static string $resource = SendingDomainResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $pair = app(GenerateDkimKeypair::class)();

        $data['dkim_private_key'] = $pair['private_key'];
        $data['dkim_public_key'] = $pair['public_key'];

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return SendingDomainResource::getUrl('view', ['record' => $this->record]);
    }
}
