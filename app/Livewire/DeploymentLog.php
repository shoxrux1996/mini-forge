<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Deployment;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Steps and their output, refreshed every two seconds while the
 * deployment runs.
 */
class DeploymentLog extends Component
{
    public Deployment $deployment;

    public function render(): View
    {
        $this->deployment->refresh()->load('steps');

        return view('livewire.deployment-log', [
            'deployment' => $this->deployment,
            'running' => ! $this->deployment->status->isFinished(),
        ]);
    }
}
