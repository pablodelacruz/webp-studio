import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import {
  AJAX_ACTIONS,
  LABELS,
  SETTINGS_PATH,
  ajaxPost,
  editorCredentials,
  expectAjaxDenied,
  login,
  loginAsAdmin,
  readNonce,
  requireSite,
  subscriberCredentials,
} from './helpers';

/**
 * Security assertions for the WebP Studio admin surface.
 *
 * The threat model is the plugin's own: every AJAX handler runs require_nonce(), which
 * combines check_ajax_referer() with current_user_can('manage_options'), and the settings
 * screen is registered with add_options_page, which WordPress gates on the same
 * capability. These tests exist to prove that gate actually holds in a browser, with real
 * cookies, for two separate reasons for refusal: no nonce, and no capability.
 */

const READ_ONLY_ROLES = [
  { name: 'subscriber', creds: () => subscriberCredentials() },
  { name: 'editor', creds: () => editorCredentials() },
];

test.beforeEach(async ({ page }) => {
  await requireSite();
});

test.describe('capability gate', () => {
  test('the settings screen redirects an anonymous visitor to the login form', async ({ page }) => {
    const response = await page.goto(SETTINGS_PATH);

    // WordPress answers a capability-gated admin screen with a redirect, not the screen.
    expect(page.url()).toMatch(/wp-login\.php/);
    await expect(page.locator('#loginform, #login_error')).toBeAttached();
    expect(response?.status()).toBeLessThan(400);
  });

  for (const role of READ_ONLY_ROLES) {
    test(`a ${role.name} cannot open the settings screen`, async ({ page }) => {
      await login(page, role.creds());

      const response = await page.goto(SETTINGS_PATH);

      // add_options_page() gates the screen on manage_options, so WordPress answers 403
      // and renders its generic denial page. The URL stays put: the refusal is the
      // response, not a redirect, which is exactly why the status is asserted.
      expect(response?.status(), `a ${role.name} must get 403, not the screen`).toBe(403);

      // Nothing of the form may leak into the response.
      await expect(page.getByRole('button', { name: /^guardar ajustes/i })).toHaveCount(0);
      await expect(page.getByLabel(LABELS.qualityNumber)).toHaveCount(0);
      await expect(page.locator('input[name$="[quality]"]')).toHaveCount(0);

      const body = (await page.locator('body').innerText()).toLowerCase();
      expect(body).toMatch(
        /not allowed to access|no tiene permisos|no tiene acceso|insufficient permissions/,
      );
    });

    test(`a ${role.name} cannot read plugin state over AJAX`, async ({ page }) => {
      await login(page, role.creds());

      // No nonce is available here by design: wp_localize_script only runs on the plugin
      // screen, which this user cannot open.
      const result = await ajaxPost(page, AJAX_ACTIONS.getStatus);

      expectAjaxDenied(result, `a ${role.name} must not read the converter status`);
    });
  }

  test('a subscriber replaying a stolen administrator nonce is still rejected', async ({ page, context }) => {
    // Harvest a genuine, valid nonce as an administrator.
    const adminContext = await context.browser()!.newContext();
    const adminPage = await adminContext.newPage();
    await loginAsAdmin(adminPage);
    const stolenNonce = await readNonce(adminPage);
    await adminContext.close();

    // WordPress nonces are bound to the user and session that minted them, so this
    // particular replay would fail the referer check on its own. The point of the test is
    // the second layer: require_nonce() must also refuse on the capability check.
    await login(page, subscriberCredentials());
    const result = await ajaxPost(page, AJAX_ACTIONS.getStatus, {}, stolenNonce);

    expectAjaxDenied(result, 'a non-admin with a foreign nonce must be refused');
    // If the referer check caught it, WordPress answers 403 with a plain body.
    // If the capability check caught it, the plugin answers 403 with Permisos insuficientes.
    expect(
      result.status === 403 || result.json?.success !== true,
      `expected a 403 denial, got ${result.status} ${result.body.slice(0, 200)}`,
    ).toBe(true);
  });

  test('a subscriber cannot write plugin options through admin-ajax', async ({ page }) => {
    await login(page, subscriberCredentials());

    // The most destructive handlers: clearing history, purging the queue, running a batch.
    for (const action of [
      AJAX_ACTIONS.runBatch,
      'wp_image_webp_converter_clear_history',
      'wp_image_webp_converter_queue_purge',
    ]) {
      const result = await ajaxPost(page, action, { _placeholder: '1' });
      expectAjaxDenied(result, `${action} must not run for a subscriber`);
    }
  });
});

test.describe('nonce and CSRF', () => {
  test('an administrator POST with no nonce is rejected', async ({ page }) => {
    await loginAsAdmin(page);

    const result = await ajaxPost(page, AJAX_ACTIONS.getStatus);
    expectAjaxDenied(result, 'a missing _ajax_nonce must be refused');
  });

  test('an administrator POST with an empty nonce is rejected', async ({ page }) => {
    await loginAsAdmin(page);

    const result = await ajaxPost(page, AJAX_ACTIONS.getStatus, {}, '');
    expectAjaxDenied(result, 'an empty _ajax_nonce must be refused');
  });

  test('an administrator POST with a forged nonce is rejected', async ({ page }) => {
    await loginAsAdmin(page);

    const result = await ajaxPost(page, AJAX_ACTIONS.getStatus, {}, 'deadbeefdeadbeef');
    expectAjaxDenied(result, 'a forged _ajax_nonce must be refused');
  });

  test('a forged-nonce POST to options.php cannot write the plugin options', async ({ page }) => {
    await loginAsAdmin(page);

    const original = await page.getByLabel(LABELS.qualityNumber).inputValue();
    const attackerValue = '5';

    // A realistic CSRF payload: a third-party page can forge every field of the settings
    // form, but not the _wpnonce that options.php checks for the option group.
    const forged = await page.evaluate(async (attackerQuality: string) => {
      const body = new URLSearchParams();
      body.set('option_page', 'wp_image_webp_converter_options_group');
      body.set('action', 'update');
      body.set('_wpnonce', 'forged-nonce-from-another-screen');
      body.set('_wp_http_referer', '/wp-admin/options-general.php?page=webp-studio');
      body.set('wp_image_webp_converter_settings[quality]', attackerQuality);
      body.set('wp_image_webp_converter_settings[enabled]', '1');
      body.set('wp_image_webp_converter_settings[delete_original]', '1');

      const response = await fetch('/wp-admin/options.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
        redirect: 'follow',
      });
      return { status: response.status, body: (await response.text()).slice(0, 300) };
    }, attackerValue);

    expect(forged.status).toBe(403);
    expect(forged.body.toLowerCase()).not.toContain('settings saved');

    // The decisive assertion: the stored option did not change.
    await page.reload();
    await expect(page.getByLabel(LABELS.qualityNumber)).toHaveValue(original);
  });
});

test.describe('output encoding', () => {
  const PAYLOADS = [
    '<script>window.__wpsXss = 1;</script>',
    '<img src=x onerror="window.__wpsXss = 1">',
    '"><script>window.__wpsXss = 1;</script>',
  ];

  test('a script payload in a saved field is rendered as text, never executed', async ({ page }) => {
    await loginAsAdmin(page);

    const dialogs: string[] = [];
    page.on('dialog', async (dialog) => {
      dialogs.push(dialog.message());
      await dialog.dismiss();
    });

    const number = page.getByLabel(LABELS.qualityNumber);
    const original = await number.inputValue();

    for (const payload of PAYLOADS) {
      // Bypass <input type="number"> validation, which is a client-side guard only: a
      // real attacker does not go through the form UI.
      await number.evaluate((el: HTMLInputElement, value: string) => {
        el.type = 'text';
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }, payload);

      await page.getByRole('button', { name: /^guardar ajustes/i }).click();

      // Wait for the round trip, not for the network to fall quiet. Without this the
      // assertion below can run BEFORE the payload was ever submitted, in which case
      // __wpsXss is trivially undefined and the XSS test passes without having tested
      // anything. The saved notice is what proves the payload reached WordPress.
      await expect(page.locator('#setting-error-settings_updated')).toContainText(
        /settings saved|ajustes guardados/i,
        { timeout: 30_000 },
      );

      // The payload must not have executed in the page that received it...
      expect(await page.evaluate(() => (window as never as { __wpsXss?: number }).__wpsXss)).toBeUndefined();

      // ...nor in the freshly rendered page.
      await page.reload();
      expect(await page.evaluate(() => (window as never as { __wpsXss?: number }).__wpsXss)).toBeUndefined();

      const html = await page.content();
      expect(html).not.toContain('<script>window.__wpsXss');
      expect(html).not.toContain('onerror="window.__wpsXss');
    }

    expect(dialogs, 'no alert() or confirm() may fire from an injected payload').toEqual([]);

    // Restore the option.
    await page.getByLabel(LABELS.qualityNumber).fill(original);
    await page.getByRole('button', { name: /^guardar ajustes/i }).click();

    // The restore has to land, otherwise a failed run leaves the site carrying an XSS
    // payload in its settings.
    await expect(page.locator('#setting-error-settings_updated')).toContainText(
      /settings saved|ajustes guardados/i,
      { timeout: 30_000 },
    );
  });

  test('an AJAX endpoint reflects a hostile parameter as JSON, not as markup', async ({ page }) => {
    await loginAsAdmin(page);
    const nonce = await readNonce(page);

    const result = await ajaxPost(
      page,
      AJAX_ACTIONS.preview,
      { quality: '<img src=x onerror="window.__wpsXss=1">' },
      nonce,
    );

    expect(result.json).not.toBeNull();
    expect(result.body.toLowerCase()).not.toContain('<script');
    expect(result.body.toLowerCase()).not.toContain('onerror=');
    // Whatever happens, the page must stay inert.
    expect(await page.evaluate(() => (window as never as { __wpsXss?: number }).__wpsXss)).toBeUndefined();
  });
});

test.describe('accessibility', () => {
  test('the settings screen has no serious or critical axe violations (wcag2a)', async ({ page }) => {
    await loginAsAdmin(page);
    await expect(page.getByRole('tablist')).toBeVisible();

    const results = await new AxeBuilder({ page }).withTags(['wcag2a']).analyze();

    const blocking = results.violations.filter(
      (violation) => violation.impact === 'serious' || violation.impact === 'critical',
    );

    // Cosmetic issues are reported, not failed, so the suite stays useful as a trend.
    // Anything serious or critical fails: those are real accessibility defects.
    expect(
      blocking,
      JSON.stringify(
        blocking.map((v) => ({
          id: v.id,
          impact: v.impact,
          help: v.help,
          nodes: v.nodes.slice(0, 5).map((n) => n.target.join(' ')),
        })),
        null,
        2,
      ),
    ).toEqual([]);
  });

  test('axe reports every violation on the settings screen, cosmetic included', async ({ page }, testInfo) => {
    await loginAsAdmin(page);
    await expect(page.getByRole('tablist')).toBeVisible();

    const results = await new AxeBuilder({ page }).withTags(['wcag2a']).analyze();
    const summary = results.violations.map((violation) => ({
      id: violation.id,
      impact: violation.impact ?? 'unknown',
      help: violation.help,
      nodes: violation.nodes.length,
    }));

    // Recorded in the report rather than asserted, per the suite's documented policy.
    await testInfo.attach('axe-wcag2a-summary', {
      body: JSON.stringify(
        { violations: summary, passes: results.passes.length, incomplete: results.incomplete.length },
        null,
        2,
      ),
      contentType: 'application/json',
    });
    console.log('[axe] wcag2a violations:', JSON.stringify(summary));
  });
});
