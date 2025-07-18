import { expect, Page } from '@playwright/test';
import { exec, execDrush } from '../utilities/DrupalExec';
import * as nodePath from 'node:path';
import * as fs from 'node:fs';
import { getModuleDir, getRootDir } from '../utilities/DrupalFilesystem';
import type { DrupalSite } from '../fixtures/DrupalSite';

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
    const playwrightCookie = {
      name: 'XB_PLAYWRIGHT',
      value: 'true',
      url: this.drupalSite.url,
    };
    await context.addCookies([simpletestCookie, playwrightCookie]);
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

  async setupMinimalXBTestSite() {
    await this.installModules(['experience_builder', 'xb_test_sdc']);
    await this.drush(
      "php-eval \"Drupal\\experience_builder\\Entity\\Page::create(['title' => 'Homepage', 'type' => 'xb_page', 'path' => ['alias' => '/homepage', 'langcode' => 'en']])->save();\"",
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
      await page.locator('[data-drupal-selector="edit-name"]').fill(username);
      await page.locator('[data-drupal-selector="edit-pass"]').fill(password);
      await page.locator('[data-drupal-selector="edit-submit"]').click();
    }
    await expect(page.locator('h1')).toHaveText(username);
  }

  async logout() {
    const page = this.page;
    await page.goto(`${this.drupalSite.url}/user/logout/confirm`);
    await page.locator('[data-drupal-selector="edit-submit"]').click();
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
      await page.locator('[data-drupal-selector="edit-label"]').fill(name);
      await page.locator('[data-drupal-selector="edit-submit"]').click();
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
          .locator(
            `[data-drupal-selector="edit-${this.normalizeAttribute(
              role,
            )}-${this.normalizeAttribute(permission)}"]`,
          )
          .check();
      }
      await page.locator('[data-drupal-selector="edit-submit"]').click();
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
      await page.locator('[data-drupal-selector="edit-mail"]').fill(email);
      await page.locator('[data-drupal-selector="edit-name"]').fill(username);
      await page
        .locator('[data-drupal-selector="edit-pass-pass1"]')
        .fill(password);
      await page
        .locator('[data-drupal-selector="edit-pass-pass2"]')
        .fill(password);
      for (const role of roles) {
        await page
          .locator(
            `[data-drupal-selector="edit-roles-${this.normalizeAttribute(
              role,
            )}"]`,
          )
          .check();
      }
      await page.locator('[data-drupal-selector="edit-submit"]').click();
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
          .locator(
            `[data-drupal-selector="edit-modules-${this.normalizeAttribute(
              module,
            )}-enable"]`,
          )
          .check();
      }
      await page.locator('[data-drupal-selector="edit-submit"]').click();
      for (const module of modules) {
        const checkbox = await page.locator(
          `[data-drupal-selector="edit-modules-${this.normalizeAttribute(
            module,
          )}-enable"]`,
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

  async addMedia(path: string, alt: string) {
    await this.page
      .locator('[data-testid="xb-contextual-panel"] input[value="Add media"]')
      .first() // @todo shouldn't need this but XB is currently rendering two fields
      .click();
    await this.page
      .locator(
        'form[data-drupal-selector^="media-library-add-form-upload"] input[name="files[upload]"]',
      )
      .setInputFiles(nodePath.join(__dirname, path));

    // It should be possible to set the alt text with the following, but there's currently a bug
    // await this.page.getByLabel('Alternative text').fill('A cute dog');
    // instead we use the evaluate method to set the value directly.
    await this.page
      .locator('input[name="media[0][fields][field_media_image][0][alt]"]')
      .evaluate((el: HTMLInputElement, value) => {
        el.value = value;
      }, alt);

    await this.page.getByRole('button', { name: 'Save', exact: true }).click();
    // @todo select the item we just uploaded rather than the first.
    await this.page
      .locator(
        '.media-library-widget-modal input[data-drupal-selector^="edit-media-library-select-form"]',
      )
      .first()
      .setChecked(true, { force: true });
    await this.page
      .getByRole('button', { name: 'Insert selected', exact: true })
      .click();
    await expect(
      this.page.locator(
        '[data-testid="xb-contextual-panel"] .js-media-library-item-preview img',
      ),
    ).toHaveAttribute('alt', alt);
  }

  async getSettings() {
    const value = await this.page.evaluate(() => {
      return window.drupalSettings;
    });
    return value;
  }

  normalizeAttribute(attribute: string) {
    return attribute.replaceAll(' ', '-').replaceAll('_', '-');
  }
}
