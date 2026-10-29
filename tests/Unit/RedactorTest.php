<?php

declare(strict_types=1);

use App\Support\Redactor;

it('masks every known secret', function () {
    $redactor = new Redactor(['s3cret-password', 'token-abcdef', null, 42]);

    expect($redactor->redact("password=s3cret-password\nAuthorization: token-abcdef token-abcdef"))
        ->toBe("password=[redacted]\nAuthorization: [redacted] [redacted]");
});

it('masks a secret that contains another as a whole', function () {
    expect((new Redactor(['abcdef', 'abcdef-and-more']))->redact('x abcdef-and-more y'))->toBe('x [redacted] y');
});

it('ignores values too short to be secrets', function () {
    expect((new Redactor(['true', '6379', '']))->redact('REDIS_PORT=6379 true'))->toBe('REDIS_PORT=6379 true');
});
