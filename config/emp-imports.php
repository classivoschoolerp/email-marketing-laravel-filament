<?php

declare(strict_types=1);

use App\Services\Imports\Esp\Importers\BrevoImporter;
use App\Services\Imports\Esp\Importers\ConstantContactImporter;
use App\Services\Imports\Esp\Importers\KlaviyoImporter;
use App\Services\Imports\Esp\Importers\MailchimpImporter;

/*
|--------------------------------------------------------------------------
| Subscriber import module (WP-42, spec §2.2.7)
|--------------------------------------------------------------------------
| Credential model: ESP API keys / OAuth tokens are entered per import run
| and stored ENCRYPTED inside import_jobs.options — they are per-tenant,
| per-run secrets, so there is no shared operator credential and no
| dedicated table. Re-running an import reuses the stored, still-encrypted
| credentials.
*/

return [

    'esp' => [

        /*
         * Registry map for "Import from other ESPs" (F-2.2.7-006), the same
         * plugin-registrable shape as emp-verification.providers: a plugin
         * merges a key => ['class' => …] entry from its service provider, or
         * resolves EspImporterRegistry and calls register() at boot.
         */
        'importers' => [
            'mailchimp' => ['class' => MailchimpImporter::class],
            'brevo' => ['class' => BrevoImporter::class],
            'constant_contact' => ['class' => ConstantContactImporter::class],
            'klaviyo' => ['class' => KlaviyoImporter::class],
        ],

        /*
         * Hard stop on the paging loop so a vendor that never stops handing
         * out cursors cannot spin a queue worker forever.
         */
        'max_pages' => (int) env('EMP_IMPORT_ESP_MAX_PAGES', 5000),
    ],

];
