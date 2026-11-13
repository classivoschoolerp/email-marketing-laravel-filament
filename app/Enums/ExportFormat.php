<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * F-2.3.8-018 / F-2.2.8 export file formats (WP-26). CSV stays the default
 * everywhere — it round-trips through the importer and streams at any size.
 * XLSX is written with openspout (also streaming, one row at a time).
 */
enum ExportFormat: string
{
    case Csv = 'csv';

    case Xlsx = 'xlsx';

    public function label(): string
    {
        return __('reports.export.formats.'.$this->value);
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** Never throws — an unknown/legacy value falls back to CSV. */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom(strtolower((string) $value)) ?? self::Csv;
    }

    /** MIME for a stored file extension (download controller). */
    public static function mimeForExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'zip' => 'application/zip',
            'xlsx' => self::Xlsx->mimeType(),
            default => self::Csv->mimeType(),
        };
    }
}
