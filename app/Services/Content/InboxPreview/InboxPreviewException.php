<?php

declare(strict_types=1);

namespace App\Services\Content\InboxPreview;

use RuntimeException;

/**
 * A rendering-farm vendor refused a submission (spec §2.3.5 F-2.3.5-006).
 * Carried to the run row's `error` column verbatim — a customer whose Litmus
 * plan has run out of previews must read that, not "something went wrong".
 */
final class InboxPreviewException extends RuntimeException {}
