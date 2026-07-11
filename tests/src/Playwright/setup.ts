import fs from 'fs';
import { fileURLToPath } from 'node:url';
import path from 'path';
import { exec, getRootDir } from '@drupal-canvas/test-utils';
import { type Drupal } from '@drupal/playwright';

/**
 * Config objects Canvas excludes from strict config schema validation in tests.
 *
 * Mirrors
 * \Drupal\Tests\canvas\TestSite\CanvasTestSetup::$configSchemaCheckerExclusions.
 */
const configSchemaCheckerExclusions = [
  // Canvas forward-ports a fix for `type: field.value.boolean` (making the
  // schema strict). Core's `article_content_type` recipe still ships an integer
  // `default_value` for the boolean `promote` base field, so applying that
  // recipe fails strict validation until the core fix lands.
  // @see https://www.drupal.org/project/drupal/issues/3534717
  // @see \Drupal\canvas\Hook\ComponentSourceHooks::configSchemaInfoAlter()
  'core.base_field_override.node.article.promote',
];

const excludeConfigScript = fileURLToPath(
  new URL('./scripts/exclude-config-schema-checking.php', import.meta.url),
);

/**
 * Adds Canvas's config schema checker exclusions to a test site's services.yml.
 *
 * The Playwright test site is installed without a `TestSetupInterface`, so —
 * unlike CanvasTestSetup — nothing registers Canvas's exclusions. Do it here,
 * before any recipe is applied, so that a recipe importing known-invalid core
 * config (e.g. `core/recipes/article_content_type`) does not fail strict schema
 * validation. The recipe's own module installation rebuilds the container,
 * picking up the edited services.yml before the offending config is imported.
 *
 * @see excludeConfigScript
 */
async function excludeConfigFromSchemaChecking(
  sitePath: string,
): Promise<void> {
  const servicesYml = path.join(getRootDir(), sitePath, 'services.yml');
  await exec(
    `php "${excludeConfigScript}" "${servicesYml}" ${configSchemaCheckerExclusions.join(' ')}`,
  );
}

export async function setupSite({
  drupal,
  modules = [],
  enableTestExtensions = false,
}: {
  drupal: Drupal;
  modules?: string[];
  enableTestExtensions?: boolean;
}) {
  const page = drupal.page;

  try {
    await excludeConfigFromSchemaChecking(drupal.drupalSite.sitePath);
    await drupal.setTestCookie();
    await drupal.loginAsAdmin();
    await drupal.setPreprocessing({ css: true, javascript: true });
    if (enableTestExtensions) {
      await drupal.enableTestExtensions();
    }
    await drupal.installModules(['canvas', ...modules]);
    await drupal.createRole({ name: 'editor' });
    await drupal.addPermissions({
      role: 'editor',
      permissions: [
        'administer code components',
        'administer folders',
        'administer patterns',
        'administer page template',
        'administer page variants',
        'create canvas_page',
        'create media',
        'edit canvas_page',
        'publish auto-saves',
        'administer content templates',
        'create url aliases',
      ],
    });
    await drupal.createUser({
      email: `editor@example.com`,
      username: 'editor',
      password: 'editor',
      roles: ['editor'],
    });
    await drupal.logout();
  } catch (error) {
    // Ensure test-results directory exists
    const screenshotDir = path.join(process.cwd(), 'test-results');
    if (!fs.existsSync(screenshotDir)) {
      fs.mkdirSync(screenshotDir, { recursive: true });
    }

    // Take screenshot with timestamp
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const screenshotPath = path.join(
      screenshotDir,
      `playwright-failure-${timestamp}.png`,
    );

    await page.screenshot({ path: screenshotPath, fullPage: true });

    console.log(`Screenshot saved to: ${screenshotPath}`);

    // Re-throw the error so the test still fails
    throw error;
  }
}
