<?php

declare(strict_types=1);

it('is a safe no-op on non-MySQL drivers', function (): void {
    $this->artisan('db:partition')
        ->expectsOutputToContain('Non-MySQL driver')
        ->assertSuccessful();
});
