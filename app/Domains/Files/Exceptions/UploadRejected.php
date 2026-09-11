<?php

namespace App\Domains\Files\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A rejected upload. The message is always something the user can act on —
 * never "Something went wrong" — but never reveals why a specific detection
 * rule fired, which would help someone probe for a bypass.
 */
class UploadRejected extends \RuntimeException
{
    public function toValidationException(string $field = 'file'): ValidationException
    {
        return ValidationException::withMessages([$field => $this->getMessage()]);
    }
}
