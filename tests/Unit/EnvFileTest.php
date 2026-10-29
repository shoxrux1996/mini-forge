<?php

declare(strict_types=1);

use App\Infrastructure\Docker\EnvFile;
use Dotenv\Dotenv;

it('writes values Laravel reads back unchanged', function () {
    $variables = [
        'PLAIN' => 'plain',
        'SPACES' => 'with space',
        'DOUBLE' => 'q"uote',
        'SINGLE' => "sin'gle",
        'BACKSLASH' => 'back\\slash',
        'TRAILING' => 'trail\\',
        'DOLLAR' => 'dollar $HOME ${APP_KEY}',
        'MULTILINE' => "-----BEGIN KEY-----\nabc\n-----END KEY-----",
        'HASH' => '#not-a-comment',
        'EMPTY' => '',
        'NULL' => null,
        'UNICODE' => 'Тошкент ✓',
        'INJECTION' => "x\"\nEVIL=1",
    ];

    $parsed = Dotenv::parse(EnvFile::render($variables));

    expect($parsed)->toBe(array_map(fn ($value) => (string) $value, $variables))
        ->and($parsed)->not->toHaveKey('EVIL');
});

it('rejects invalid variable names', function (string $key) {
    EnvFile::render([$key => 'x']);
})->with(['lower', '1START', 'WITH SPACE', "NEW\nLINE", 'A=B'])->throws(InvalidArgumentException::class);
