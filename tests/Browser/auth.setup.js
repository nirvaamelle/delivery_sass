import { expect, test as setup } from '@playwright/test';
import { STORAGE_STATE } from './storage-state.js';
import { currentCode, seededSecret } from './totp.js';

/**
 * Sign in once, save the session, and let every console test reuse it.
 *
 * This exists because the gate hit a real limit: Filament's login page calls
 * `$this->rateLimit(5)`, so the sixth sign-in in a minute is throttled from the
 * same IP. Signing in per test meant the gate could never cover more than five
 * screens — and Phase 1 alone adds around ten. The throttle is correct
 * behaviour (PLAN.md §3 asks for a rate-limited login); the test approach was
 * what needed fixing.
 *
 * Reusing one session is also closer to how the app is actually used: nobody
 * logs in again between screens.
 *
 * From P6-05a the sign-in also answers a TOTP challenge, because the seeded
 * admin holds a role the exit gate requires a second factor of. The gate does
 * what a phone does rather than skipping the step: there is no test-only
 * bypass, no "disable 2FA when testing" flag and no exemption on the account.
 * Any of those would be a hole in clause 4 living in the codebase, and it would
 * reach staging the first time somebody copied the config.
 */

const ADMIN = { email: 'admin@construction.test', password: 'password' };

setup('authenticate once', async ({ page }) => {
  await page.goto('/admin/login');

  // Wait for the Livewire COMPONENT to be registered, not merely for the
  // Livewire global to exist. window.Livewire appears as soon as the script
  // evaluates, but the form is inert until its component is bound, and a click
  // in that window is silently discarded — which reads exactly like bad
  // credentials and is not.
  await page.waitForFunction(
    () => window.Livewire !== undefined && window.Livewire.all().length > 0,
  );

  // Target the input ids directly. Filament renders no <label for=...> in the
  // server HTML, and the password field gets its type from Alpine at runtime,
  // so label- and type-based locators race hydration.
  await page.locator('input[id="form.email"]').fill(ADMIN.email);
  await page.locator('input[id="form.password"]').fill(ADMIN.password);

  await page.getByRole('button', { name: 'Sign in' }).click();

  // Settled, not merely navigated. Signing in lands on /admin and is then
  // redirected to the challenge, so a URL checked mid-flight reports the
  // dashboard and the second factor is skipped.
  await page.waitForLoadState('networkidle');

  // The second factor, if this account is one the gate covers.
  if (page.url().includes('/two-factor/challenge')) {
    await page.locator('#code').fill(currentCode(seededSecret()));

    await Promise.all([
      page.waitForURL('**/admin', { timeout: 15000 }),
      page.getByRole('button', { name: 'Continue' }).click(),
    ]);
  }

  await expect(page).toHaveTitle(/Dashboard/);

  await page.context().storageState({ path: STORAGE_STATE });
});
