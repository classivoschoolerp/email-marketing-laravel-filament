<?php

declare(strict_types=1);

namespace App\Mail;

use App\Jobs\Imports\RunImportJob;
use App\Services\SystemEmails\RenderedSystemEmail;
use App\Services\SystemEmails\SystemEmailRenderer;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Import summary mail (spec §3.15 F-3.15-011, WP-20): queued to the list
 * owner when a subscriber import finishes
 * ({@see RunImportJob}). Scalar constructor — no tenant
 * context needed on the worker; properties NOT readonly (D-46). Rendered
 * through SystemEmailRenderer (key `import-finished`, admin-overridable).
 */
class ImportFinishedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $listName;

    public int $imported;

    public int $updated;

    public int $skipped;

    public int $failed;

    public function __construct(string $listName, int $imported, int $updated, int $skipped, int $failed)
    {
        $this->listName = $listName;
        $this->imported = $imported;
        $this->updated = $updated;
        $this->skipped = $skipped;
        $this->failed = $failed;
        $this->onQueue(Queues::TRANSACTIONAL);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->rendered()->subject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->rendered()->html);
    }

    private function rendered(): RenderedSystemEmail
    {
        return app(SystemEmailRenderer::class)->resolve('import-finished', [
            'LIST_NAME' => $this->listName,
            'IMPORTED' => number_format($this->imported),
            'UPDATED' => number_format($this->updated),
            'SKIPPED' => number_format($this->skipped),
            'FAILED' => number_format($this->failed),
        ]);
    }
}
