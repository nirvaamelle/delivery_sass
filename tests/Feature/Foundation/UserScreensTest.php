<?php

use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\ProjectAssignmentsRelationManager;
use App\Filament\Resources\Users\UsersResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Accounts on screen
|--------------------------------------------------------------------------
|
| Accounts, roles and project assignments were seeder-only. The screen is the
| administrator's alone — whoever edits roles decides what everybody else can
| open — and it saves through UserAccountService and ProjectAccessService.
|
| **No password is ever shown or pre-filled.** It is set on create, and
| replaced by its own action afterwards.
|
*/

beforeEach(function () {
    foreach (['admin', 'timekeeper', 'foreman', 'finance-manager'] as $role) {
        Role::findOrCreate($role);
    }
});

it('is the administrator\'s screen and nobody else\'s', function (string $role, int $status) {
    actingAs(userWithRole($role));

    get(UsersResource::getUrl('index'))->assertStatus($status);
})->with([
    'admin' => ['admin', 200],
    'finance manager' => ['finance-manager', 403],
    'foreman' => ['foreman', 403],
]);

it('creates an account with roles', function () {
    actingAs(userWithRole('admin'));

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Rosa Timekeeper',
            'email' => 'rosa@example.com',
            'password' => 'a-long-passphrase',
            'roles' => ['timekeeper'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'rosa@example.com')->sole();

    expect($user->hasRole('timekeeper'))->toBeTrue()
        ->and(Hash::check('a-long-passphrase', $user->password))->toBeTrue();
});

it('shows a short password beside the password field', function () {
    actingAs(userWithRole('admin'));

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Short', 'email' => 'short@example.com', 'password' => 'short', 'roles' => []])
        ->call('create')
        ->assertHasFormErrors(['password']);
});

it('changes roles on the edit page, and never puts the password in the form', function () {
    actingAs(userWithRole('admin'));
    $user = userWithRole('timekeeper');

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertFormSet(['roles' => ['timekeeper']])
        ->assertFormFieldDoesNotExist('password')
        ->fillForm(['roles' => ['foreman']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->getRoleNames()->all())->toBe(['foreman']);
});

it('refuses to demote the last administrator on screen', function () {
    $admin = userWithRole('admin');
    actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
        ->fillForm(['roles' => ['foreman']])
        ->call('save')
        ->assertHasFormErrors(['roles']);

    expect($admin->fresh()->hasRole('admin'))->toBeTrue();
});

it('sets a new password by its own action', function () {
    actingAs(userWithRole('admin'));
    $user = userWithRole('foreman');

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->callAction('setPassword', data: ['password' => 'a-brand-new-passphrase']);

    expect(Hash::check('a-brand-new-passphrase', $user->fresh()->password))->toBeTrue();
});

it('assigns an account to a project and takes it off again', function () {
    actingAs(userWithRole('admin'));
    $user = userWithRole('foreman');
    $project = Project::factory()->create();

    Livewire::test(ProjectAssignmentsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class])
        ->callTableAction('assignProject', data: ['project_id' => $project->getKey(), 'role' => ProjectRole::Foreman->value]);

    expect(app(ProjectAccessService::class)->forUser($user)->pluck('project_id')->all())->toBe([$project->getKey()]);

    $assignment = app(ProjectAccessService::class)->forUser($user)->sole();

    Livewire::test(ProjectAssignmentsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class])
        ->callTableAction('unassignProject', $assignment);

    expect(app(ProjectAccessService::class)->forUser($user))->toHaveCount(0);
});
