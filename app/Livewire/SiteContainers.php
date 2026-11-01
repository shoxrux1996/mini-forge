<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Sites\InspectSiteContainer;
use App\Actions\Sites\ListSiteContainers;
use App\Enums\ServiceRole;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * Live container state, fetched over SSH when the page loads and on
 * refresh. Never stored, so it is never shown as stale data.
 */
class SiteContainers extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public Site $site;

    /** @var list<array{name: string, service: string, state: string, health: string, status: string, image: string}> */
    public array $containers = [];

    public ?string $fetchedAt = null;

    public ?string $error = null;

    #[On('site-changed')]
    public function load(): void
    {
        try {
            $this->containers = ListSiteContainers::run($this->site);
            $this->error = null;
        } catch (RuntimeException $e) {
            $this->containers = [];
            $this->error = $e->getMessage();
        }

        $this->fetchedAt = now()->format('H:i:s');
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Containers')
            ->description(fn () => match (true) {
                $this->error !== null => 'Could not load containers: '.$this->error,
                $this->fetchedAt !== null => "Live from the server at {$this->fetchedAt}.",
                default => 'Loading from the server…',
            })
            ->records(fn () => collect($this->containers)->keyBy('name')->all())
            ->paginated(false)
            ->emptyStateHeading(fn () => $this->fetchedAt === null ? 'Loading…' : 'No containers')
            ->emptyStateDescription(fn () => $this->fetchedAt === null ? null : 'Nothing is running for this site yet.')
            ->columns([
                TextColumn::make('service')->weight('medium'),
                TextColumn::make('state')
                    ->badge()
                    ->color(fn (string $state) => $state === 'running' ? 'success' : 'gray'),
                TextColumn::make('health')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (string $state) => match ($state) {
                        'healthy' => 'success',
                        'unhealthy' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('status'),
                TextColumn::make('image')
                    ->fontFamily('mono')
                    ->limit(48),
            ])
            ->recordActions([
                Action::make('logs')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->url(fn (array $record) => SiteResource::getUrl('logs', ['record' => $this->site, 'service' => $record['service']])),
                Action::make('inspect')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->color('gray')
                    ->modalHeading(fn (array $record) => $record['name'])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (array $record) => view('livewire.container-details', $this->inspection($record['service']))),
            ])
            ->headerActions([
                Action::make('refresh')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action(fn () => $this->load()),
            ]);
    }

    /**
     * @return array{details: array<string, string>, error: ?string}
     */
    private function inspection(string $service): array
    {
        try {
            return ['details' => InspectSiteContainer::run($this->site, ServiceRole::from($service)), 'error' => null];
        } catch (RuntimeException $e) {
            return ['details' => [], 'error' => $e->getMessage()];
        }
    }

    public function render(): View
    {
        return view('livewire.site-containers');
    }
}
