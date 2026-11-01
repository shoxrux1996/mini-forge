<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Exceptions\RemoteCommandFailed;
use App\Exceptions\ServerConnectionFailed;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @phpstan-type Metrics array{
 *     cpu_percent: ?int,
 *     load: ?string,
 *     memory_used_mb: ?int,
 *     memory_total_mb: ?int,
 *     swap_used_mb: ?int,
 *     swap_total_mb: ?int,
 *     disk_used_gb: ?int,
 *     disk_total_gb: ?int,
 *     containers: list<array{name: string, cpu: string, memory: string, memory_percent: string, pids: string}>
 * }
 */
class ReadServerMetrics
{
    use AsAction;

    /** CPU is measured over one second; docker stats takes about two more. */
    private const string SCRIPT = <<<'BASH'
        read -r _ user nice system idle iowait irq softirq steal _ < /proc/stat
        sleep 1
        read -r _ user2 nice2 system2 idle2 iowait2 irq2 softirq2 steal2 _ < /proc/stat
        busy=$(( (user2 + nice2 + system2 + irq2 + softirq2 + steal2) - (user + nice + system + irq + softirq + steal) ))
        waiting=$(( (idle2 + iowait2) - (idle + iowait) ))
        echo "cpu_percent=$(( 100 * busy / (busy + waiting > 0 ? busy + waiting : 1) ))"
        echo "load=$(cut -d' ' -f1-3 /proc/loadavg)"
        awk '/^MemTotal:/ {t=$2} /^MemAvailable:/ {a=$2} /^SwapTotal:/ {st=$2} /^SwapFree:/ {sf=$2}
          END {print "memory_total_mb=" int(t/1024); print "memory_used_mb=" int((t-a)/1024);
               print "swap_total_mb=" int(st/1024); print "swap_used_mb=" int((st-sf)/1024)}' /proc/meminfo
        df -BG --output=size,used / | tail -n 1 | tr -d G | awk '{print "disk_total_gb=" $1; print "disk_used_gb=" $2}'
        docker stats --no-stream --format 'container={{json .}}' 2>/dev/null || true
        BASH;

    /**
     * A live snapshot read over SSH. Nothing is stored and nothing runs on
     * the server between reads, so there is no history.
     *
     * @return Metrics
     *
     * @throws ServerConnectionFailed
     * @throws RemoteCommandFailed
     */
    public function handle(Server $server): array
    {
        $connection = ConnectToServer::run($server);

        try {
            return self::parse($connection->run(self::SCRIPT, 30)->throw('Reading metrics')->output);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * @return Metrics
     */
    public static function parse(string $output): array
    {
        $values = [];
        $containers = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with($line, 'container=')) {
                $row = json_decode(substr($line, 10), true);

                if (is_array($row)) {
                    $containers[] = [
                        'name' => (string) ($row['Name'] ?? ''),
                        'cpu' => (string) ($row['CPUPerc'] ?? ''),
                        'memory' => (string) ($row['MemUsage'] ?? ''),
                        'memory_percent' => (string) ($row['MemPerc'] ?? ''),
                        'pids' => (string) ($row['PIDs'] ?? ''),
                    ];
                }
            } elseif (preg_match('/^([a-z_]+)=(.+)$/', trim($line), $match) === 1) {
                $values[$match[1]] = $match[2];
            }
        }

        usort($containers, fn (array $a, array $b) => $a['name'] <=> $b['name']);

        $integer = fn (string $key) => isset($values[$key]) && ctype_digit($values[$key]) ? (int) $values[$key] : null;

        return [
            'cpu_percent' => $integer('cpu_percent'),
            'load' => $values['load'] ?? null,
            'memory_used_mb' => $integer('memory_used_mb'),
            'memory_total_mb' => $integer('memory_total_mb'),
            'swap_used_mb' => $integer('swap_used_mb'),
            'swap_total_mb' => $integer('swap_total_mb'),
            'disk_used_gb' => $integer('disk_used_gb'),
            'disk_total_gb' => $integer('disk_total_gb'),
            'containers' => $containers,
        ];
    }
}
