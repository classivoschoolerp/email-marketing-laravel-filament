<?php

declare(strict_types=1);

namespace App\Services\Imports\Esp;

use App\Services\Imports\ImportFailedException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared plumbing for the core ESP adapters (spec §2.2.7 F-2.2.7-006):
 * a JSON client with a sane timeout, credential presence checks, and the
 * rule that a vendor failure becomes an ImportFailedException carrying a
 * TRANSLATED message — RunImportJob turns that into a failed import row
 * with a readable reason instead of a stack trace.
 */
abstract class AbstractEspImporter implements EspImporter
{
    protected const TIMEOUT_SECONDS = 60;

    /** Contacts requested per page. */
    protected const PAGE_SIZE = 500;

    /**
     * @param  array<string, string>  $credentials
     *
     * @throws ImportFailedException
     */
    protected function credential(array $credentials, string $key): string
    {
        $value = trim((string) ($credentials[$key] ?? ''));

        if ($value === '') {
            throw new ImportFailedException(__('imports.errors.esp_credential_missing', [
                'esp' => $this->name(),
                'field' => __('imports.form.esp_'.$key),
            ]));
        }

        return $value;
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)->acceptJson();
    }

    /**
     * Run a vendor call, mapping transport failures and non-2xx responses to
     * a translated ImportFailedException.
     *
     * @param  callable(): Response  $call
     *
     * @throws ImportFailedException
     */
    protected function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ImportFailedException $e) {
            // Already a translated, operator-readable reason — never re-wrap.
            throw $e;
        } catch (Throwable $e) {
            throw new ImportFailedException(__('imports.errors.esp_unreachable', [
                'esp' => $this->name(),
                'message' => $e->getMessage(),
            ]));
        }

        if (! $response->successful()) {
            throw new ImportFailedException(__('imports.errors.esp_http', [
                'esp' => $this->name(),
                'status' => $response->status(),
            ]));
        }

        return $response;
    }

    /**
     * Normalise a vendor attribute bag to flat `header => string` cells.
     * Nested/array values are joined so they survive the CSV hand-off; empty
     * values are dropped so they never overwrite an existing subscriber
     * field with a blank on an update-on-duplicate run.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    protected function flatten(array $attributes): array
    {
        $fields = [];

        foreach ($attributes as $key => $value) {
            $key = trim((string) $key);

            if ($key === '' || is_object($value)) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(' ', array_map(
                    static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
                    $value,
                ));
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif ($value === null) {
                $value = '';
            } elseif (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $fields[$key] = $value;
        }

        return $fields;
    }
}
