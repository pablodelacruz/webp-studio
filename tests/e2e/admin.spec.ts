import { expect, test } from '@playwright/test';
import {
  AJAX_ACTIONS,
  LABELS,
  OPTION_FIELD,
  ajaxPost,
  collectPageErrors,
  loginAsAdmin,
  readNonce,
  requireSite,
} from './helpers';

/**
 * Functional coverage for the WebP Studio settings screen.
 *
 * The plugin already has 182 runtime assertions in tests/verify-runtime.php; this file
 * covers what only a real browser can prove: that the screen renders, that saving a
 * control round-trips through options.php, and that the admin JS runs without throwing.
 */
test.beforeEach(async ({ page }) => {
  await requireSite();
});

test.describe('settings screen', () => {
  test('an administrator can sign in and reach the settings page', async ({ page }) => {
    await loginAsAdmin(page);

    await expect(page).toHaveURL(/page=webp-studio/);
    await expect(page.getByRole('tablist')).toBeVisible();
    await expect(page.getByRole('tab', { name: /configuraci/i })).toBeVisible();
    await expect(page.locator('#wpadminbar')).toBeAttached();
  });

  test('every expected control renders and reports the saved state', async ({ page }) => {
    await loginAsAdmin(page);

    // Registered setting fields. process_existing and activity_log are deliberately
    // absent: the plugin retired the former and toggles the latter from the diagnostics
    // card via AJAX, so a form control for them would be a bug.
    await expect(page.getByLabel(LABELS.enabledCheckbox, { exact: true })).toBeAttached();
    await expect(page.getByRole('slider', { name: LABELS.qualitySlider })).toBeVisible();
    await expect(page.getByLabel(LABELS.qualityNumber)).toBeVisible();
    await expect(page.getByRole('radio', { name: LABELS.originalsKeep })).toBeAttached();
    await expect(page.getByRole('radio', { name: LABELS.originalsDelete })).toBeAttached();

    // Quality slider and number input are two views of one option: the plugin JS keeps
    // them in sync, and both must submit the same field name.
    const slider = page.getByRole('slider', { name: LABELS.qualitySlider });
    const number = page.getByLabel(LABELS.qualityNumber);
    await expect(number).toHaveAttribute('name', `${OPTION_FIELD}[quality]`);

    const sliderValue = await slider.inputValue();
    await expect(number).toHaveValue(sliderValue);

    // The quality value is rendered escaped inside <output>.
    await expect(page.locator('[data-control="quality-value"]')).toHaveText(sliderValue);

    // Exactly one originals radio is checked.
    await expect(page.locator('input[name$="[delete_original]"]:checked')).toHaveCount(1);
  });

  test('changing the quality and saving persists across a reload', async ({ page }) => {
    await loginAsAdmin(page);

    const number = page.getByLabel(LABELS.qualityNumber);
    const slider = page.getByRole('slider', { name: LABELS.qualitySlider });
    const original = await number.inputValue();

    // Pick a value that is definitely not the current one, and stay inside the slider range.
    const target = original === '72' ? '61' : '72';
    await number.fill(target);
    // The number input is the submitted field; the slider mirrors it client-side.
    await expect(slider).toHaveValue(target);

    await page.getByRole('button', { name: /^guardar ajustes/i }).click();

    // No wait for network silence here. WordPress answers a settings POST with a
    // redirect back to the same screen, and networkidle races the admin page's own
    // 3s status poll: it settles only when the poll window happens to fall right.
    // The notice below cannot exist on the pre-save page, so asserting on it is both
    // the real condition and inherently navigation-safe.

    // WordPress redirects back to the same screen and renders an admin notice; the core
    // string is English while the plugin's own strings are Spanish, so both are accepted.
    await expect(page.locator('#setting-error-settings_updated')).toContainText(
      /settings saved|ajustes guardados/i,
    );

    await page.reload();
    await expect(page.getByLabel(LABELS.qualityNumber)).toHaveValue(target);
    await expect(page.getByRole('slider', { name: LABELS.qualitySlider })).toHaveValue(target);

    // Restore, so a failed run does not leave the instance with a surprising setting.
    await page.getByLabel(LABELS.qualityNumber).fill(original);
    await page.getByRole('button', { name: /^guardar ajustes/i }).click();

    // The restore is a real write and has to actually land, or a failed run leaves the
    // instance carrying whatever the test was asserting against. Wait for the notice,
    // not for the network to fall quiet.
    await expect(page.locator('#setting-error-settings_updated')).toContainText(
      /settings saved|ajustes guardados/i,
    );
  });

  test('the page loads with no uncaught JavaScript errors', async ({ page }) => {
    const collector = collectPageErrors(page);

    await loginAsAdmin(page);

    // Wait for the deferred admin script to actually poll, which is the observable
    // proof it booted. networkidle cannot express that: the page is never idle on
    // purpose, because the script polls every 3 seconds by design.
    await page.waitForResponse(
      (r) => r.url().includes('admin-ajax.php') && r.url().includes(AJAX_ACTIONS.getStatus),
      { timeout: 30_000 },
    );
    await expect(page.getByRole('tablist')).toBeVisible();

    // Visit every tab: each panel mounts markup and bindings on show.
    for (const name of [/biblioteca/i, /diagn/i, /configuraci/i]) {
      const tab = page.getByRole('tab', { name });
      await tab.click();

      // Wait for the panel this tab controls to actually be shown. A fixed sleep
      // here passes only on a fast machine and fails only on a slow one, which is
      // the worst possible failure mode: it says nothing about the code. aria-controls
      // is the link that says which panel this tab owns, so waiting on it is both
      // condition-based and independent of how fast the machine is.
      const panelId = await tab.getAttribute('aria-controls');
      expect(panelId, `the "${name}" tab must point at a panel`).toBeTruthy();
      await expect(page.locator(`#${panelId}`)).toBeVisible();
    }

    expect(collector.stop(), 'admin JS must run clean').toEqual([]);
  });

  test('the quality preview action responds and renders a WebP sample', async ({ page }) => {
    await loginAsAdmin(page);

    const status = page.locator('[data-preview="status"]');
    await expect(status).toBeVisible();

    // The first render re-encodes the plugin sample at the stored quality.
    await expect(status).not.toHaveText('', { timeout: 30_000 });
    await expect(status).not.toHaveText(/no se pudo generar/i, { timeout: 30_000 });

    await expect(page.locator('[data-preview="image"]')).toHaveAttribute('src', /\.webp/);

    // Moving the slider triggers a debounced re-preview. The admin script waits 350ms
    // before requesting it (schedulePreview in webp-studio-admin.js), so waiting on a
    // fixed 2500ms was both slower than necessary and unreliable: a loaded CI runner
    // can exceed it. The cache-buster on the src is the observable effect, and
    // toHaveAttribute already retries until it matches or the timeout expires.
    await page.getByRole('slider', { name: LABELS.qualitySlider }).fill('45');
    await expect(page.locator('[data-preview="image"]')).toHaveAttribute('src', /\?t=\d+/, {
      timeout: 15_000,
    });
  });

  test('the status AJAX endpoint answers for an administrator', async ({ page }) => {
    await loginAsAdmin(page);
    const nonce = await readNonce(page);

    const result = await ajaxPost(page, AJAX_ACTIONS.getStatus, {}, nonce);

    expect(result.status).toBe(200);
    expect(result.json?.success).toBe(true);
    expect(result.json?.data).toBeTruthy();
  });
});
