<?php

declare(strict_types=1);

namespace App\Services\Imports;

use RuntimeException;

/**
 * Thrown by import readers when a run cannot proceed (disabled source,
 * unreachable URL, oversized file, …). RunImportJob converts it into a
 * failed import_jobs row with the message as the summary.
 */
class ImportFailedException extends RuntimeException {}
