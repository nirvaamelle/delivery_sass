import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';

/**
 * A TOTP generator for the console gate.
 *
 * P6-05a asks every Finance, HR and Admin account for a code at sign-in, and
 * the gate signs in as the local admin. So the gate needs to do what a phone
 * does: read the shared secret and compute the code.
 *
 * It is deliberately NOT a bypass. There is no test-only route, no "skip 2FA in
 * testing" flag, and no exemption on the seeded account — any of those would be
 * a hole in exit gate clause 4 that lives in the codebase and reaches staging
 * the first time somebody copies the config. The gate proves the real thing by
 * satisfying it.
 *
 * The secret is written by `DatabaseSeeder` in local and testing only, and is
 * gitignored.
 */

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** Decode a base32 secret to the raw bytes HMAC needs. */
function base32Decode(secret) {
  const clean = secret.replace(/=+$/, '').toUpperCase();
  let bits = '';

  for (const character of clean) {
    const value = BASE32.indexOf(character);

    if (value === -1) {
      throw new Error(`Invalid base32 character: ${character}`);
    }

    bits += value.toString(2).padStart(5, '0');
  }

  const bytes = [];

  for (let i = 0; i + 8 <= bits.length; i += 8) {
    bytes.push(parseInt(bits.slice(i, i + 8), 2));
  }

  return Buffer.from(bytes);
}

/**
 * The six-digit code an authenticator would be showing right now.
 *
 * RFC 6238 with the defaults every authenticator app uses: SHA-1, a
 * thirty-second window, six digits.
 */
export function currentCode(secret, windowsAhead = 0) {
  const counter = Math.floor(Date.now() / 1000 / 30) + windowsAhead;

  const buffer = Buffer.alloc(8);
  buffer.writeUInt32BE(Math.floor(counter / 0x100000000), 0);
  buffer.writeUInt32BE(counter % 0x100000000, 4);

  const digest = createHmac('sha1', base32Decode(secret)).update(buffer).digest();

  // Dynamic truncation, RFC 4226 §5.4.
  const offset = digest[digest.length - 1] & 0x0f;
  const binary =
    ((digest[offset] & 0x7f) << 24) |
    ((digest[offset + 1] & 0xff) << 16) |
    ((digest[offset + 2] & 0xff) << 8) |
    (digest[offset + 3] & 0xff);

  return String(binary % 1_000_000).padStart(6, '0');
}

/** The local admin's secret, as written by the seeder. */
export function seededSecret() {
  return readFileSync('storage/app/private/dev-2fa-secret.txt', 'utf8').trim();
}
