<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\assertDatabaseHas;

/*
|--------------------------------------------------------------------------
| Package wiring — P0-04
|--------------------------------------------------------------------------
|
| These prove the three spatie packages are wired into the application, not
| merely present in composer.json. PLAN.md §2 depends on all three:
| permission carries the approval matrix, activitylog is non-negotiable for an
| ERP that produces an audited P&L, and medialibrary holds every receipt, DR
| and test certificate.
|
*/

it('assigns a role to a user', function () {
    $role = Role::create(['name' => 'procurement-head']);
    $user = User::factory()->create();

    $user->assignRole($role);

    expect($user->fresh()->hasRole('procurement-head'))->toBeTrue();
});

it('grants a permission through a role', function () {
    $permission = Permission::create(['name' => 'approve.purchase-order']);
    $role = Role::create(['name' => 'finance-manager']);
    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->fresh()->can('approve.purchase-order'))->toBeTrue();
});

it('denies a permission the user was never granted', function () {
    $user = User::factory()->create();

    expect($user->can('approve.purchase-order'))->toBeFalse();
});

it('records an activity entry when a logged model changes', function () {
    $user = User::factory()->create();

    $user->update(['name' => 'Renamed Person']);

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toBe('updated')
        ->and($activity->subject_id)->toBe($user->id);
});

it('records who caused the change', function () {
    $actor = User::factory()->create(['name' => 'Approver']);
    auth()->login($actor);

    $subject = User::factory()->create();
    $subject->update(['name' => 'Changed By Approver']);

    $activity = Activity::query()->latest('id')->first();

    expect($activity->causer_id)->toBe($actor->id);
});

it('has the media table medialibrary needs for attachments', function () {
    // Model-level wiring lands with the first attachment-bearing document in
    // Phase 1 (receipts, DRs, test certificates). This asserts the schema is
    // in place so that task is not also a migration task.
    assertDatabaseHas('migrations', ['migration' => '2026_09_09_222914_create_media_table']);

    expect(Schema::hasTable('media'))->toBeTrue();
});
