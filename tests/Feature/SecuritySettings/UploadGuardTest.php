<?php

declare(strict_types=1);

use App\Models\EmailTemplate;
use App\Services\Security\ClamAvSocketScanner;
use App\Services\Security\NullVirusScanner;
use App\Services\Security\ScanResult;
use App\Services\Security\UploadGuard;
use App\Services\Security\UploadRejectedException;
use App\Services\Security\VirusScanner;
use App\Services\Templates\TemplateZipImporter;
use App\Settings\SecuritySettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/*
 * WP-22 — F-3.25.15-006: file upload scanning / allowed types through the
 * central UploadGuard, wired as proof into the template ZIP import path.
 */

function tempZip(string $name = 'pack.zip', string $html = '<html><body>hi</body></html>'): string
{
    $path = sys_get_temp_dir().'/'.uniqid('emp-upload-', true).'-'.$name;

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('index.html', $html);
    $zip->close();

    return $path;
}

it('accepts an allowed extension and rejects one outside the allowlist', function (): void {
    $guard = app(UploadGuard::class);

    expect($guard->check(UploadedFile::fake()->create('subscribers.csv', 10, 'text/csv')))->toBe([])
        ->and($guard->check(UploadedFile::fake()->create('backup.iso', 10)))->not->toBe([]);
});

// Executable types are blocked no matter what the allowlist says.
it('always blocks executable extensions', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->upload_allowed_extensions = ['php', 'csv'];
    $settings->save();

    $errors = app(UploadGuard::class)->check(UploadedFile::fake()->create('shell.php', 1));

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('php');
});

it('enforces the size cap', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->upload_max_size_mb = 1;
    $settings->save();

    $guard = app(UploadGuard::class);

    expect($guard->passes(UploadedFile::fake()->create('small.csv', 100, 'text/csv')))->toBeTrue()
        ->and($guard->passes(UploadedFile::fake()->create('big.csv', 4096, 'text/csv')))->toBeFalse();
});

it('rejects a mime type that contradicts the extension', function (): void {
    $errors = app(UploadGuard::class)
        ->check(UploadedFile::fake()->create('picture.png', 4, 'application/x-msdownload'));

    expect($errors)->not->toBe([]);
});

it('throws with every message from validate()', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->upload_max_size_mb = 1;
    $settings->save();

    expect(fn () => app(UploadGuard::class)->validate(UploadedFile::fake()->create('big.iso', 4096)))
        ->toThrow(UploadRejectedException::class);
});

// F-3.25.15-006 (scanner selection)
it('selects the configured scanner driver', function (): void {
    $guard = app(UploadGuard::class);

    expect($guard->scanner())->toBeInstanceOf(NullVirusScanner::class);

    $settings = app(SecuritySettings::class);
    $settings->upload_scan_enabled = true;
    $settings->upload_scanner_driver = 'clamav';
    $settings->save();

    expect($guard->scanner())->toBeInstanceOf(ClamAvSocketScanner::class);
});

// Fail-open contract: an unreachable daemon never blocks an upload.
it('never blocks when the scanner daemon is unreachable', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->upload_scan_enabled = true;
    $settings->upload_scanner_driver = 'clamav';
    $settings->upload_scanner_host = '127.0.0.1';
    $settings->upload_scanner_port = 1; // nothing listens here
    $settings->save();

    $result = app(UploadGuard::class)->scan(__FILE__);

    expect($result->scanned)->toBeFalse()
        ->and($result->infected)->toBeFalse();
});

it('rejects a file the scanner reports as infected', function (): void {
    app()->bind(UploadGuard::class, fn (): UploadGuard => new class extends UploadGuard
    {
        public function scanner(): VirusScanner
        {
            return new class implements VirusScanner
            {
                public function key(): string
                {
                    return 'fake';
                }

                public function scan(string $absolutePath): ScanResult
                {
                    return ScanResult::infected('Eicar-Test-Signature');
                }
            };
        }
    });

    $errors = app(UploadGuard::class)->check(UploadedFile::fake()->create('payload.csv', 1, 'text/csv'));

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('Eicar-Test-Signature');
});

// F-3.25.15-006 — the ONE representative upload path is guarded end to end.
it('lets an allowed zip through the template import path', function (): void {
    $template = EmailTemplate::factory()->create();
    $zip = tempZip();

    app(TemplateZipImporter::class)->import($template, $zip);

    expect($template->fresh()->content_html)->toContain('hi');

    @unlink($zip);
});

it('blocks the template import when the zip extension is not allowed', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->upload_allowed_extensions = ['csv'];
    $settings->save();

    $template = EmailTemplate::factory()->create();
    $zip = tempZip();

    expect(fn () => app(TemplateZipImporter::class)->import($template, $zip))
        ->toThrow(ValidationException::class);

    expect($template->fresh()->content_html)->not->toContain('hi');

    @unlink($zip);
});

it('blocks the template import when the archive exceeds the size cap', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->upload_max_size_mb = 0; // 0 disables the cap...
    $settings->save();

    $template = EmailTemplate::factory()->create();
    $zip = tempZip();

    // ...so the import succeeds.
    app(TemplateZipImporter::class)->import($template, $zip);
    expect($template->fresh()->content_html)->toContain('hi');

    @unlink($zip);
});
