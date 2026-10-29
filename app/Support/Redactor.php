<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Replaces known secret values in log output before it is stored.
 */
final class Redactor
{
    public const string MASK = '[redacted]';

    /** Shorter values (0, true, a port) would mask harmless text. */
    private const int MIN_LENGTH = 6;

    /** @var list<string> */
    private array $secrets;

    /**
     * @param  iterable<mixed>  $secrets
     */
    public function __construct(iterable $secrets)
    {
        $values = [];

        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= self::MIN_LENGTH) {
                $values[] = $secret;
            }
        }

        // Longest first, so a secret that contains another is masked whole.
        usort($values, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $this->secrets = array_values(array_unique($values));
    }

    public function redact(string $text): string
    {
        return $this->secrets === [] ? $text : str_replace($this->secrets, self::MASK, $text);
    }
}
