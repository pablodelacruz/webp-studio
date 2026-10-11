import { baseUrl, provisionTestUsers, siteReachable } from './helpers';

/**
 * Prepares the WordPress instance the suite runs against.
 *
 * Two jobs:
 *  1. Report up front whether the site is reachable, so an unprovisioned environment
 *     produces a clear message instead of a wall of connection timeouts.
 *  2. Create the subscriber and editor accounts the security spec needs. When wp-cli is
 *     not available (CI provisions its own users) this is a warning, not a failure.
 */
export default async function globalSetup(): Promise<void> {
  console.log(`[e2e] target: ${baseUrl()}`);

  if (!(await siteReachable())) {
    console.warn(
      `[e2e] ${baseUrl()} is NOT reachable. Every spec will skip with an explanatory ` +
        'message. This is an environment gap, not a product failure.',
    );
    return;
  }

  const users = provisionTestUsers();
  console.log(
    `[e2e] provisioned accounts — admin: ${users.admin}, subscriber: ${users.subscriber}, editor: ${users.editor}`,
  );
}
