<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Models\DeliveryServer;
use App\Sending\Message;
use App\Sending\MimeBuilder;
use App\Sending\SendResult;
use App\Sending\Support\NativePhpMailer;
use App\Sending\Support\PhpMailer;
use Throwable;

/**
 * PHP mail() driver (spec §2.9.2 F-2.9.2-014, D-21 key "php-mail"). Builds
 * the full MIME message (MimeBuilder — Force-FROM/REPLY-TO, custom headers,
 * DKIM) and hands it to the PhpMailer seam: the To and Subject headers are
 * split out (mail() writes those itself), everything else travels in the
 * additional-headers block. Optional config.params adds sendmail params
 * (e.g. "-fbounces@example.com").
 *
 * Transient-vs-permanent classification:
 *  | condition                     | classification |
 *  |-------------------------------|----------------|
 *  | mail() returned false (local  | transient      |
 *  |   MTA/spool unavailable)      | transient      |
 *  | message build error           | permanent      |
 */
class PhpMailDriver extends AbstractDriver
{
    private readonly PhpMailer $mailer;

    public function __construct(MimeBuilder $mime, ?PhpMailer $mailer = null)
    {
        parent::__construct($mime);

        $this->mailer = $mailer
            ?? (app()->bound(PhpMailer::class) ? app(PhpMailer::class) : new NativePhpMailer);
    }

    public function key(): string
    {
        return 'php-mail';
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        try {
            $raw = $this->mime->build($message, $server)->toString();

            [$headerBlock, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');

            [$headers, $subject] = $this->splitHeaders($headerBlock);

            $ok = $this->mailer->mail(
                implode(', ', array_keys($message->recipients())),
                $subject,
                $body,
                $headers,
                (string) $server->configValue('params', ''),
            );

            return $ok
                ? SendResult::success()
                : SendResult::transientFailure(__('sending.errors.php_mail_failed'));
        } catch (Throwable $e) {
            return SendResult::permanentFailure($e->getMessage());
        }
    }

    /**
     * Remove To/Subject from the header block (mail() writes them) and return
     * [remaining headers, subject value]. Folded headers are unfolded before
     * matching so multi-line encoded subjects survive.
     *
     * @return array{0: string, 1: string}
     */
    private function splitHeaders(string $headerBlock): array
    {
        $unfolded = [];

        foreach (explode("\r\n", $headerBlock) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $unfolded !== []) {
                $unfolded[count($unfolded) - 1] .= ' '.ltrim($line);

                continue;
            }

            $unfolded[] = $line;
        }

        $subject = '';
        $kept = [];

        foreach ($unfolded as $line) {
            if (stripos($line, 'Subject:') === 0) {
                $subject = trim(substr($line, 8));

                continue;
            }

            if (stripos($line, 'To:') === 0) {
                continue;
            }

            $kept[] = $line;
        }

        return [implode("\r\n", $kept), $subject];
    }

    public function validateConfig(DeliveryServer $server): array
    {
        return []; // nothing to configure — uses the host PHP mail setup
    }

    public function capabilities(): array
    {
        return [
            'transport' => 'php-mail',
            'attachments' => true,
            'custom_headers' => true,
            'dkim' => true,
        ];
    }

    public function formSchema(): array
    {
        return [
            $this->field('config.params', 'text', ['help' => 'sending.help.php_mail_params']),
        ];
    }
}
