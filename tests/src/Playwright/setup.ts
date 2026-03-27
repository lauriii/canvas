import fs from 'fs';
import path from 'path';
import { type Drupal } from '@drupal/playwright';

export async function setupSite({ drupal }: { drupal: Drupal }) {
  const page = drupal.page;

  try {
    await drupal.setTestCookie();
    await drupal.loginAsAdmin();
    await drupal.setPreprocessing({ css: true, javascript: true });
    await drupal.enableTestExtensions();
    await drupal.installModules(['canvas']);
    await drupal.createRole({ name: 'editor' });
    await drupal.addPermissions({
      role: 'editor',
      permissions: [
        'administer code components',
        'administer folders',
        'administer patterns',
        'administer page template',
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
