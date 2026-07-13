<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class AccessibleDropdown extends Component
{
    public function __construct(
        public ?string $label = null,
        public ?string $icon = 'o-chevron-down'
    ) {}

    public function render(): View|string
    {
        return view('components.accessible-dropdown');
    }
}
