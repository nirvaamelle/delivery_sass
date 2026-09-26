<?php

use App\Filament\Resources\Accomplishments\AccomplishmentsResource;
use App\Filament\Resources\BackCharges\BackChargesResource;
use App\Filament\Resources\CloseOutChecklists\CloseOutChecklistsResource;
use App\Filament\Resources\Demobilizations\DemobilizationsResource;
use App\Filament\Resources\FinalAccounts\FinalAccountsResource;
use App\Filament\Resources\Mobilizations\MobilizationsResource;
use App\Filament\Resources\Permits\PermitsResource;
use App\Filament\Resources\Punchlists\PunchlistsResource;
use App\Filament\Resources\ReceivingReports\ReceivingReportsResource;
use App\Filament\Resources\RetentionReleases\RetentionReleasesResource;
use App\Filament\Resources\StockCards\StockCardsResource;
use App\Filament\Resources\Subcontracts\SubcontractsResource;
use App\Filament\Resources\ThreeWayMatches\ThreeWayMatchesResource;
use App\Filament\Resources\TurnoverPacks\TurnoverPacksResource;
use App\Filament\Resources\Warranties\WarrantiesResource;

/*
|--------------------------------------------------------------------------
| Retired modules
|--------------------------------------------------------------------------
|
| The business pivoted to third-party logistics; these screens describe a
| construction firm's work. They are hidden from the navigation and nothing
| else — spec §8. Code, tables, routes and tests all stay, and re-enabling a
| module is deleting one line from this list.
|
| Deliberately NOT an access control. Access stays with ScreenAccess and
| config/access.php, which every one of these resources still has a rule in. A
| retired URL still answers for anyone whose role allowed it yesterday, and
| tests/Feature/Foundation/RetiredModulesTest.php pins that — a reader who
| believes a hidden screen is a secured screen stops securing it.
|
| Substantial Completions is named in the spec's prose but absent here: it has
| no Filament resource to hide.
|
*/

return [
    'retired' => [
        AccomplishmentsResource::class,
        BackChargesResource::class,
        CloseOutChecklistsResource::class,
        DemobilizationsResource::class,
        FinalAccountsResource::class,
        MobilizationsResource::class,
        PermitsResource::class,
        PunchlistsResource::class,
        ReceivingReportsResource::class,
        RetentionReleasesResource::class,
        StockCardsResource::class,
        SubcontractsResource::class,
        ThreeWayMatchesResource::class,
        TurnoverPacksResource::class,
        WarrantiesResource::class,
    ],
];
