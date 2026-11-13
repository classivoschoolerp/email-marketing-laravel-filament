# Upload guard (spec §3.25.15 F-3.25.15-006)

`App\Services\Security\UploadGuard` is the single place that decides whether a
file may enter the platform. It reads the `security` settings group
(Admin → Settings → Security → *File uploads*):

| Setting | Effect |
|---|---|
| `upload_allowed_extensions` | Allowlist, lowercase, dot-free. Empty = no extension restriction. |
| `upload_max_size_mb` | Hard size cap. `0` disables the cap (D-23). |
| `upload_scan_enabled` | Turns the malware scanner on. |
| `upload_scanner_driver` | `null` (no verdict) or `clamav` (clamd INSTREAM). |
| `upload_scanner_host` / `upload_scanner_port` | TCP host+port, or an absolute path for a unix socket. |

On top of the allowlist, `UploadGuard::ALWAYS_BLOCKED` rejects executable types
(`php`, `phar`, `exe`, `sh`, `jsp`, …) whatever the operator configures.

## API

```php
$guard = app(\App\Services\Security\UploadGuard::class);

$errors = $guard->check($uploadedFile);      // Illuminate\Http\UploadedFile
$errors = $guard->checkPath($absolutePath);  // a file already on disk
$guard->validate($uploadedFile);             // throws UploadRejectedException
$result = $guard->scan($absolutePath);       // ScanResult, scanner only
```

`check()` (client uploads) trusts the client file name for the extension and
additionally verifies the detected MIME type against it. `checkPath()` (files
Filament's `FileUpload` has already stored, temp files, queue payloads) trusts
the **content** first — generated names routinely say `.tmp` — and falls back to
the name.

The scanner is fail-open by contract: an unreachable daemon yields
`ScanResult::skipped()` and never blocks an upload, mirroring the SpamAssassin
decision in D-49. A new anti-virus vendor is a new `VirusScanner` implementation
plus a key in `upload_scanner_driver` — never a core change (docs/ARCHITECTURE.md rule 2).

## Wiring status

**Wired (proof path):** `App\Services\Templates\TemplateZipImporter::import()` —
every template and campaign ZIP import (customer template import, campaign
content import, gallery packs) passes through `checkPath()` before the archive
is opened.

**Not yet wired** — each of these already has its own narrower validation
(`FileUpload` accept lists, §3.25.2 import extensions, §3.25.3 attachment
types); adopting the guard means calling `check()`/`checkPath()` before the file
is persisted:

| Path | File |
|---|---|
| Subscriber CSV import | `app/Filament/Customer/Resources/EmailListResource/Pages/ImportSubscribers.php` |
| Campaign attachments | `app/Filament/Customer/Resources/CampaignResource.php` |
| Customer avatar | `app/Filament/Customer/Pages/Profile.php` |
| Admin user avatar | `app/Filament/Admin/Resources/UserResource.php` |
| Logo / favicon | `app/Filament/Admin/Pages/Settings/CommonSettingsPage.php` |
| GeoIP database | `app/Filament/Admin/Pages/Settings/LocationSettingsPage.php` |
| Blacklist / suppression CSV | `app/Filament/{Admin,Customer}/Resources/*BlacklistEmailResource`, `SuppressionListResource` |
| Language pack, price-plan and country/zone imports | `LanguageResource`, `PricePlanResource`, `ListCountries`, `ListZones` |
| Gallery template pack | `app/Filament/Admin/Resources/GalleryTemplateResource*` |
