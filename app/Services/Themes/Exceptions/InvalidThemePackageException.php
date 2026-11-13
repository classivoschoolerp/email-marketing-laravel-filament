<?php

declare(strict_types=1);

namespace App\Services\Themes\Exceptions;

use RuntimeException;

/**
 * A theme archive that must not be installed (spec §6.3 F-6.3-005).
 *
 * Carries a LIST of messages rather than one string because manifest
 * validation reports every problem at once — an author fixing a package
 * should not have to upload it six times to discover six mistakes.
 */
class InvalidThemePackageException extends RuntimeException
{
    /** @var list<string> */
    private array $errors = [];

    /**
     * @param  list<string>  $errors
     */
    public static function withErrors(array $errors): self
    {
        $exception = new self($errors[0] ?? __('themes.errors.package_invalid'));
        $exception->errors = array_values($errors);

        return $exception;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors === [] ? [$this->getMessage()] : $this->errors;
    }
}
