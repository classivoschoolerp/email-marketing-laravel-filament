<?php

declare(strict_types=1);

namespace App\Sending;

/**
 * Transport-agnostic outbound message DTO — the WP-13 ↔ WP-14 seam. The
 * campaigns engine builds one of these per recipient and hands it to a
 * SendingDriver together with the chosen DeliveryServer; nothing in
 * app/Sending references Campaign classes.
 *
 * Properties are deliberately NOT readonly: instances travel inside queued
 * jobs and readonly promoted props break unserialization (docs/DECISIONS.md D-46).
 */
final class Message
{
    /**
     * @param  array<int|string, string>  $to  list of addresses, or email => display-name map
     * @param  array<string, string>  $headers  header name => value
     * @param  list<array{name: string, content: string, mime?: string}>  $attachments  raw binary content
     * @param  list<array{cid: string, content: string, mime?: string}>  $embedded  inline (CID) images;
     *                                                                   html references them as cid:{cid} (WP-13 embed_images)
     */
    public function __construct(
        public string $fromEmail,
        public ?string $fromName = null,
        public array $to = [],
        public string $subject = '',
        public ?string $html = null,
        public ?string $text = null,
        public array $headers = [],
        public array $attachments = [],
        public ?string $replyTo = null,
        public array $embedded = [],
        /**
         * AMP for Email body (spec §2.5.4 F-2.5.4-014). When set, MimeBuilder
         * adds a `text/x-amp-html` part BEFORE the `text/html` one. Null (the
         * default) means the message is assembled exactly as it always was.
         */
        public ?string $amp = null,
    ) {}

    /**
     * Recipients normalised to email => display-name|null.
     *
     * @return array<string, string|null>
     */
    public function recipients(): array
    {
        $out = [];

        foreach ($this->to as $key => $value) {
            if (is_int($key)) {
                $out[$value] = null;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** First recipient address (most drivers send one message per recipient). */
    public function firstRecipient(): ?string
    {
        return array_key_first($this->recipients());
    }
}
