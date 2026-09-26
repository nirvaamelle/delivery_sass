<?php

namespace App\Providers;

use App\Domain\Gates\Gatekeeper;
use App\Domain\Ops\JobFailureAlerts;
use App\Domain\Security\TwoFactorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One registry for the whole request. Gates are declared at boot and
        // read everywhere a transition can happen, so a fresh instance per
        // resolution would silently enforce nothing.
        $this->app->singleton(Gatekeeper::class);

        /*
         * P6-05. A singleton so the replay guard is ONE guard: a fresh instance
         * per resolution would each remember a different "last code accepted",
         * and the check would pass for whichever instance had not seen it.
         */
        $this->app->singleton(TwoFactorService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerWithholdingSchemaMacro();

        /*
         * P6-06. A closure rather than a class in app/Listeners: event
         * discovery would register a listener class a second time, and every
         * failure would then count twice. `recordSafely` never throws — it runs
         * inside the worker's own failure path, where an exception would turn
         * one failed job into a dead queue.
         */
        Event::listen(JobFailed::class, fn (JobFailed $event) => app(JobFailureAlerts::class)->recordSafely($event));
    }

    /**
     * One definition of the withholding columns, adopted by every money-bearing
     * document — F9.
     *
     * The finding was that these columns have to exist from a table's FIRST
     * migration. Adding a tax column to a table that already carries postings
     * means restating them, so the shape is fixed once, here, and every
     * document in Phase 1 and Phase 2 calls this rather than redeclaring it.
     * Four documents declaring "roughly the same" tax columns is how a ledger
     * ends up unable to total its own withholding.
     */
    private function registerWithholdingSchemaMacro(): void
    {
        Blueprint::macro('withholdingColumns', function (): void {
            /*
             * Which rate schedule was applied. Kept alongside the rate itself
             * because rates change: without the code, a historic posting cannot
             * be explained, only recomputed — and recomputing it with today's
             * rate gives the wrong answer.
             */
            $this->string('withholding_code')->nullable();

            /*
             * The rate as applied, at six decimal places so it can be expressed
             * to the basis point. Not DECIMAL(18,4): a rate is not money, and
             * four places cannot hold a rate finer than a hundredth of a
             * percent.
             */
            $this->decimal('withholding_rate', 9, 6)->nullable();

            /*
             * The amount withheld. DECIMAL(18,4) like every other money column
             * in the system, so it sums with them exactly.
             */
            $this->decimal('withholding_amount', 18, 4)->default('0.0000');

            /*
             * The BIR certificate reference (Form 2307 and its successors). A
             * withheld amount the supplier cannot claim back is a dispute, so
             * the reference travels with the amount.
             */
            $this->string('withholding_certificate_reference')->nullable();
        });
    }
}
