<?php

namespace App\Livewire\Live;

use Livewire\Component;

class Ticker extends Component
{
    public string $view = 'livewire.live.ticker';

    public function render()
    {
        return view($this->view);
    }
}
