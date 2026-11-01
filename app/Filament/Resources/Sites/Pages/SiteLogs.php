<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sites\Pages;

use App\Actions\Sites\ReadSiteLogs;
use App\Enums\ServiceRole;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;
use RuntimeException;

class SiteLogs extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SiteResource::class;

    protected string $view = 'filament.sites.logs';

    #[Url]
    public string $service = 'web';

    public int $lines = 100;

    public string $search = '';

    public bool $follow = false;

    public ?string $output = null;

    public ?string $error = null;

    public ?string $fetchedAt = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->service = (ServiceRole::tryFrom($this->service) ?? ServiceRole::Web)->value;
    }

    public function getTitle(): string
    {
        return "Logs: {$this->site()->domain}";
    }

    public function updatedService(): void
    {
        $this->load();
    }

    public function updatedLines(): void
    {
        $this->load();
    }

    public function load(): void
    {
        $service = ServiceRole::tryFrom($this->service) ?? ServiceRole::Web;
        $lines = in_array($this->lines, ReadSiteLogs::LINE_CHOICES, true) ? $this->lines : ReadSiteLogs::LINE_CHOICES[0];

        try {
            $this->output = ReadSiteLogs::run($this->site(), $service, $lines);
            $this->error = null;
        } catch (RuntimeException $e) {
            $this->output = null;
            $this->error = $e->getMessage();
        }

        $this->fetchedAt = now()->format('H:i:s');
    }

    /** The fetched lines that contain the search text. */
    public function visibleOutput(): string
    {
        $output = (string) $this->output;

        if ($this->search === '') {
            return $output;
        }

        $matches = array_filter(
            preg_split('/\R/', $output) ?: [],
            fn (string $line) => mb_stripos($line, $this->search) !== false,
        );

        return implode("\n", $matches);
    }

    /**
     * @return list<ServiceRole>
     */
    public function services(): array
    {
        return $this->site()->services->where('enabled', true)->pluck('role')->values()->all();
    }

    private function site(): Site
    {
        $record = $this->getRecord();

        return $record instanceof Site ? $record : throw new RuntimeException('Unknown site.');
    }
}
