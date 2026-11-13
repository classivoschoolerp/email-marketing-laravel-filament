<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ActivityLog;
use App\Models\ApiRequestLog;
use App\Models\CampaignBounceLog;
use App\Models\CampaignComplaintLog;
use App\Models\CampaignDeliveryLog;
use App\Models\LoginLog;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use RuntimeException;

/**
 * Queued CSV exports for the §3.27 admin log resources (F-3.27-015 "export
 * logs", WP-17). Writes to the private disk under admin/log-exports/; the
 * file is served by the signed reports.admin-logs.download route, which
 * re-checks the `logs.view` permission on top of the signature. Rows stream
 * through cursor() so memory stays flat on large log tables.
 */
class AdminLogExporter
{
    /**
     * WP-40 extended the registry with the three §3.27 P2 viewers
     * (F-3.27-009/010/011) so "export logs" (F-3.27-015) covers every log
     * screen rather than only the four WP-17 shipped.
     */
    public const TYPES = [
        'delivery', 'bounces', 'complaints', 'logins',
        'api_requests', 'webhook_deliveries', 'audit',
    ];

    public const DIRECTORY = 'admin/log-exports';

    public static function relativePath(string $type, string $token): string
    {
        return self::DIRECTORY.'/'.$type.'-'.$token.'.csv';
    }

    /** Writes the CSV and returns [path, rows]. @return array{string, int} */
    public function write(string $type, string $token): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Unknown admin log export type: '.$type);
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);

        $path = self::relativePath($type, $token);
        $writer = Writer::createFromPath($disk->path($path), 'w+');

        $writer->insertOne(array_map(
            fn (string $key): string => __('reports.export.headers.'.$key),
            $this->headerKeys($type),
        ));

        $rows = 0;

        foreach ($this->rows($type) as $row) {
            $writer->insertOne($row);
            $rows++;
        }

        return [$path, $rows];
    }

    /** @return list<string> */
    private function headerKeys(string $type): array
    {
        return match ($type) {
            'delivery' => ['campaign', 'subscriber', 'server', 'status', 'message_id', 'response', 'retries', 'date'],
            'bounces' => ['campaign', 'subscriber', 'bounce_type', 'message_id', 'message', 'date'],
            'complaints' => ['campaign', 'subscriber', 'feedback_type', 'message_id', 'message', 'date'],
            'logins' => ['guard', 'email', 'ip', 'user_agent', 'result', 'date'],
            'api_requests' => ['date', 'customer', 'api_key', 'method', 'path', 'status', 'duration_ms', 'ip'],
            'webhook_deliveries' => ['date', 'customer', 'event', 'url', 'status', 'response_status', 'attempts', 'duration_ms', 'error'],
            'audit' => ['date', 'customer', 'actor', 'action', 'subject', 'ip', 'old_values', 'new_values'],
            default => throw new RuntimeException('Unknown admin log export type: '.$type),
        };
    }

    /** @return \Generator<int, list<int|string|null>> */
    private function rows(string $type): \Generator
    {
        switch ($type) {
            case 'delivery':
                foreach (CampaignDeliveryLog::query()->with(['campaign', 'subscriber', 'deliveryServer'])->orderBy('id')->cursor() as $log) {
                    yield [
                        $log->campaign?->name,
                        $log->subscriber?->email,
                        $log->deliveryServer?->name,
                        (string) $log->status,
                        $log->message_id,
                        $log->response,
                        (int) $log->retries,
                        (string) $log->created_at,
                    ];
                }
                break;

            case 'bounces':
                foreach (CampaignBounceLog::query()->with(['campaign', 'subscriber'])->orderBy('id')->cursor() as $log) {
                    yield [
                        $log->campaign?->name,
                        $log->subscriber?->email ?? $log->email,
                        $log->bounce_type->value,
                        $log->message_id,
                        $log->message,
                        (string) $log->created_at,
                    ];
                }
                break;

            case 'complaints':
                foreach (CampaignComplaintLog::query()->with(['campaign', 'subscriber'])->orderBy('id')->cursor() as $log) {
                    yield [
                        $log->campaign?->name,
                        $log->subscriber?->email ?? $log->email,
                        $log->feedback_type,
                        $log->message_id,
                        $log->message,
                        (string) $log->created_at,
                    ];
                }
                break;

            case 'logins':
                foreach (LoginLog::query()->orderBy('id')->cursor() as $log) {
                    yield [
                        (string) $log->guard,
                        $log->email,
                        $log->ip,
                        $log->user_agent,
                        $log->result->value,
                        (string) $log->created_at,
                    ];
                }
                break;

                // WP-40 §3.27 P2 viewers. api_request_logs carries no tenant
                // scope by design (WP-19); the other two are unscoped explicitly.
            case 'api_requests':
                foreach (ApiRequestLog::query()->with(['apiKey', 'customer'])->orderBy('id')->cursor() as $log) {
                    yield [
                        (string) $log->created_at,
                        $log->customer?->email,
                        $log->apiKey?->name,
                        (string) $log->method,
                        (string) $log->path,
                        (int) $log->status,
                        (int) $log->duration_ms,
                        $log->ip,
                    ];
                }
                break;

            case 'webhook_deliveries':
                foreach (WebhookDelivery::withoutTenancy()->with('customer')->orderBy('id')->cursor() as $delivery) {
                    yield [
                        (string) $delivery->created_at,
                        $delivery->customer?->email,
                        (string) $delivery->event,
                        (string) $delivery->url,
                        (string) $delivery->status,
                        $delivery->response_status,
                        (int) $delivery->attempts,
                        $delivery->duration_ms,
                        $delivery->error,
                    ];
                }
                break;

            case 'audit':
                foreach (ActivityLog::withoutTenancy()->with('customer')->orderBy('id')->cursor() as $entry) {
                    yield [
                        (string) $entry->created_at,
                        $entry->customer?->email,
                        $entry->actorLabel(),
                        (string) $entry->action,
                        $entry->subject_label,
                        $entry->ip,
                        $entry->old_values === null ? null : json_encode($entry->old_values),
                        $entry->new_values === null ? null : json_encode($entry->new_values),
                    ];
                }
                break;
        }
    }
}
