<?php

declare(strict_types=1);

namespace App\Mail\Upgrade;

use App\Mail\Ops\ThresholdAlertMail;
use App\Services\Upgrade\ReleaseInfo;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * §6.2 F-6.2-008 — "a new release is available" notice for operators (WP-41).
 *
 * Same reasoning as {@see ThresholdAlertMail}: this is operator
 * mail, not a §3.15 customer lifecycle template, so it does not go through
 * SystemEmailRenderer and keeps working when the settings tables cannot be
 * read. Strings live in lang/en/upgrade.php.
 *
 * Promoted constructor properties must not be readonly on a queued Mailable
 * (D-46) — hence the plain public properties.
 */
class NewReleaseAvailableMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /** @var array<string, mixed> */
    public array $release;

    public string $currentVersion;

    /** @param array<string, mixed> $release {@see ReleaseInfo::toArray()} */
    public function __construct(array $release, string $currentVersion)
    {
        $this->release = $release;
        $this->currentVersion = $currentVersion;
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('upgrade.mail.subject', [
            'app' => (string) config('app.name'),
            'version' => (string) ($this->release['version'] ?? '?'),
        ]));
    }

    public function content(): Content
    {
        return new Content(view: 'upgrade.mail.new-release', with: [
            'release' => $this->release,
            'currentVersion' => $this->currentVersion,
            'appName' => (string) config('app.name'),
            'installable' => ($this->release['download_url'] ?? null) !== null
                && ($this->release['checksum'] ?? null) !== null,
        ]);
    }
}
