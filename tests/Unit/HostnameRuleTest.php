<?php

declare(strict_types=1);

use App\Rules\Hostname;

function passesHostname(string $value, bool $allowIp = false): bool
{
    $passes = true;
    (new Hostname($allowIp))->validate('host', $value, function () use (&$passes) {
        $passes = false;
    });

    return $passes;
}

it('accepts domains and subdomains', function (string $domain) {
    expect(passesHostname($domain))->toBeTrue();
})->with(['example.com', 'api.example.com', 'staging.my-app.co.uk', 'xn--80ak6aa92e.com']);

it('rejects things that are not domains', function (string $value) {
    expect(passesHostname($value))->toBeFalse();
})->with([
    'localhost', 'Example.com', '-bad.com', 'bad-.com', 'a..com', 'example.com/path',
    'example.com; rm -rf /', '$(whoami).example.com', 'http://example.com', '203.0.113.10',
]);

it('accepts IP addresses only when allowed', function () {
    expect(passesHostname('203.0.113.10', allowIp: true))->toBeTrue()
        ->and(passesHostname('2001:db8::1', allowIp: true))->toBeTrue()
        ->and(passesHostname('203.0.113.10'))->toBeFalse();
});
