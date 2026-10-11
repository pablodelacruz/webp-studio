import { existsSync, readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';

/**
 * Shared helpers for the WebP Studio E2E suite.
 *
 * Design notes
 * ------------
 * - Selectors are role/label based. The plugin markup ships no data-testid and none was
 *   added: every field on the settings screen is reachable through an accessible name
 *   (getByRole/getByLabel) or a stable input `name`, so test-only hooks in production
 *   markup would buy nothing.
 * - The admin UI is only available in Spanish (the plugin ships no .pot/.po files), so
 *   accessible names are matched as Spanish literals. If the plugin is translated, the
 *   label constants below are the single place to update.
 */

export const SETTINGS_PATH = '/wp-admin/admin.php?page=webp-studio';
export const LOGIN_PATH = '/wp-login.php';
export const AJAX_PATH = '/wp-admin/admin-ajax.php';

/** Option group option name, `WP_Image_WebP_Converter::OPTION_NAME`. */
export const OPTION_FIELD = 'wp_image_webp_converter_settings';

/** Accessible names of the settings controls, verbatim from the plugin markup. */
export const LABELS = {
  enabledCheckbox: 'Conversión automática',
  qualitySlider: 'Calidad WebP',
  qualityNumber: 'Calidad WebP, valor numérico',
  originalsKeep: 'Conservar originales',
  originalsDelete: 'Eliminar después de convertir',
} as const;

/** AJAX actions exposed by the plugin, all `wp_ajax_`-only (no nopriv variants). */
export const AJAX_ACTIONS = {
  getStatus: 'wp_image_webp_converter_get_status',
  runBatch: 'wp_image_webp_converter_run_batch',
  preview: 'wp_image_webp_converter_preview',
  getHistory: 'wp_image_webp_converter_get_history',
  toggleActivity: 'wp_image_webp_converter_toggle_activity_log',
  queueStatus: 'wp_image_webp_converter_queue_status',
} as const;

export interface Credentials {
  user: string;
  password: string;
}

/**
 * Reads the developer `.env` next to the repo root, when present.
 *
 * `.env` is gitignored and holds local Docker credentials; loading it is a convenience
 * so the local suite runs without exporting variables. CI has no `.env` and falls back to
 * process.env exclusively.
 */
export function localEnv(): Record<string, string> {
  const envFile = path.resolve(process.cwd(), '.env');
  const values: Record<string, string> = {};
  if (!existsSync(envFile)) return values;

  for (const rawLine of readFileSync(envFile, 'utf8').split('\n')) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq === -1) continue;
    values[line.slice(0, eq).trim()] = line.slice(eq + 1).trim();
  }
  return values;
}

/**
 * Administrator account used by the suite.
 *
 * Deliberately a dedicated account, not the human's own: the tests write options, and
 * provisionTestUsers() has to be able to reset that account's password idempotently.
 * E2E_ADMIN_USER / E2E_ADMIN_PASS override the default (CI sets its own); local .env is
 * used as a last-resort fallback so the suite works on a developer machine untouched.
 */
export function adminCredentials(): Credentials {
  return {
    user: process.env.E2E_ADMIN_USER || localEnv().E2E_ADMIN_USER || 'webp-e2e-admin',
    password: process.env.E2E_ADMIN_PASS || localEnv().E2E_ADMIN_PASS || 'webp-e2e-admin-pass',
  };
}

/** Subscriber account: logged in, but without manage_options. */
export function subscriberCredentials(): Credentials {
  return {
    user: process.env.E2E_SUBSCRIBER_USER || 'webp-e2e-subscriber',
    password: process.env.E2E_SUBSCRIBER_PASS || 'webp-e2e-subscriber-pass',
  };
}

/** Editor account: also lacks manage_options, so it guards a higher-privilege role. */
export function editorCredentials(): Credentials {
  return {
    user: process.env.E2E_EDITOR_USER || 'webp-e2e-editor',
    password: process.env.E2E_EDITOR_PASS || 'webp-e2e-editor-pass',
  };
}

export function subscriberLogin(): string {
  return process.env.E2E_SUBSCRIBER_USER || 'webp-e2e-subscriber';
}

export function editorLogin(): string {
  return process.env.E2E_EDITOR_USER || 'webp-e2e-editor';
}

/**
 * Logs in through wp-login.php and waits for the admin bar.
 *
 * wp-login.php is used on purpose instead of APIRequestContext: the cookie jar has to
 * live in the browser context, because the AJAX helper below runs fetch() inside the page
 * so that the WordPress cookies and the logged-in user are exactly the browser's.
 */
export async function login(page: Page, creds: Credentials, redirectTo = '/wp-admin/'): Promise<void> {
  await page.goto(LOGIN_PATH);

  // wp-login.php shows a "cookies blocked" notice in some headless configurations; the
  // form is still submitted, so this is informational only.
  await page.locator('#user_login').fill(creds.user);
  await page.locator('#user_pass').fill(creds.password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith(LOGIN_PATH), { timeout: 30_000 }),
    page.locator('#wp-submit').click(),
  ]);

  if (redirectTo !== '/wp-admin/') {
    await page.goto(redirectTo);
  }
  await expect(page.locator('#wpadminbar')).toBeAttached();
}

export async function loginAsAdmin(page: Page): Promise<void> {
  await login(page, adminCredentials(), SETTINGS_PATH);
}

export function baseUrl(): string {
  return process.env.E2E_BASE_URL || 'http://localhost:8080';
}

/** True when a WordPress instance answers on the configured base URL. */
export async function siteReachable(): Promise<boolean> {
  try {
    const response = await fetch(new URL('/wp-login.php', baseUrl()), {
      redirect: 'manual',
      signal: AbortSignal.timeout(15_000),
    });
    return response.status < 500;
  } catch {
    return false;
  }
}

/**
 * Skips the calling test when no WordPress instance is reachable.
 *
 * The suite never provisions a site itself: without one, a red run would be noise about
 * a missing environment rather than a real regression.
 */
export async function requireSite(): Promise<void> {
  test.skip(
    !(await siteReachable()),
    `No WordPress instance at ${baseUrl()}. Start the Docker stack (or set E2E_BASE_URL) ` +
      'and re-run: these specs need a real site to exercise the admin UI.',
  );
}

/**
 * Runs wp-cli, either through `docker exec` against the local stack or directly.
 *
 * Returns null when no wp-cli is available, so callers can document the requirement
 * instead of failing: on CI the users are created by the workflow itself.
 */
export function wpCli(args: string[]): string | null {
  const container = process.env.E2E_WP_CLI_CONTAINER || 'wpimgplugin-wordpress-1';
  const docRoot = process.env.E2E_WP_DOCROOT || '/var/www/html';

  try {
    if (process.env.E2E_WP_CLI_DIRECT === '1') {
      return execFileSync('wp', ['--path=' + docRoot, ...args], { encoding: 'utf8' });
    }
    return execFileSync(
      'docker',
      ['exec', container, 'wp', '--path=' + docRoot, ...args],
      { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
    );
  } catch {
    return null;
  }
}

/** Creates (or updates) a user with the given role. Returns false if wp-cli is missing. */
export function ensureUser(login: string, password: string, role: string, email: string): boolean {
  const existing = wpCli(['user', 'get', login, '--field=ID']);
  if (existing && existing.trim()) {
    wpCli(['user', 'update', login, '--user_pass=' + password, '--role=' + role]);
    return true;
  }
  const created = wpCli([
    'user',
    'create',
    login,
    email,
    '--user_pass=' + password,
    '--role=' + role,
    '--porcelain',
  ]);
  return created !== null;
}

/**
 * Creates the low-privilege accounts the security spec needs.
 *
 * Called from globalSetup so the spec itself only asserts behaviour. Failure is reported,
 * not swallowed silently.
 */
export function provisionTestUsers(): { admin: boolean; subscriber: boolean; editor: boolean } {
  const admin = adminCredentials();
  const sub = subscriberCredentials();
  const ed = editorCredentials();

  const adminReady = ensureUser(
    admin.user,
    admin.password,
    'administrator',
    `${admin.user}@e2e.invalid`,
  );
  const subscriber = ensureUser(
    sub.user,
    sub.password,
    'subscriber',
    `${sub.user}@e2e.invalid`,
  );
  const editor = ensureUser(ed.user, ed.password, 'editor', `${ed.user}@e2e.invalid`);

  if (!adminReady || !subscriber || !editor) {
    console.warn(
      '[e2e] wp-cli unavailable: the E2E accounts were NOT created. The security spec ' +
        'will skip the role-restriction tests and the admin specs will fail on login. ' +
        'Create them manually with `wp user create <login> <login>@e2e.invalid --role=...` ' +
        '(or set E2E_WP_CLI_CONTAINER / E2E_WP_CLI_DIRECT=1).',
    );
  }
  return { admin: adminReady, subscriber, editor };
}

/** Reads the AJAX nonce localized into the page as `wpsStudio.nonce`. */
export async function readNonce(page: Page): Promise<string> {
  const nonce = await page.evaluate(() => {
    const wps = (window as unknown as { wpsStudio?: { nonce?: string } }).wpsStudio;
    return wps?.nonce ?? '';
  });
  expect(nonce, 'wpsStudio.nonce must be localized on the settings screen').not.toEqual('');
  return nonce;
}

export interface AjaxResult {
  status: number;
  /** Raw response body, so non-JSON rejections can be asserted on too. */
  body: string;
  /** Parsed JSON body when the response was JSON, otherwise null. */
  json: { success?: boolean; data?: unknown } | null;
}

/**
 * POSTs to admin-ajax.php from inside the page.
 *
 * Running through page.evaluate (rather than request.post) is deliberate: the browser's
 * own cookie jar decides who the server thinks we are, which is exactly the property the
 * security assertions are about. `nonceOverride` lets a test deliberately send a bad,
 * empty or borrowed nonce.
 */
export async function ajaxPost(
  page: Page,
  action: string,
  data: Record<string, string> = {},
  nonceOverride?: string,
): Promise<AjaxResult> {
  return page.evaluate(
    async ({ ajaxUrl, action: act, payload, nonce }) => {
      const body = new URLSearchParams();
      body.set('action', act);
      if (nonce !== undefined) body.set('_ajax_nonce', nonce);
      for (const [key, value] of Object.entries(payload)) body.set(key, value);

      const response = await fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
        redirect: 'follow',
      });

      const text = await response.text();
      let json: { success?: boolean; data?: unknown } | null = null;
      try {
        json = JSON.parse(text);
      } catch {
        json = null;
      }
      return { status: response.status, body: text, json };
    },
    { ajaxUrl: AJAX_PATH, action, payload: data, nonce: nonceOverride },
  );
}

/** Asserts an AJAX call did not succeed, whatever the failure mode. */
export function expectAjaxDenied(result: AjaxResult, because: string): void {
  expect(
    result.json?.success,
    `${because} — response: ${result.status} ${result.body.slice(0, 300)}`,
  ).not.toBe(true);
}

/** Collects uncaught JS errors and console errors for the duration of a test. */
export function collectPageErrors(page: Page): { errors: string[]; stop: () => string[] } {
  const errors: string[] = [];

  page.on('pageerror', (error) => {
    errors.push(`pageerror: ${error.message}`);
  });
  page.on('console', (message) => {
    if (message.type() === 'error') {
      errors.push(`console.error: ${message.text()}`);
    }
  });

  return { errors, stop: () => errors.splice(0, errors.length) };
}
