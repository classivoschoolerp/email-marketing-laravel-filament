<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\LanguageResource;
use App\Filament\Admin\Resources\LanguageResource\Pages\ListLanguages;
use App\Models\Language;
use App\Models\User;
use App\Services\I18n\LanguagePackImporter;
use App\Services\I18n\Locales;
use App\Settings\CommonSettings;
use Database\Seeders\LanguageSeeder;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// WP-20 — §3.22 F-3.22-001 / §6.5 F-6.5-004: installed languages admin.

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'emp-i18n-'.uniqid();
    mkdir($this->workDir, 0755, true);
});

afterEach(function (): void {
    \Illuminate\Support\Facades\File::deleteDirectory($this->workDir);
});

function makePackZip(string $dir, array $entries): string
{
    $path = $dir.DIRECTORY_SEPARATOR.'pack-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);

    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();

    return $path;
}

it('lists installed languages for an admin and blocks guests', function (): void {
    $this->seed(LanguageSeeder::class);

    $this->get(LanguageResource::getUrl('index', panel: 'admin'))
        ->assertOk()
        ->assertSee('English')
        ->assertSee('Italiano')
        ->assertSee('日本語');

    auth('admin')->logout();
    $this->get(LanguageResource::getUrl('index', panel: 'admin'))->assertRedirect();
});

it('seeds en, it and ja language rows with the default mirrored from settings', function (): void {
    $this->seed(LanguageSeeder::class);

    expect(Language::query()->pluck('code')->all())->toContain('en', 'it', 'ja')
        ->and(Language::query()->where('code', 'en')->first()?->is_default)->toBeTrue()
        ->and(Language::query()->where('code', 'ja')->first()?->is_default)->toBeFalse();
});

it('imports a valid language pack zip (flat and single-folder layouts)', function (): void {
    $zip = makePackZip($this->workDir, [
        'greetings.php' => "<?php\n\nreturn ['hello' => 'Ciao'];\n",
        'strings.json' => '{"Bye": "Addio"}',
    ]);

    $count = app(LanguagePackImporter::class)->import($zip, 'xx', $this->workDir);

    expect($count)->toBe(2)
        ->and(is_file($this->workDir.DIRECTORY_SEPARATOR.'xx'.DIRECTORY_SEPARATOR.'greetings.php'))->toBeTrue()
        ->and(is_file($this->workDir.DIRECTORY_SEPARATOR.'xx'.DIRECTORY_SEPARATOR.'strings.json'))->toBeTrue();

    $nested = makePackZip($this->workDir, [
        'xx/nested.php' => "<?php\n\nreturn ['a' => 'b'];\n",
    ]);

    expect(app(LanguagePackImporter::class)->import($nested, 'xx', $this->workDir))->toBe(1)
        ->and(is_file($this->workDir.DIRECTORY_SEPARATOR.'xx'.DIRECTORY_SEPARATOR.'nested.php'))->toBeTrue();
});

it('rejects invalid language pack archives', function (): void {
    $importer = app(LanguagePackImporter::class);

    // Wrong extension.
    $bad = makePackZip($this->workDir, ['evil.exe' => 'MZ...']);
    expect(fn () => $importer->import($bad, 'xx', $this->workDir))->toThrow(ValidationException::class);

    // PHP file that is not a lang file.
    $badPhp = makePackZip($this->workDir, ['x.php' => "<?php echo 'no return here';"]);
    expect(fn () => $importer->import($badPhp, 'xx', $this->workDir))->toThrow(ValidationException::class);

    // Broken JSON.
    $badJson = makePackZip($this->workDir, ['x.json' => '{not json']);
    expect(fn () => $importer->import($badJson, 'xx', $this->workDir))->toThrow(ValidationException::class);

    // Nested too deep.
    $deep = makePackZip($this->workDir, ['a/b/c.php' => "<?php\n\nreturn [];\n"]);
    expect(fn () => $importer->import($deep, 'xx', $this->workDir))->toThrow(ValidationException::class);

    // Bad language code.
    $ok = makePackZip($this->workDir, ['x.php' => "<?php\n\nreturn [];\n"]);
    expect(fn () => $importer->import($ok, '../evil', $this->workDir))->toThrow(ValidationException::class);
});

it('creates a new pack from the reference locale files', function (): void {
    mkdir($this->workDir.DIRECTORY_SEPARATOR.'en');
    file_put_contents(
        $this->workDir.DIRECTORY_SEPARATOR.'en'.DIRECTORY_SEPARATOR.'sample.php',
        "<?php\n\nreturn ['hi' => 'Hi'];\n",
    );

    $count = app(LanguagePackImporter::class)->createFromReference('newpack', $this->workDir);

    expect($count)->toBe(1)
        ->and(is_file($this->workDir.DIRECTORY_SEPARATOR.'newpack'.DIRECTORY_SEPARATOR.'sample.php'))->toBeTrue();
});

it('sets the default language through CommonSettings — the source of truth', function (): void {
    $this->seed(LanguageSeeder::class);

    Livewire::test(ListLanguages::class)
        ->callTableAction('set_default', Language::query()->where('code', 'it')->first());

    expect(app(CommonSettings::class)->refresh()->default_language)->toBe('it')
        ->and(Language::query()->where('code', 'it')->first()?->is_default)->toBeTrue()
        ->and(Language::query()->where('code', 'en')->first()?->is_default)->toBeFalse()
        ->and(Locales::defaultCode())->toBe('it');
});

it('exports a language pack from the resource action', function (): void {
    $this->seed(LanguageSeeder::class);

    $response = Livewire::test(ListLanguages::class)
        ->callTableAction('export', Language::query()->where('code', 'it')->first());

    $response->assertFileDownloaded();
})->skip(fn (): bool => ! method_exists(\Livewire\Features\SupportTesting\Testable::class, 'assertFileDownloaded'), 'assertFileDownloaded unavailable');

it('toggles enabled and rtl through the edit action', function (): void {
    $this->seed(LanguageSeeder::class);

    Livewire::test(ListLanguages::class)
        ->callTableAction('edit', Language::query()->where('code', 'ja')->first(), data: [
            'name' => '日本語',
            'rtl' => false,
            'enabled' => false,
        ]);

    expect(Language::query()->where('code', 'ja')->first()?->enabled)->toBeFalse();
});
