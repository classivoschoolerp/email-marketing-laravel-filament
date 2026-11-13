<?php

declare(strict_types=1);

namespace App\Services\SpamCheck;

use Throwable;

/**
 * SpamAssassin pre-send checker (spec §2.9.2 F-2.9.2-024, D-08). Speaks the
 * spamd protocol (SPAMC/1.5, protocol.txt in the SpamAssassin distribution)
 * over the SpamdClient socket seam.
 *
 * Request framing (REPORT gives verdict + score + rule breakdown in one
 * round trip):
 *
 *   REPORT SPAMC/1.5\r\n
 *   Content-length: <bytes>\r\n
 *   \r\n
 *   <raw RFC 2822 message>
 *
 * Response framing:
 *
 *   SPAMD/1.1 0 EX_OK\r\n
 *   Content-length: <bytes>\r\n
 *   Spam: True ; 7.5 / 5.0\r\n
 *   \r\n
 *   <human-readable report with the "pts rule name description" table>
 *
 * The rule table lines (" 1.2 MISSING_HEADERS  Missing To: header") are
 * parsed into the structured breakdown. Block decision: score >= the
 * platform threshold (constructor arg, default config
 * emp-sending.spamassassin.threshold) — spamd's own required_score only
 * feeds the isSpam verdict.
 *
 * An unreachable daemon fails OPEN (checked=false, blocked=false) with the
 * error recorded — a monitoring concern, not a send blocker (D-08: no
 * Windows daemon locally).
 */
class SpamAssassinChecker implements SpamCheckerInterface
{
    public const PROTOCOL_VERSION = 'SPAMC/1.5';

    public function __construct(
        private readonly SpamdClient $client,
        private readonly ?float $blockThreshold = null,
    ) {}

    public function check(string $rawMessage): SpamCheckResult
    {
        try {
            $response = $this->client->exchange($this->buildRequest('REPORT', $rawMessage));

            return $this->parseResponse($response);
        } catch (Throwable $e) {
            return SpamCheckResult::unavailable($e->getMessage());
        }
    }

    /** Exact SPAMC request bytes — public for protocol-framing tests. */
    public function buildRequest(string $command, string $rawMessage): string
    {
        return $command.' '.self::PROTOCOL_VERSION."\r\n"
            .'Content-length: '.strlen($rawMessage)."\r\n"
            ."\r\n"
            .$rawMessage;
    }

    /** Parse a raw SPAMD response — public for transcript tests. */
    public function parseResponse(string $response): SpamCheckResult
    {
        [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');

        $lines = explode("\r\n", $head);
        $statusLine = array_shift($lines) ?? '';

        if (preg_match('/^SPAMD\/[\d.]+\s+(\d+)\s+(.*)$/', $statusLine, $status) !== 1) {
            return SpamCheckResult::unavailable('Malformed spamd status line: '.$statusLine);
        }

        if ((int) $status[1] !== 0) {
            return SpamCheckResult::unavailable('spamd error '.$status[1].' '.$status[2]);
        }

        $isSpam = false;
        $score = 0.0;
        $required = 5.0;
        $sawSpamHeader = false;

        foreach ($lines as $line) {
            if (preg_match('/^Spam:\s*(True|False|Yes|No)\s*;\s*(-?[\d.]+)\s*\/\s*(-?[\d.]+)/i', $line, $m) === 1) {
                $sawSpamHeader = true;
                $isSpam = in_array(strtolower($m[1]), ['true', 'yes'], true);
                $score = (float) $m[2];
                $required = (float) $m[3];
            }
        }

        if (! $sawSpamHeader) {
            return SpamCheckResult::unavailable('spamd response missing Spam header');
        }

        return new SpamCheckResult(
            checked: true,
            score: $score,
            requiredScore: $required,
            isSpam: $isSpam,
            rules: $this->parseRules($body),
            blocked: $score >= $this->threshold(),
            report: $body !== '' ? $body : null,
        );
    }

    /**
     * Rule table from the REPORT body.
     *
     * @return list<array{points: float, rule: string, description: string}>
     */
    private function parseRules(string $body): array
    {
        $rules = [];
        $collect = false;

        foreach (preg_split('/\r\n|\n/', $body) ?: [] as $line) {
            // The table starts after the "pts rule name description" header
            // and its ---- underline; tolerate reports without the header.
            if (preg_match('/^\s*pts\s+rule name/i', $line) === 1 || preg_match('/^-{2,}\s+-{2,}/', $line) === 1) {
                $collect = true;

                continue;
            }

            if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s+([A-Z][A-Z0-9_]+)\s+(.+)$/', $line, $m) === 1) {
                $rules[] = [
                    'points' => (float) $m[1],
                    'rule' => $m[2],
                    'description' => trim($m[3]),
                ];
            } elseif ($collect && trim($line) === '' && $rules !== []) {
                break; // table finished
            }
        }

        return $rules;
    }

    public function threshold(): float
    {
        return $this->blockThreshold
            ?? (float) config('emp-sending.spamassassin.threshold', 5.0);
    }
}
