<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Sending\MimeBuilder;
use App\Sending\SendingDriver;

/**
 * Shared plumbing for built-in drivers: the MimeBuilder (Force-FROM /
 * Force-REPLY-TO / custom headers / DKIM policy) and formSchema() helpers.
 */
abstract class AbstractDriver implements SendingDriver
{
    public function __construct(protected readonly MimeBuilder $mime) {}

    public function label(): string
    {
        return __('sending.types.'.$this->key());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function field(string $name, string $type, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'type' => $type,
            'label' => 'sending.fields.'.str_replace('.', '_', $name),
            'required' => false,
            'options' => [],
            'default' => null,
            'help' => null,
        ], $overrides);
    }
}
