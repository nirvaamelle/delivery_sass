<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Hris\EmployeeService;
use App\Domain\Hris\InvalidEmployeeDetail;
use App\Domain\Hris\PayBasis;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Employees\EmployeesResource;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hire through EmployeeService, never by writing the row directly.
 *
 * Filament's default would `new Employee($data)->save()`, skipping every rule
 * the service holds. The optional starting rate is recorded in the same
 * transaction as the first entry in the rate history, effective from the date
 * hired — so a hire that fails leaves no orphan rate, and a rate that fails
 * leaves no half-hired employee.
 */
class CreateEmployee extends CreateRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = EmployeesResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $basis = $data['pay_basis'] ?? null;
        $rate = $data['starting_rate'] ?? null;
        $organization = Organization::query()->findOrFail($data['organization_id'] ?? null);

        unset($data['pay_basis'], $data['starting_rate'], $data['organization_id']);

        $user = auth()->user();
        $by = $user instanceof User ? $user : null;

        try {
            return DB::transaction(function () use ($organization, $data, $basis, $rate, $by): Employee {
                $service = app(EmployeeService::class);
                $employee = $service->hire($organization, $data);

                if (filled($rate) && filled($basis)) {
                    $service->setRate(
                        $employee,
                        PayBasis::from((string) $basis),
                        bcadd((string) $rate, '0', 4),
                        Carbon::parse($employee->date_hired),
                        $by,
                        'Starting rate at hire.',
                    );
                }

                return $employee;
            });
        } catch (InvalidEmployeeDetail $e) {
            // Beside the field that caused it, not a banner to match up.
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['data.starting_rate' => $e->getMessage()]);
        }
    }
}
