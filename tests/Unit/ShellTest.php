<?php

declare(strict_types=1);

use App\Infrastructure\SSH\HostKey;
use App\Infrastructure\SSH\Shell;
use Symfony\Component\Process\Process;

function bashEcho(string $script): string
{
    $process = new Process(['bash', '-c', $script]);
    $process->mustRun();

    return $process->getOutput();
}

it('quotes values so bash sees them literally', function (string $value) {
    expect(bashEcho('printf %s '.Shell::quote($value)))->toBe($value);
})->with([
    'plain' => 'example.com',
    'spaces' => 'two words',
    'single quotes' => "it's 'quoted'",
    'command substitution' => '$(touch /tmp/pwned)`id`',
    'separators' => 'a; rm -rf / && b | c',
    'newline' => "line1\nline2",
    'empty' => '',
]);

it('builds commands with every argument quoted', function () {
    expect(Shell::command('docker', 'logs', '--tail', 50, 'web; id'))
        ->toBe("'docker' 'logs' '--tail' '50' 'web; id'");
});

it('exports variables and rejects bad names', function () {
    expect(Shell::exports(['ROOT' => '/opt/mini-forge', 'NETWORK' => "a'b"]))
        ->toBe("export ROOT='/opt/mini-forge'\nexport NETWORK='a'\\''b'");

    expect(fn () => Shell::exports(['bad name' => 'x']))->toThrow(InvalidArgumentException::class);
    expect(fn () => Shell::exports(['X;id' => 'x']))->toThrow(InvalidArgumentException::class);
});

it('wraps scripts in strict bash, through sudo for non-root users', function () {
    expect(Shell::wrap('echo hi', sudo: false))->toBe("bash -c 'set -euo pipefail\necho hi'")
        ->and(Shell::wrap('echo hi', sudo: true))->toBe("sudo -n bash -c 'set -euo pipefail\necho hi'");
});

it('runs wrapped scripts with strict mode', function () {
    $process = Process::fromShellCommandline(Shell::wrap("false\necho unreachable", sudo: false));
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('');
});

it('computes the same fingerprint as ssh-keygen', function () {
    expect(HostKey::fingerprint('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIE6w2yrj9+vxL07I5tYPvkdvDYpPfBMDz1/an7MUj/OC'))
        ->toBe('SHA256:YDbq5q/WBMOUfxwm5gkywep6hHLz/N4FUAlO2uNSgnM');

    expect(fn () => HostKey::fingerprint('garbage'))->toThrow(InvalidArgumentException::class);
});
