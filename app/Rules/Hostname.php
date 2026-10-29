<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A DNS name such as example.com or api.example.com. With $allowIp, an IPv4
 * or IPv6 address is accepted too.
 */
class Hostname implements ValidationRule
{
    private const string PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    public function __construct(private readonly bool $allowIp = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a host name.');

            return;
        }

        if ($this->allowIp && filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            $fail($this->allowIp
                ? 'The :attribute must be an IP address or a lowercase host name.'
                : 'The :attribute must be a lowercase domain name, like example.com.');
        }
    }
}
