<?php

namespace FixtureBad\UI\Dashboard;

use Tey\Kit\Features\SessionUi\Dashboard\Widget;

// ui.dashboard-composition: a task widget in the aside column (warn only).
final class TasksWidget extends Widget
{
    public function title(): string
    {
        return 'Tasks';
    }

    public function component(): string
    {
        return 'TasksWidget';
    }

    public function kind(): string
    {
        return 'task';
    }

    public function region(): string
    {
        return 'aside';
    }
}
