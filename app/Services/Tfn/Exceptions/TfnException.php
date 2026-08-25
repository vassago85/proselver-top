<?php

namespace App\Services\Tfn\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Anything TFN's API is unhappy about surfaces as one of these -- the
 * Livewire page catches the base type once and renders the message. Sub-
 * classes carry more specific intent when the caller needs to branch
 * (e.g. missing config vs. 401 vs. business validation).
 */
class TfnException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?array $payload = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
