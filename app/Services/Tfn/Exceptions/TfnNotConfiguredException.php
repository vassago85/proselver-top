<?php

namespace App\Services\Tfn\Exceptions;

/**
 * Thrown when the caller attempts a live TFN call before TFN_ENABLED has
 * been flipped or before credentials + customer number are populated.
 *
 * The Fuel page catches this specifically to decide "banner" (not-
 * configured => render fixtures) vs "toast" (transient failure).
 */
class TfnNotConfiguredException extends TfnException
{
}
