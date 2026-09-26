<?php

use App\Domain\Budgets\CostCodeService;
use App\Domain\Budgets\InvalidBudgetDetail;
use App\Models\CostCode;
use App\Models\Organization;

/*
|--------------------------------------------------------------------------
| The cost code tree
|--------------------------------------------------------------------------
|
| Cost codes were seeded and nothing else. Budget lines, commitments and ledger
| rows roll up by them, so the rules are about keeping those roll-ups true: the
| code is fixed once added, a parent stays in the same company, and the tree
| cannot loop.
|
*/

function costCodes(): CostCodeService
{
    return app(CostCodeService::class);
}

it('adds a cost code under a parent', function () {
    $organization = Organization::factory()->create();
    $parent = costCodes()->add($organization, ['code' => '03', 'name' => 'Concrete']);

    $child = costCodes()->add($organization, ['code' => '03-100', 'name' => 'Formwork', 'parent_id' => $parent->getKey()]);

    expect($child->parent_id)->toBe($parent->getKey())
        ->and($child->fresh()->wbsPath())->toBe('03 › 03-100');
});

it('refuses a cost code without a code or name', function (string $field) {
    $attributes = ['code' => '04', 'name' => 'Masonry'];
    $attributes[$field] = '  ';

    expect(fn () => costCodes()->add(Organization::factory()->create(), $attributes))
        ->toThrow(InvalidBudgetDetail::class, $field);
})->with(['code', 'name']);

it('refuses a code already used in the same company, and allows it in another', function () {
    $organization = Organization::factory()->create();
    costCodes()->add($organization, ['code' => '05', 'name' => 'Metals']);

    expect(fn () => costCodes()->add($organization, ['code' => '05', 'name' => 'Metals again']))
        ->toThrow(InvalidBudgetDetail::class, 'already');

    expect(costCodes()->add(Organization::factory()->create(), ['code' => '05', 'name' => 'Metals'])->exists)->toBeTrue();
});

it('refuses a parent from another company', function () {
    $foreign = costCodes()->add(Organization::factory()->create(), ['code' => '06', 'name' => 'Wood']);

    $refusal = null;
    try {
        costCodes()->add(Organization::factory()->create(), ['code' => '06-1', 'name' => 'Framing', 'parent_id' => $foreign->getKey()]);
    } catch (InvalidBudgetDetail $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(InvalidBudgetDetail::class)
        ->and($refusal?->field)->toBe('parent_id');
});

it('renames a cost code and moves it under another parent', function () {
    $organization = Organization::factory()->create();
    $a = costCodes()->add($organization, ['code' => '07', 'name' => 'Thermal']);
    $leaf = costCodes()->add($organization, ['code' => '07-1', 'name' => 'Insulaton']);

    costCodes()->update($leaf, ['name' => 'Insulation', 'parent_id' => $a->getKey()]);

    expect($leaf->fresh()->name)->toBe('Insulation')
        ->and($leaf->fresh()->parent_id)->toBe($a->getKey());
});

it('refuses to change the code or company of a cost code', function (string $field, mixed $value) {
    $costCode = costCodes()->add(Organization::factory()->create(), ['code' => '08', 'name' => 'Openings']);

    expect(fn () => costCodes()->update($costCode, [$field => $value]))
        ->toThrow(InvalidBudgetDetail::class, $field);
})->with([
    'code' => ['code', '08-X'],
    'company' => ['organization_id', 999],
]);

it('refuses to move a cost code under its own descendant, beside the parent field', function () {
    $organization = Organization::factory()->create();
    $root = costCodes()->add($organization, ['code' => '09', 'name' => 'Finishes']);
    $child = costCodes()->add($organization, ['code' => '09-1', 'name' => 'Paint', 'parent_id' => $root->getKey()]);

    $refusal = null;
    try {
        costCodes()->update($root, ['parent_id' => $child->getKey()]);
    } catch (InvalidBudgetDetail $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(InvalidBudgetDetail::class)
        ->and($refusal?->field)->toBe('parent_id');

    expect(CostCode::query()->find($root->getKey())->parent_id)->toBeNull();
});
