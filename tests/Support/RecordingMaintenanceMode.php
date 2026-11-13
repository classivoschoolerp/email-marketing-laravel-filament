<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Upgrade\MaintenanceMode;

/**
 * WP-41 test double for §6.2 F-6.2-005.
 *
 * Records the down()/up() calls the upgrade runner makes WITHOUT invoking
 * `artisan down`, which would put the whole test process into maintenance
 * mode and break every other test in the run.
 */
class RecordingMaintenanceMode extends MaintenanceMode
{
    /** @var list<string> */
    public array $calls = [];

    public bool $downSucceeds = true;

    private bool $down = false;

    public function isDown(): bool
    {
        return $this->down;
    }

    public function down(): bool
    {
        $this->calls[] = 'down';
        $this->down = $this->downSucceeds;

        return $this->down;
    }

    public function up(): bool
    {
        $this->calls[] = 'up';
        $this->down = false;

        return true;
    }
}
