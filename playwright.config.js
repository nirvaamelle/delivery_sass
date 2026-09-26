import { defineConfig, devices } from '@playwright/test';
import { STORAGE_STATE } from './tests/Browser/storage-state.js';

/**
 * Browser-console gate for the build loop.
 *
 * PHASE-PLAN.md requires every iteration to prove the console is clean before a
 * task can be marked done. Driving that by hand is unreliable, so it lives here
 * as a runnable test: `npm run test:console`.
 *
 * Two projects, deliberately. `setup` signs in once and saves the session;
 * `chromium` reuses it. Signing in per test hit Filament's `rateLimit(5)` on the
 * sixth screen — the throttle is correct (PLAN.md §3 asks for a rate-limited
 * login), so the gate is what had to change. One session also covers more
 * screens per run and matches how the panel is really used.
 *
 * The webServer block boots `php artisan serve` on a free port and tears it down
 * afterwards, so the check needs no server running beforehand.
 */
export default defineConfig({
  testDir: './tests/Browser',
  fullyParallel: false,
  workers: 1,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: 'http://127.0.0.1:8123',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.js/,
    },
    {
      name: 'chromium',
      testIgnore: /auth\.setup\.js/,
      use: { ...devices['Desktop Chrome'], storageState: STORAGE_STATE },
      dependencies: ['setup'],
    },
  ],
  webServer: {
    command: 'php artisan serve --port=8123 --no-reload',
    url: 'http://127.0.0.1:8123/admin/login',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
});
