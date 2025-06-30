import { expect } from '@playwright/test';
import { exec, execDrush } from '../utilities/DrupalExec';
import * as nodePath from 'node:path';
import * as fs from 'node:fs';
import { getModuleDir, getRootDir } from '../utilities/DrupalFilesystem';

export class Drupal {
  readonly page: Page;
  readonly drupalSite: DrupalSite;

  constructor({ page, drupalSite }: { page: Page; drupalSite: DrupalSite }) {
    this.page = page;
    this.drupalSite = drupalSite;
  }

  async setTestCookie() {
    const context = await this.page.context();
    const simpletestCookie = {
      name: 'SIMPLETEST_USER_AGENT',
      value: encodeURIComponent(this.drupalSite.userAgent),
      url: this.drupalSite.url,
    };
    await context.addCookies([simpletestCookie]);
  }

  hasDrush() {
    return this.drupalSite.hasDrush;
  }

  disableDrush() {
    this.drupalSite.hasDrush = true;
  }

  enableDrush() {
    this.drupalSite.hasDrush = false;
  }

  setDrush(enabled: boolean) {
    this.drupalSite.hasDrush = enabled;
  }

  async drush(command: string) {
    return await execDrush(command, this.drupalSite);
  }

  async setupXBTestSite() {
    const moduleDir = await getModuleDir();
    await this.enableTestExtensions();
    await this.writeBaseUrl();
    await this.applyRecipe(
      `${moduleDir}/experience_builder/tests/fixtures/recipes/base`,
    );
    await this.applyRecipe(
      `${moduleDir}/experience_builder/tests/fixtures/recipes/test_site`,
    );
  }

  async loginAsAdmin() {
    const stdout = await exec(
      `php core/scripts/test-site.php user-login 1 --site-path ${this.drupalSite.sitePath}`,
    );
    await this.page.goto(`${this.drupalSite.url}${stdout.toString()}`);
    await expect(this.page.locator('h1')).toHaveText('admin');
  }

  async login(
    { username, password }: { username: string; password?: string } = {
      username: this.drupalSite.username,
      password: this.drupalSite.password,
    },
  ) {
    if (!this.drupalSite.hasDrush && !password) {
      throw new Error('Password is required when drush is not available.');
    }
    const page = this.page;
    if (this.drupalSite.hasDrush) {
      const loginUrl = await this.drush(
        `user:login --name=${username} --no-browser`,
      );
      await page.goto(loginUrl);
    } else {
      await page.goto(`${this.drupalSite.url}/user/login`);
      await page.getByTestId('edit-name').fill(username);
      await page.getByTestId('edit-pass').fill(password);
      await page.getByTestId('edit-submit').click();
    }
    await expect(page.locator('h1')).toHaveText(username);
  }

  async logout() {
    const page = this.page;
    await page.goto(`${this.drupalSite.url}/user/logout/confirm`);
    await page.getByTestId('edit-submit').click();
    let cookies = await page.context().cookies();
    cookies = cookies.filter(
      (cookie) =>
        cookie.name.startsWith('SESS') || cookie.name.startsWith('SSESS'),
    );
    await expect(cookies).toHaveLength(0);
  }

  async createRole({ name }: { name: string }) {
    if (this.drupalSite.hasDrush) {
      await this.drush(`role:create ${name}`);
    } else {
      const page = this.page;
      await page.goto(`${this.drupalSite.url}/admin/people/roles/add`);
      await page.getByTestId('edit-label').fill(name);
      await page.getByTestId('edit-submit').click();
      await expect(page.locator('//*[@data-drupal-messages]')).toContainText(
        'has been added.',
      );
      await expect(page.locator('//*[@data-drupal-messages]')).toContainText(
        name,
      );
    }
  }

  async addPermissions({
    role,
    permissions,
  }: {
    role: string;
    permissions: string[];
  }) {
    if (this.drupalSite.hasDrush) {
      await this.drush(`role:perm:add ${role} '${permissions.join(',')}'`);
    } else {
      const page = this.page;
      await page.goto(`${this.drupalSite.url}/admin/people/permissions`);
      for (const permission of permissions) {
        await page
          .getByTestId(
            `edit-${this.normalizeAttribute(role)}-${this.normalizeAttribute(permission)}`,
          )
          .check();
      }
      await page.getByTestId('edit-submit').click();
      await expect(page.locator('//*[@data-drupal-messages]')).toContainText(
        'The changes have been saved',
      );
    }
  }

  async createUser({
    username,
    password,
    email,
    roles,
  }: {
    username: string;
    password: string;
    email: string;
    roles: string[];
  }): Promise<number> {
    if (this.drupalSite.hasDrush) {
      await this.drush(
        `user:create ${username} --password=${password} --mail=${email}`,
      );
      for (const role of roles) {
        await this.drush(`user:role:add ${role} ${username}`);
      }
    } else {
      const page = this.page;
      await page.goto(`${this.drupalSite.url}/admin/people/create`);
      await page.getByTestId('edit-mail').fill(email);
      await page.getByTestId('edit-name').fill(username);
      await page.getByTestId('edit-pass-pass1').fill(password);
      await page.getByTestId('edit-pass-pass2').fill(password);
      for (const role of roles) {
        await page
          .getByTestId(`edit-roles-${this.normalizeAttribute(role)}`)
          .check();
      }
      await page.getByTestId('edit-submit').click();
      await expect(page.locator('//*[@data-drupal-messages]')).toContainText(
        'Created a new user account for',
      );
      await expect(page.locator('//*[@data-drupal-messages]')).toContainText(
        username,
      );
      const href = await page
        .locator('//*[@data-drupal-messages]//a')
        .getAttribute('href');
      const match = href?.match(/\/user\/(\d+)/);
      const userId = parseInt(match[1]);
      if (isNaN(userId)) {
        throw new Error(`No user ID found for ${username}`);
      }
      return userId;
    }
  }

  async installModules(modules: string[]) {
    if (this.drupalSite.hasDrush) {
      await this.drush(`pm:enable ${modules.join(' ')}`);
    } else {
      const page = this.page;
      await page.goto(`${this.drupalSite.url}/admin/modules`);
      for (const module of modules) {
        await page
          .getByTestId(`edit-modules-${this.normalizeAttribute(module)}-enable`)
          .check();
      }
      await page.getByTestId('edit-submit').click();
      for (const module of modules) {
        const checkbox = await page.getByTestId(
          `edit-modules-${this.normalizeAttribute(module)}-enable`,
        );
        await expect(checkbox).toBeTruthy();
        await expect(checkbox).toBeDisabled();
      }
      await expect(page.locator('//*[@data-drupal-messages]')).toContainText(
        `been installed`,
      );
    }
  }

  async enableTestExtensions() {
    const settingsFile = nodePath.resolve(
      getRootDir(),
      `${this.drupalSite.sitePath}/settings.php`,
    );
    fs.chmodSync(settingsFile, 0o775);
    return await exec(
      `echo '$settings["extension_discovery_scan_tests"] = TRUE;' >> ${settingsFile}`,
    );
  }

  async writeBaseUrl() {
    // \Drupal\Core\StreamWrapper\PublicStream::baseUrl needs a base-url set,
    // otherwise it will default to $GLOBALS['base_url']. When a recipe is being
    // run via core/scripts/drupal, that defaults to core/scripts/drupal 😭.
    const settingsFile = nodePath.resolve(
      getRootDir(),
      `${this.drupalSite.sitePath}/settings.php`,
    );
    fs.chmodSync(settingsFile, 0o775);
    return await exec(
      `echo '$settings["file_public_base_url"] = "${this.drupalSite.url}/${this.drupalSite.sitePath}/files";' >> ${settingsFile}`,
    );
  }

  async applyRecipe(path: string) {
    return await exec(
      `DRUPAL_DEV_SITE_PATH=${this.drupalSite.sitePath} php core/scripts/drupal recipe ${path}`,
    );
  }

  async getSettings() {
    const value = await this.page.evaluate(() => {
      return window.drupalSettings;
    });
    return value;
  }

  async getXBEditorPath() {
    const bodyClass = await this.page.locator('body').getAttribute('class');
    const hasXBPageClass = bodyClass?.includes('xb-page');
    const drupalSettings = await this.getSettings();
    if (hasXBPageClass) {
      return `${drupalSettings.path.baseUrl}xb/xb_${drupalSettings.path.currentPath}`;
    } else {
      return `${drupalSettings.path.baseUrl}xb/${drupalSettings.path.currentPath}/editor`;
    }
  }

  async waitForXBEditor() {
    await expect(
      this.page.locator('xpath=//*[@data-testid="xb-contextual-panel"]'),
    ).toContainText('Title', {
      timeout: 15_000,
    });
    await expect(
      this.page.locator('xpath=//*[@data-testid="xb-primary-panel"]'),
    ).toContainText('Content', {
      timeout: 15_000,
    });
    await expect(this.page.locator('css=.xb--viewport-overlay')).toBeVisible({
      timeout: 15_000,
    });
  }

  async goToXBEditor() {
    const path = await this.getXBEditorPath();
    await this.page.goto(path);
    await this.waitForXBEditor();
  }

  normalizeAttribute(attribute: string) {
    return attribute.replaceAll(' ', '-').replaceAll('_', '-');
  }
}
