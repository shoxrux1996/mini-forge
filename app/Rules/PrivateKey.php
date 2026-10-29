<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use phpseclib4\Crypt\PublicKeyLoader;
use Throwable;

/** An SSH private key that phpseclib can load with the given passphrase. */
class PrivateKey implements ValidationRule
{
    public function __construct(private readonly ?string $passphrase = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            PublicKeyLoader::loadPrivateKey((string) $value, $this->passphrase ?: null);
        } catch (Throwable) {
            $fail('The :attribute is not a readable private key, or the passphrase is wrong.');
        }
    }
}
