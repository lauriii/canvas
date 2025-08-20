import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';
import { expect } from '@playwright/test';
import * as nodePath from 'node:path';
import { readFileSync } from 'node:fs';

/**
 * This test suite will verify XB AI related features.
 */
test.describe('XB AI Features', () => {
  test.beforeAll(
    'Setup test site with Experience Builder',
    async ({ browser, drupalSite }) => {
      const setupPage = await browser.newPage();
      const drupalInstance = new Drupal({ page: setupPage, drupalSite });
      await drupalInstance.installModules([
        'experience_builder',
        'xb_ai',
        'xb_ai_test',
      ]);
      await setupPage.close();
    },
  );

  test.beforeEach(async ({ drupal }) => {
    await drupal.createXbPage('Homepage', '/homepage');
  });

  async function navigateToEditor(page, xBEditor) {
    await page.goto('/homepage');
    await xBEditor.goToEditor();
  }

  async function openAiPanel(page, xBEditor) {
    await navigateToEditor(page, xBEditor);
    await page.getByRole('button', { name: 'Open AI Panel' }).click();
  }

  async function submitAiQuery(page, query) {
    await page.getByRole('textbox', { name: 'Build me a' }).fill(query);
    return page.getByTestId('xb-ai-panel').getByRole('button').nth(1).click();
  }

  function waitForAiApiCall(page) {
    return [
      page.waitForRequest(
        (request) =>
          request.url().includes('/admin/api/xb/ai') &&
          request.method() === 'POST',
      ),
      page.waitForResponse(
        (response) =>
          response.url().includes('/admin/api/xb/ai') &&
          response.status() === 200,
      ),
    ];
  }

  async function getDeepChatContent(page) {
    return page.evaluate(() => {
      const deepChatElement = document.querySelector('deep-chat');
      const shadowRoot = deepChatElement?.shadowRoot;
      if (!shadowRoot) return null;

      const aiMessageElement = shadowRoot.querySelector('.ai-message-text');
      return aiMessageElement?.textContent || null;
    });
  }

  async function createUserWithRole(drupal, roleName, permissions) {
    await drupal.createRole({ name: roleName });
    await drupal.addPermissions({ role: roleName, permissions });

    const user = {
      email: `${roleName}@example.com`,
      username: roleName,
      password: 'superstrongpassword1337',
      roles: [roleName],
    };

    await drupal.createUser(user);
    return user;
  }

  async function simulateImageUpload(page, imagePath) {
    const imageBuffer = readFileSync(imagePath);
    const imageBase64 = imageBuffer.toString('base64');

    await page.waitForFunction(
      () => {
        const deepChatElement = document.querySelector('deep-chat');
        return deepChatElement?.shadowRoot?.querySelector('#drag-and-drop');
      },
      { timeout: 10000 },
    );

    return page.evaluate((base64Data) => {
      const deepChatElement = document.querySelector('deep-chat');
      const targetElement =
        deepChatElement.shadowRoot.querySelector('#drag-and-drop');

      const byteCharacters = atob(base64Data);
      const byteArray = new Uint8Array(byteCharacters.length);
      for (let i = 0; i < byteCharacters.length; i++) {
        byteArray[i] = byteCharacters.charCodeAt(i);
      }

      const blob = new Blob([byteArray], { type: 'image/jpeg' });
      const file = new File([blob], 'gracie-big.jpg', { type: 'image/jpeg' });
      const dataTransfer = new DataTransfer();
      dataTransfer.items.add(file);

      const events = ['dragenter', 'dragover', 'drop'];
      events.forEach((eventType) => {
        const event = new DragEvent(eventType, {
          dataTransfer,
          bubbles: true,
          cancelable: true,
        });
        targetElement.dispatchEvent(event);
      });

      return true;
    }, imageBase64);
  }

  test('Should verify AI panel API request and response payload', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    await drupal.loginAsAdmin();
    await openAiPanel(page, xBEditor);

    const query = 'What is a CMS?';
    const [aiApiRequest, aiApiResponse] = await Promise.all([
      ...waitForAiApiCall(page),
      submitAiQuery(page, query),
    ]);

    const requestBody = JSON.parse(aiApiRequest.postData());
    expect(requestBody).toHaveProperty('messages');
    expect(requestBody).toHaveProperty('entity_type');
    expect(requestBody).toHaveProperty('entity_id');
    expect(requestBody).toHaveProperty('selected_component');
    expect(requestBody).toHaveProperty('layout');
    expect(requestBody.current_layout.layout).toHaveProperty('content');
    expect(requestBody).toHaveProperty('active_component_uuid');
    expect(requestBody).toHaveProperty('current_layout');
    expect(requestBody).toHaveProperty('derived_proptypes');
    expect(requestBody).toHaveProperty('page_title');
    expect(requestBody).toHaveProperty('page_description');
    expect(requestBody.messages[0].text).toBe(query);
    expect(requestBody.entity_type).toBe('xb_page');
    expect(requestBody.entity_id).toBe('1');
    expect(requestBody.selected_component).toBe('');
    expect(requestBody.layout).toBe('{}');
    expect(requestBody.active_component_uuid).toBe('');
    expect(requestBody.page_title).toBe('Homepage');
    expect(requestBody.page_description).toBe('');
    expect(typeof requestBody.current_layout.layout.content).toBe('object');
    expect(Array.isArray(requestBody.derived_proptypes)).toBe(true);

    const responseData = await aiApiResponse.json();
    expect(responseData).toHaveProperty('status', true);
    expect(responseData).toHaveProperty('message');
    expect(responseData.message).toContain('Content Management System');

    const deepChatContent = await getDeepChatContent(page);
    expect(deepChatContent).toContain(responseData.message);
  });

  test('Should show AI panel only to users with XB AI permissions', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    const basePermissions = ['view the administration theme', 'edit xb_page'];
    const userWithoutAiPermissions = await createUserWithRole(
      drupal,
      'xb_permissions',
      basePermissions,
    );

    await drupal.login(userWithoutAiPermissions);
    await navigateToEditor(page, xBEditor);

    // TODO: This test should fail once https://www.drupal.org/i/3533449 is implemented
    await expect(
      page.getByRole('button', { name: 'Open AI Panel' }),
    ).toBeAttached();

    await drupal.addPermissions({
      role: 'xb_permissions',
      permissions: ['use experience builder ai'],
    });
    await page.reload();
    await expect(
      page.getByRole('button', { name: 'Open AI Panel' }),
    ).toBeAttached();
  });

  test('Should complete create component workflow successfully', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    await drupal.loginAsAdmin();
    await openAiPanel(page, xBEditor);
    await submitAiQuery(page, 'Create component');

    await expect(page).toHaveURL(
      /\/xb\/xb_page\/\d+\/code-editor\/component\/herobanner/,
    );
    await page.getByTestId('xb-publish-review').click();
    await page
      .getByRole('checkbox', { name: 'Select all changes in Components' })
      .click();
    await page
      .getByRole('checkbox', { name: 'Select all changes in Assets' })
      .click();
    await page.getByRole('button', { name: 'Publish 2 selected' }).click();
    await page.getByRole('button', { name: 'Add to components' }).click();
    await page.getByRole('button', { name: 'Add' }).click();
    await expect(page).toHaveURL(/\/xb\/xb_page\/\d+\/editor/);
    await page.getByRole('button', { name: 'Add' }).click();
    await page
      .locator('div')
      .filter({ hasText: /^HeroBanner$/ })
      .nth(4)
      .click();
    await xBEditor.clickPreviewComponent('js.herobanner');
  });

  test('Should verify image upload functionality', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    await drupal.loginAsAdmin();
    await openAiPanel(page, xBEditor);

    const imagePath = nodePath.join(
      __dirname,
      '../../fixtures/images/gracie-big.jpg',
    );
    const uploadSuccess = await simulateImageUpload(page, imagePath);
    expect(uploadSuccess).toBe(true);

    await page.waitForTimeout(2000);
    const hasImage = await page.evaluate(() => {
      const deepChatElement = document.querySelector('deep-chat');
      return !!deepChatElement?.shadowRoot?.querySelector('img');
    });
    expect(hasImage).toBe(true);

    const submitButton = page
      .getByTestId('xb-ai-panel')
      .locator('.input-button.inside-right');
    await expect(submitButton).not.toBeVisible();

    await page
      .getByRole('textbox', { name: 'Build me a' })
      .fill('What is a CMS?');
    await expect(submitButton).toBeVisible();
  });

  test('Should generate title', async ({ page, drupal, xBEditor }) => {
    await drupal.loginAsAdmin();
    await openAiPanel(page, xBEditor);
    await expect(page.getByRole('textbox', { name: 'Title*' })).toHaveValue(
      'Homepage',
    );
    await submitAiQuery(page, 'Generate title');
    await expect(page.getByRole('textbox', { name: 'Title*' })).toHaveValue(
      'Welcome to Our Interactive Experience',
    );
  });

  test('Should generate metadata', async ({ page, drupal, xBEditor }) => {
    await drupal.loginAsAdmin();
    await openAiPanel(page, xBEditor);
    await expect(
      page.getByRole('textbox', { name: 'Meta description' }),
    ).toHaveValue('');
    await submitAiQuery(page, 'Generate metadata');
    await expect(
      page.getByRole('textbox', { name: 'Meta description' }),
    ).toHaveValue(
      'Experience a journey through our interactive digital space, designed to engage and inspire visitors with immersive content and seamless navigation.',
    );
  });

  test('Should verify create and edit component workflow', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    await drupal.loginAsAdmin();
    await openAiPanel(page, xBEditor);
    await submitAiQuery(page, 'Create second component');

    await expect(page).toHaveURL(
      /\/xb\/xb_page\/\d+\/code-editor\/component\/herobannersecond/,
    );
    const preview = xBEditor.getCodePreviewFrame();
    const redElements = preview.locator('.bg-red-600');
    const blueElements = preview.locator('.bg-blue-600');
    await expect(redElements).toHaveCount(1);
    await expect(blueElements).toHaveCount(0);

    await submitAiQuery(page, 'Edit component');
    const updatedPreview = xBEditor.getCodePreviewFrame();
    const redElementsUpdated = updatedPreview.locator('.bg-red-600');
    const blueElementsUpdated = updatedPreview.locator('.bg-blue-600');
    await expect(redElementsUpdated).toHaveCount(0);
    await expect(blueElementsUpdated).toHaveCount(1);
  });
});
