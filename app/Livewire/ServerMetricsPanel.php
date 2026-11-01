<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Servers\ReadServerMetrics;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;

/**
 * Live CPU, memory, disk and container usage, read over SSH when the page
 * loads and every 30 seconds while it stays open.
 *
 * @phpstan-import-type Metrics from ReadServerMetrics
 */
class ServerMetricsPanel extends Component
{
    public Server $server;

    /** @var ?Metrics */
    public ?array $metrics = null;

    public ?string $fetchedAt = null;

    public ?string $error = null;

    public function load(): void
    {
        try {
            $this->metrics = ReadServerMetrics::run($this->server);
            $this->error = null;
        } catch (RuntimeException $e) {
            $this->metrics = null;
            $this->error = $e->getMessage();
        }

        $this->fetchedAt = now()->format('H:i:s');
    }

    public function render(): View
    {
        return view('livewire.server-metrics-panel');
    }
}
