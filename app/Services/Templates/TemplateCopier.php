<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Models\Customer;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Duplicates templates (spec §2.5.1 "duplicate") and copies gallery templates
 * into a customer account (spec §3.14 — "use this" is copy, never edit).
 * Asset files are copied to the new template's directory and URLs inside the
 * content are rewritten so the copy survives deletion of the source.
 */
class TemplateCopier
{
    public function __construct(private readonly TemplateQuota $quota) {}

    /** Duplicate within the same ownership (customer dup or admin dup of a system row). */
    public function duplicate(EmailTemplate $source, ?string $name = null): EmailTemplate
    {
        if (! $source->isSystem()) {
            $owner = $source->customer()->withoutGlobalScopes()->first();
            $this->quota->assertCanCreate($owner);
        }

        return $this->copyRow($source, $source->customer_id, $name ?? $this->copyName($source->name), [
            'is_default' => false,
            'is_gallery' => false,
            'is_featured' => false,
            'gallery_customer_groups' => null,
        ]);
    }

    /** Copy a gallery template into the given customer's account (read-only source). */
    public function copyToCustomer(EmailTemplate $source, Customer $customer, ?string $name = null): EmailTemplate
    {
        $this->quota->assertCanCreate($customer);

        return $this->copyRow($source, $customer->id, $name ?? $source->name, [
            'category_id' => null, // system categories are not customer categories
            'is_default' => false,
            'is_gallery' => false,
            'is_featured' => false,
            'gallery_customer_groups' => null,
            'sort_order' => 0,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function copyRow(EmailTemplate $source, ?int $customerId, string $name, array $overrides): EmailTemplate
    {
        $copy = $source->replicate(['uid', 'deleted_at']);
        $copy->uid = (string) Str::uuid();
        $copy->customer_id = $customerId;
        $copy->name = $name;
        $copy->forceFill($overrides);
        $copy->save();

        $this->copyAssets($source, $copy);

        return $copy->refresh();
    }

    private function copyAssets(EmailTemplate $source, EmailTemplate $copy): void
    {
        $disk = Storage::disk('public');
        $sourceDir = $source->assetDirectory();
        $copyDir = $copy->assetDirectory();

        foreach ($disk->allFiles($sourceDir) as $file) {
            $disk->copy($file, $copyDir.Str::after($file, $sourceDir));
        }

        // Rewrite asset URLs (and any stored paths) to the new directory.
        foreach (['content_html', 'content_json', 'thumbnail_path'] as $field) {
            $value = $copy->getAttribute($field);

            if (is_string($value) && $value !== '') {
                $copy->setAttribute($field, str_replace($sourceDir, $copyDir, $value));
            }
        }

        $copy->saveQuietly();
    }

    private function copyName(string $name): string
    {
        return Str::limit($name, 240, '').' '.__('templates.copy_suffix');
    }
}
