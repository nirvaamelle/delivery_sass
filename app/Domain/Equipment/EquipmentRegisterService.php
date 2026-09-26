<?php

namespace App\Domain\Equipment;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;

/**
 * Adding a machine to the register, and correcting its details.
 *
 * The register was read-only and filled by seeders. Deployment and release stay
 * EquipmentService's acts, and status is theirs — this service never writes it.
 *
 * **The code is fixed.** Costs and assignments are looked up by it on site.
 *
 * **Acquisition figures lock once a depreciation schedule exists.** The schedule
 * is computed once from cost, salvage, life and date (F5); changing any of them
 * afterwards would leave a schedule that no longer matches the machine, with
 * every month's charge still looking correct.
 */
class EquipmentRegisterService
{
    private const REQUIRED = ['code', 'description', 'ownership'];

    /** Fields the depreciation schedule was computed from. */
    public const ACQUISITION_FIELDS = ['acquisition_cost', 'salvage_value', 'acquired_on', 'useful_life_months', 'depreciation_method'];

    private const EDITABLE = ['description', 'category', 'serial_number', 'ownership', ...self::ACQUISITION_FIELDS];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function register(Organization $organization, array $attributes, ?User $by = null): Equipment
    {
        foreach (self::REQUIRED as $field) {
            if (blank($attributes[$field] ?? null)) {
                throw new InvalidEquipmentDetail($field, sprintf('A machine needs %s.', str_replace('_', ' ', $field)));
            }
        }

        $code = trim((string) $attributes['code']);

        if (Equipment::query()->where('code', $code)->exists()) {
            throw new InvalidEquipmentDetail('code', sprintf('Equipment code %s is already in use.', $code));
        }

        $values = $this->validated(array_intersect_key($attributes, array_flip(self::EDITABLE)));

        $equipment = Equipment::query()->create([
            ...$values,
            'organization_id' => $organization->getKey(),
            'code' => $code,
            'status' => EquipmentStatus::Available,
        ]);

        activity()->performedOn($equipment)->causedBy($by)->log('equipment-registered');

        return $equipment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateDetails(Equipment $equipment, array $attributes, ?User $by = null): Equipment
    {
        $locked = array_values(array_diff(array_keys($attributes), self::EDITABLE));

        if ($locked !== []) {
            throw new InvalidEquipmentDetail($locked[0], sprintf(
                '%s cannot be changed here — the code is fixed, and status moves by deployment and release.',
                $locked[0],
            ));
        }

        $values = $this->validated($attributes);
        $equipment->fill($values);

        if ($equipment->schedules()->exists()) {
            foreach (self::ACQUISITION_FIELDS as $field) {
                if ($equipment->isDirty($field)) {
                    throw new InvalidEquipmentDetail($field, sprintf(
                        '%s cannot change — the depreciation schedule was computed from it.',
                        str_replace('_', ' ', $field),
                    ));
                }
            }
        }

        $changed = array_keys($equipment->getDirty());
        $equipment->save();

        if ($changed !== []) {
            activity()->performedOn($equipment)->causedBy($by)
                ->withProperties(['fields' => $changed])
                ->log('equipment-details-updated');
        }

        return $equipment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes): array
    {
        foreach (['description', 'ownership'] as $field) {
            if (array_key_exists($field, $attributes) && blank($attributes[$field])) {
                throw new InvalidEquipmentDetail($field, sprintf('%s cannot be blank.', $field));
            }
        }

        if (isset($attributes['ownership']) && ! $attributes['ownership'] instanceof Ownership && Ownership::tryFrom((string) $attributes['ownership']) === null) {
            throw new InvalidEquipmentDetail('ownership', 'Ownership must be owned, rented or leased.');
        }

        foreach (['acquisition_cost', 'salvage_value'] as $field) {
            if (! array_key_exists($field, $attributes) || blank($attributes[$field])) {
                unset($attributes[$field]);

                continue;
            }

            if (! is_numeric($attributes[$field]) || bccomp((string) $attributes[$field], '0', 4) < 0) {
                throw new InvalidEquipmentDetail($field, sprintf('%s must be a number, zero or more.', str_replace('_', ' ', $field)));
            }

            $attributes[$field] = bcadd((string) $attributes[$field], '0', 4);
        }

        if (isset($attributes['acquisition_cost'], $attributes['salvage_value'])
            && bccomp($attributes['salvage_value'], $attributes['acquisition_cost'], 4) > 0) {
            throw new InvalidEquipmentDetail('salvage_value', 'Salvage value cannot be more than the acquisition cost.');
        }

        // Blank means "not given": these columns are NOT NULL with defaults (a
        // 60-month life, straight line), which a rented machine simply keeps.
        foreach (['useful_life_months', 'depreciation_method'] as $field) {
            if (array_key_exists($field, $attributes) && blank($attributes[$field])) {
                unset($attributes[$field]);
            }
        }

        if (array_key_exists('useful_life_months', $attributes)
            && (! ctype_digit((string) $attributes['useful_life_months']) || (int) $attributes['useful_life_months'] < 1)) {
            throw new InvalidEquipmentDetail('useful_life_months', 'Useful life must be a whole number of months, at least one.');
        }

        foreach ($attributes as $field => $value) {
            if (is_string($value)) {
                $attributes[$field] = trim($value) === '' ? null : trim($value);
            }
        }

        return $attributes;
    }
}
