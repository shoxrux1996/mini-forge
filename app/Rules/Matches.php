<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A regex rule as an object, so patterns containing `|` are not split the
 * way Laravel splits "regex:..." rule strings.
 */
class Matches implements ValidationRule
{
    public function __construct(
        private readonly string $pattern,
        private readonly string $message = 'The :attribute format is invalid.',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match($this->pattern, $value) !== 1) {
            $fail($this->message);
        }
    }
}
