<?php

namespace MrNewport\LaravelFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;

class FlowActionEvent
{
    use Dispatchable, SerializesModels;

    public FlowInstanceStep $oldStep;
    public string $action;
    public ?FlowInstanceStep $newStep;

    public function __construct(FlowInstanceStep $oldStep, string $action, ?FlowInstanceStep $newStep)
    {
        $this->oldStep = $oldStep;
        $this->action  = $action;
        $this->newStep = $newStep;
    }
}
