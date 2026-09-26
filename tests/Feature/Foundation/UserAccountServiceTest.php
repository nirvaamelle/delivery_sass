<?php

use App\Domain\Access\InvalidAccountDetail;
use App\Domain\Access\UserAccountService;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Sign-in accounts
|--------------------------------------------------------------------------
|
| Accounts were made by seeders and nothing else. Roles decide what an account
| opens, so the rules protect them: a role must exist, and the last
| administrator cannot be demoted.
|
*/

function accounts(): UserAccountService
{
    return app(UserAccountService::class);
}

beforeEach(function () {
    foreach (['admin', 'timekeeper', 'foreman'] as $role) {
        Role::findOrCreate($role);
    }
});

it('creates an account with roles and a hashed password', function () {
    $user = accounts()->create('Maria Santos', ' Maria.Santos@Example.com ', 'a-long-passphrase', ['timekeeper']);

    expect($user->email)->toBe('maria.santos@example.com')
        ->and($user->hasRole('timekeeper'))->toBeTrue()
        ->and(Hash::check('a-long-passphrase', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->password)->not->toBe('a-long-passphrase');
});

it('refuses a duplicate email', function () {
    accounts()->create('One', 'same@example.com', 'a-long-passphrase', []);

    expect(fn () => accounts()->create('Two', 'SAME@example.com', 'a-long-passphrase', []))
        ->toThrow(InvalidAccountDetail::class, 'already');
});

it('refuses a short password', function () {
    expect(fn () => accounts()->create('Short', 'short@example.com', 'too-short', []))
        ->toThrow(InvalidAccountDetail::class, '12 characters');
});

it('refuses a role that does not exist', function () {
    expect(fn () => accounts()->create('Typo', 'typo@example.com', 'a-long-passphrase', ['time-keeper']))
        ->toThrow(InvalidAccountDetail::class, 'time-keeper');

    expect(User::query()->where('email', 'typo@example.com')->exists())->toBeFalse();
});

it('changes roles', function () {
    $user = accounts()->create('Pedro', 'pedro@example.com', 'a-long-passphrase', ['timekeeper']);

    accounts()->setRoles($user, ['foreman']);

    expect($user->fresh()->getRoleNames()->all())->toBe(['foreman']);
});

it('refuses to demote the last administrator', function () {
    $admin = accounts()->create('Admin', 'only.admin@example.com', 'a-long-passphrase', ['admin']);

    expect(fn () => accounts()->setRoles($admin, ['foreman']))
        ->toThrow(InvalidAccountDetail::class, 'last administrator');

    expect($admin->fresh()->hasRole('admin'))->toBeTrue();
});

it('allows demoting an administrator when another remains', function () {
    accounts()->create('Admin A', 'a.admin@example.com', 'a-long-passphrase', ['admin']);
    $b = accounts()->create('Admin B', 'b.admin@example.com', 'a-long-passphrase', ['admin']);

    accounts()->setRoles($b, ['foreman']);

    expect($b->fresh()->hasRole('admin'))->toBeFalse();
});

it('updates name and email, refusing an email another account has', function () {
    accounts()->create('Taken', 'taken@example.com', 'a-long-passphrase', []);
    $user = accounts()->create('Old Name', 'old@example.com', 'a-long-passphrase', []);

    accounts()->updateDetails($user, 'New Name', 'new@example.com');
    expect($user->fresh()->name)->toBe('New Name');

    expect(fn () => accounts()->updateDetails($user, 'New Name', 'taken@example.com'))
        ->toThrow(InvalidAccountDetail::class, 'already');
});

it('sets a new password without logging it', function () {
    $user = accounts()->create('Reset', 'reset@example.com', 'a-long-passphrase', []);

    accounts()->setPassword($user, 'another-long-passphrase');

    expect(Hash::check('another-long-passphrase', $user->fresh()->password))->toBeTrue()
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain('another-long-passphrase');
});
