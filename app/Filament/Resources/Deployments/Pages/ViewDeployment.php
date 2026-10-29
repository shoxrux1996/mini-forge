<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deployments\Pages;

use App\Filament\Resources\Deployments\DeploymentResource;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Deployment;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Deployment $record
 */
class ViewDeployment extends ViewRecord
{
    protected static string $resource = DeploymentResource::class;

    public function getTitle(): string
    {
        return "Deployment #{$this->record->id}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('site')
                ->label('Open site')
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('gray')
                ->url(fn (Deployment $record) => SiteResource::getUrl('view', ['record' => $record->site])),
        ];
    }
}
