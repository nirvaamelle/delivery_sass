<?php

namespace App\Domain\Budgets;

use App\Models\CostCode;
use App\Models\Organization;
use App\Models\User;
use DomainException;

/**
 * The cost code tree — adding a code, renaming it, moving it under a parent.
 *
 * **The code is fixed once added.** Budget lines, commitments and ledger rows
 * roll up by it, so a changed code would restate every report that grouped by
 * the old one. The name can be corrected.
 *
 * **A parent must be in the same company, and the tree cannot loop.** The loop
 * guard already lives on the model (it refuses a code becoming its own
 * ancestor); this service turns that refusal into one a form can show beside the
 * parent field.
 *
 * No delete: budget lines point at cost codes, and the foreign key refuses it.
 */
class CostCodeService
{
    /** What an edit may change. */
    private const EDITABLE = ['name', 'parent_id'];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(Organization $organization, array $attributes, ?User $by = null): CostCode
    {
        foreach (['code', 'name'] as $field) {
            if (trim((string) ($attributes[$field] ?? '')) === '') {
                throw new InvalidBudgetDetail($field, sprintf('A cost code needs a %s.', $field));
            }
        }

        $code = trim((string) $attributes['code']);

        $taken = CostCode::query()
            ->where('organization_id', $organization->getKey())
            ->where('code', $code)
            ->exists();

        if ($taken) {
            throw new InvalidBudgetDetail('code', sprintf('Cost code %s is already in use in this company.', $code));
        }

        $parentId = $this->parentIn($organization->getKey(), $attributes['parent_id'] ?? null);

        $costCode = CostCode::query()->create([
            'organization_id' => $organization->getKey(),
            'parent_id' => $parentId,
            'code' => $code,
            'name' => trim((string) $attributes['name']),
        ]);

        activity()->performedOn($costCode)->causedBy($by)->log('cost-code-added');

        return $costCode;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(CostCode $costCode, array $attributes, ?User $by = null): CostCode
    {
        $locked = array_values(array_diff(array_keys($attributes), self::EDITABLE));

        if ($locked !== []) {
            throw new InvalidBudgetDetail($locked[0], sprintf(
                '%s cannot be changed — reports roll up by the code, and it belongs to its company.',
                $locked[0],
            ));
        }

        if (array_key_exists('name', $attributes)) {
            if (trim((string) $attributes['name']) === '') {
                throw new InvalidBudgetDetail('name', 'A cost code needs a name.');
            }

            $costCode->name = trim((string) $attributes['name']);
        }

        if (array_key_exists('parent_id', $attributes)) {
            $costCode->parent_id = $this->parentIn($costCode->organization_id, $attributes['parent_id']);
        }

        $changed = array_keys($costCode->getDirty());

        try {
            $costCode->save();
        } catch (DomainException $e) {
            // The model's loop guard.
            throw new InvalidBudgetDetail('parent_id', $e->getMessage());
        }

        if ($changed !== []) {
            activity()->performedOn($costCode)->causedBy($by)
                ->withProperties(['fields' => $changed])
                ->log('cost-code-updated');
        }

        return $costCode;
    }

    private function parentIn(mixed $organizationId, mixed $parentId): ?int
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = CostCode::query()->find($parentId);

        if ($parent === null || (int) $parent->organization_id !== (int) $organizationId) {
            throw new InvalidBudgetDetail('parent_id', 'The parent cost code must belong to the same company.');
        }

        return (int) $parent->getKey();
    }
}
