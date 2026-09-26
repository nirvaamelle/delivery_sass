<?php

namespace App\Filament\Resources\CloseOutChecklists\Pages;

use App\Domain\Closeout\CloseOutChecklistService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\CloseOutChecklists\CloseOutChecklistsResource;
use App\Models\CloseOutChecklist;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * The close-out report — the phase exit gate's second sentence, rendered.
 *
 * A plain page rather than a Filament form or infolist, because what is being
 * shown is not a record's fields: it is eleven lines grouped by slide 9's three
 * panels, each naming who cleared it and when. The grouping is the point — the
 * documents belong to the PMO, the financial close to Finance, the people to HR
 * — and a flat list of eleven rows is one nobody owns.
 */
class ViewCloseOutChecklist extends Page
{
    use AuthorizesResourcePage;
    use InteractsWithRecord;

    protected static string $resource = CloseOutChecklistsResource::class;

    protected string $view = 'filament.resources.close-out-checklists.pages.view-close-out-checklist';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return sprintf('Close-out report %s', $this->getChecklist()->number);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $checklist = $this->getChecklist();
        $service = app(CloseOutChecklistService::class);

        return [
            'checklist' => $checklist,
            'project' => $checklist->project()->sole(),
            // Grouped here rather than in the view: a Blade template deciding
            // which panel a line belongs to would be a second copy of the
            // template's own grouping.
            'panels' => collect($service->report($checklist))->groupBy('panel'),
            'outstanding' => $service->outstandingFor($checklist),
            'complete' => $service->isComplete($checklist),
        ];
    }

    private function getChecklist(): CloseOutChecklist
    {
        /** @var CloseOutChecklist $checklist */
        $checklist = $this->getRecord();

        return $checklist;
    }
}
