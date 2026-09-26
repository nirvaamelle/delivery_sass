/**
 * Where the shared authenticated session is written.
 *
 * Its own module because playwright.config.js needs the path, and importing it
 * from auth.setup.js would evaluate a test declaration while the config is
 * still loading — which Playwright refuses outright.
 */
export const STORAGE_STATE = 'test-results/.auth/admin.json';
