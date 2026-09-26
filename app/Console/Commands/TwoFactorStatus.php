<?php

namespace App\Console\Commands;

use App\Domain\Security\TwoFactorService;
use Illuminate\Console\Command;

/**
 * `php artisan security:two-factor-status`
 *
 * Exit gate clause 4, as something somebody can check rather than assert. It
 * exits non-zero while any covered account is unenrolled, so the answer to
 * "is clause 4 met" is a command, not an opinion — and CI can ask it.
 */
class TwoFactorStatus extends Command
{
    protected $signature = 'security:two-factor-status';

    protected $description = 'List Finance, HR and Admin accounts that have not enrolled in two-factor authentication';

    public function handle(TwoFactorService $twoFactor): int
    {
        if (! config('security.two_factor_enabled')) {
            // Not green. With enforcement off, an empty "outstanding" list would
            // read as clause 4 being met when nobody is asked for a code at all.
            $this->error('Two-factor authentication is switched off (TWO_FACTOR_ENABLED=false). Exit gate clause 4 is not met while it is.');

            return self::FAILURE;
        }

        $outstanding = $twoFactor->outstanding();

        if ($outstanding->isEmpty()) {
            $this->info(sprintf(
                'Every account holding %s has two-factor authentication enrolled.',
                implode(' / ', $twoFactor->requiredRoles()),
            ));

            return self::SUCCESS;
        }

        $this->error(sprintf('%d account(s) still to enrol:', $outstanding->count()));

        $this->table(
            ['Name', 'Email', 'Roles'],
            $outstanding->map(fn ($user): array => [
                (string) $user->name,
                (string) $user->email,
                $user->getRoleNames()->implode(', '),
            ])->all(),
        );

        return self::FAILURE;
    }
}
