<?php

namespace Database\Seeders;

use App\Domain\Ops\DemoSeedGuard;
use App\Domain\Vendors\VendorService;
use App\Domain\Vendors\VendorSuspensionReason;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Three vendors that show the register doing its job.
 *
 * Deliberately not three healthy vendors. The register's whole value is that it
 * distinguishes them, and a seed where everything is fine demonstrates nothing:
 * one is current, one has a lapsed certificate, one is suspended. Only the first
 * can receive an RFQ, and the screen says so without anyone having to check
 * dates by hand.
 */
class DemoVendorSeeder extends Seeder
{
    public function run(): void
    {
        // Demo vendors are demo content, refused in production (P6-08). Guarded here
        // as well as in DatabaseSeeder, because `db:seed --class=` skips the parent.
        app(DemoSeedGuard::class)->assertMaySeed((string) app()->environment());

        $organization = Organization::firstOrCreate(
            ['code' => 'MBI'],
            ['name' => 'MBI Construction'],
        );

        $actor = User::query()->first();
        $vendors = app(VendorService::class);

        $current = Vendor::updateOrCreate(
            ['code' => 'VEN-0001'],
            ['organization_id' => $organization->id, 'name' => 'Northgate Cement Supply', 'tin' => '001-234-567-000'],
        );
        $vendors->accredit($current, now()->subMonths(2), 'ACC-2026-0001', $actor);

        $lapsed = Vendor::updateOrCreate(
            ['code' => 'VEN-0002'],
            ['organization_id' => $organization->id, 'name' => 'Bayview Steel Traders', 'tin' => '002-345-678-000'],
        );
        $vendors->accredit($lapsed, now()->subMonths(18), 'ACC-2024-0007', $actor);

        $suspended = Vendor::updateOrCreate(
            ['code' => 'VEN-0003'],
            ['organization_id' => $organization->id, 'name' => 'Sunrise Aggregates', 'tin' => '003-456-789-000'],
        );
        $vendors->accredit($suspended, now()->subMonths(3), 'ACC-2026-0012', $actor);

        if ($actor !== null) {
            $vendors->suspend(
                $suspended,
                VendorSuspensionReason::LateDeliveries,
                $actor,
                'Two late deliveries in the current quarter.',
            );
        }
    }
}
