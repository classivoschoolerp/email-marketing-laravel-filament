<?php

declare(strict_types=1);

namespace App\Jobs\Infra;

use App\Models\SendingDomain;
use App\Models\TrackingDomain;
use App\Services\Domains\SendingDomainVerifier;
use App\Services\Domains\TrackingDomainVerifier;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Queued verify-all for domain resources (spec §3.13.5/§3.13.6, WP-15) —
 * backs the admin "verify all" header actions so bulk DNS churn runs on the
 * maintenance queue, not in the UI request. The daily re-verification path
 * is the sending-domains:verify / tracking-domains:verify commands.
 */
class VerifyDomainsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TYPE_SENDING = 'sending';

    public const TYPE_TRACKING = 'tracking';

    public function __construct(public readonly string $type)
    {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(SendingDomainVerifier $sending, TrackingDomainVerifier $tracking): void
    {
        match ($this->type) {
            self::TYPE_SENDING => SendingDomain::withoutTenancy()->chunkById(
                100,
                function ($domains) use ($sending): void {
                    foreach ($domains as $domain) {
                        try {
                            $wasVerified = $domain->status === \App\Enums\DomainStatus::Verified;
                            $sending->verify($domain);
                            $fresh = $domain->fresh();
                            $nowVerified = $fresh->status === \App\Enums\DomainStatus::Verified;

                            // Notify the owner when the outcome changes (F-3.15-010).
                            if ($domain->customer !== null && $nowVerified !== $wasVerified) {
                                \Illuminate\Support\Facades\Mail::to($domain->customer->email)->queue(
                                    new \App\Mail\SendingDomainVerificationMail($fresh->name ?? $fresh->domain, $nowVerified),
                                );
                            }
                        } catch (Throwable $exception) {
                            Log::error('Sending domain verification failed.', [
                                'sending_domain_id' => $domain->id,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                },
            ),
            self::TYPE_TRACKING => TrackingDomain::withoutTenancy()->chunkById(
                100,
                function ($domains) use ($tracking): void {
                    foreach ($domains as $domain) {
                        try {
                            $tracking->verify($domain);
                        } catch (Throwable $exception) {
                            Log::error('Tracking domain verification failed.', [
                                'tracking_domain_id' => $domain->id,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                },
            ),
            default => throw new InvalidArgumentException("Unknown domain verify type [{$this->type}]."),
        };
    }
}
