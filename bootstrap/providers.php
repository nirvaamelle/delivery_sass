<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\GateServiceProvider;

return [
    AppServiceProvider::class,
    GateServiceProvider::class,
    AdminPanelProvider::class,
];
