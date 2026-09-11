import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Coverage for the Canvas AI dev chat, which runs a turn as several requests.
 *
 * The agent pauses after each tool decision, and `AiWizardDev` re-POSTs the
 * same turn to `/admin/api/canvas/ai-dev` until a response reports
 * `should_continue: false`.
 *
 * `canvas_dev_ai` is what puts `drupalSettings.canvas.aiDevMode` in place, so
 * this file installs it to render `AiWizardDev` instead of `AiWizard`. That is
 * also why this coverage does not live in `ai.spec.ts`: installing the module
 * would switch that file's tests over to the dev wizard too.
 *
 * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder
 * @see \Drupal\canvas_ai_test\EventSubscriber\CanvasAiRequestInterceptor
 */

test.use({
  // canvas_test_sdc provides the components the page builder agent's
  // placement fixtures reference by id.
  modules: ['canvas_ai_test', 'canvas_test_sdc'],
  enableTestExtensions: true,
});

test.describe('AI dev chat', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    // canvas_dev_ai is installed on its own, after canvas_ai, rather than
    // alongside it in `test.use()` above. Its default canvas_dev_ai.settings
    // names the ai_agent config entities canvas_ai provides, and the
    // ConfigExists constraints on those names are validated when the settings
    // are saved. Core installs every module's simple config before any
    // module's config entities, so installing both modules in one operation
    // saves the settings while the agents do not exist yet — which the config
    // schema checker this test site runs with turns into a fatal error.
    await drupal.installModules(['canvas_dev_ai']);
    await drupal.createRole({ name: 'ai_editor' });
    await drupal.createUser({
      email: `ai_editor@example.com`,
      username: 'ai_editor',
      password: 'ai_editor',
      roles: ['ai_editor'],
    });
    await drupal.addPermissions({
      role: 'ai_editor',
      permissions: [
        'create canvas_page',
        'edit canvas_page',
        'publish auto-saves',
        'administer code components',
        'use drupal canvas ai',
        'create media',
      ],
    });
    await drupal.logout();
  });

  test('Component agent turns', async ({ page, drupal, canvas, ai }) => {
    // Intercept the dev chat's calls. Counting the requests here is what holds
    // each turn to the number it should have sent to the backend.
    let requests = 0;
    await page.route('**/admin/api/canvas/ai-dev', async (route) => {
      requests += 1;
      // Hold every request briefly. Playwright waits for a state to arrive and
      // cannot catch one that has already flipped, so without a pause the turn
      // can finish before the running state is ever asserted on.
      await new Promise((resolve) => setTimeout(resolve, 500));
      await route.continue();
    });

    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas();
    await ai.openPanel();

    const chat = page.getByTestId('canvas-ai-panel').locator('deep-chat');
    // deep-chat puts a role class on every chat message (`user-message-text` or
    // `ai-message-text`) plus one for the content kind. Both the progress
    // message and the answer are the agent's, so the content kind is what tells
    // them apart: the progress message is added as HTML — so the backend leaves
    // it out of the chat history it sends to the model — and carries
    // `.html-message`, where the answer carries `.text-message`.
    // @see \Drupal\canvas_ai\CanvasAiChatHelper::getFilteredChatHistory()
    // This runs as one long chat: the user has a component created, then edits
    // it. Each turn adds one chat message of each kind, so the one to assert on
    // is the last.
    const userMessage = chat.locator('.user-message-text');
    const progressMessage = chat.locator('.html-message');
    const answer = chat.locator('.text-message.ai-message-text');
    const preview = canvas.getCodePreviewFrame();

    // The user asks for a component. On the first request the agent says it is
    // creating one and calls its tool, simulated here by the first fixture.
    // That fixture reports `should_continue: true`, so the chat sends a second
    // request under the same request_id. The interceptor counts the requests
    // made under each request_id and answers this one from the second fixture,
    // which carries the created component's JavaScript.
    // @see \Drupal\canvas_ai_test\EventSubscriber\CanvasAiRequestInterceptor::countHop()
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/create_a_red_button.json
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/create_a_red_button-2.json
    await ai.submitQuery('Create a red button');

    // The user's message is rendered.
    await expect(userMessage.last()).toHaveText('Create a red button');

    // The first request's narration is rendered, with a spinning loader under
    // it.
    await expect(progressMessage.last()).toContainText(
      'Creating the red button component.',
    );
    await expect(progressMessage.last().locator('.aiLoader')).toBeVisible();

    // The final request's answer is rendered and the loader has given way to
    // the finished icon.
    await expect(answer.last()).toHaveText(
      'The red button component is ready.',
    );
    await expect(
      progressMessage.last().locator('.aiCompletedIcon'),
    ).toBeVisible();
    await expect(progressMessage.last().locator('.aiLoader')).toBeHidden();
    expect(requests).toBe(2);

    // That final request carried a `component_structure`, so its side effect
    // ran: the component was created and the code editor opened on it.
    // @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::buildSolvableResponse()
    await expect(page).toHaveURL(
      /\/canvas\/code-editor\/component\/red_button/,
    );
    await expect(
      page.locator(
        '[data-testid="canvas-code-editor-main-panel"] div[role="textbox"]',
      ),
    ).toContainText('export default function RedButton');
    // The source compiled and rendered, using the prop's example value.
    await expect(preview.locator('button')).toHaveText('Click here');
    await expect(preview.locator('.bg-red-600')).toHaveCount(1);

    // The user then asks for a change. This turn takes three requests: the
    // agent loads the component's code, updates it, and reports the result. The
    // first two fixtures continue the turn and the third ends it, and each
    // carries the narration accumulated so far.
    // @see \Drupal\canvas_ai_test\EventSubscriber\CanvasAiRequestInterceptor::countHop()
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/make_it_blue_and_add_an_icon_slot.json
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/make_it_blue_and_add_an_icon_slot-2.json
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/make_it_blue_and_add_an_icon_slot-3.json
    await ai.submitQuery('Make it blue and add an icon slot');
    await expect(progressMessage.last()).toContainText(
      'Loading the component code.',
    );
    await expect(progressMessage.last().locator('.aiLoader')).toBeVisible();
    await expect(progressMessage.last()).toContainText(
      'Updating the button color and adding the icon slot.',
    );
    await expect(progressMessage.last().locator('.aiLoader')).toBeVisible();

    await expect(answer.last()).toHaveText(
      'The button is blue and has an icon slot.',
    );
    await expect(
      progressMessage.last().locator('.aiCompletedIcon'),
    ).toBeVisible();

    // Three more requests.
    expect(requests).toBe(5);

    // The final request's `js_structure` rewrote the code of the component
    // already open in the editor instead of creating another one.
    await expect(page).toHaveURL(
      /\/canvas\/code-editor\/component\/red_button/,
    );
    await expect(preview.locator('.bg-blue-600')).toHaveCount(1);
    await expect(preview.locator('.bg-red-600')).toHaveCount(0);

    // Its `props_metadata` and `slots_metadata` reached the component data
    // panel, which lists each by title.
    await page.getByRole('tab', { name: 'Props' }).click();
    await expect(page.getByLabel('Prop name')).toHaveValue('Button Text');
    await page.getByRole('tab', { name: 'Slots' }).click();
    await expect(page.getByLabel('Slot name')).toHaveValue('Icon');
  });

  test('Page builder agent turns', async ({ page, drupal, canvas, ai }) => {
    // Count the requests to hold each turn to the number of hops it should
    // have sent to the backend. Request 3, the second hop of the "Approved"
    // turn below, is held until the test releases it: Playwright waits for a
    // state to arrive and cannot catch one that has already flipped, so
    // without the hold the turn could end before its in-progress state is
    // asserted on.
    let requests = 0;
    let releaseSecondPlacementHop!: () => void;
    const secondPlacementHop = new Promise<void>((resolve) => {
      releaseSecondPlacementHop = resolve;
    });
    await page.route('**/admin/api/canvas/ai-dev', async (route) => {
      requests += 1;
      if (requests === 3) {
        await secondPlacementHop;
      }
      await route.continue();
    });

    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas();
    await ai.openPanel();

    const chat = page.getByTestId('canvas-ai-panel').locator('deep-chat');
    const progressMessage = chat.locator('.html-message');
    const answer = chat.locator('.text-message.ai-message-text');
    // The Tools menu trigger is a deep-chat custom button inside its shadow
    // DOM, which CSS locators pierce.
    const toolsTrigger = chat.locator('.custom-button');
    const menu = page.getByTestId('canvas-ai-tool-selector');
    const builderRow = menu.getByRole('button', {
      name: /Dev Page Builder Agent/,
    });
    const pill = page.getByTestId('canvas-ai-active-tool');
    const removeButton = pill.getByRole('button', {
      name: 'Remove the selected tool',
    });

    // The user selects the page builder agent as the Tool. Selecting it closes
    // the menu and shows it in the pill.
    await toolsTrigger.click();
    await builderRow.click();
    await expect(menu).toBeHidden();
    await expect(pill).toContainText('Drupal Canvas Dev Page Builder Agent');
    await expect(removeButton).toBeEnabled();
    await expect(toolsTrigger).toBeEnabled();

    // The user asks for a whole page. The fixture answers with the plan as the
    // turn's message and no tool call, so the turn ends after one request: the
    // plan renders as the answer, there is no progress message, and nothing is
    // placed.
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/build_me_a_landing_page.json
    await ai.submitQuery('Build me a landing page');
    await expect(answer.last()).toHaveText(
      'Here is the plan: 1) Hero with the main call to action. 2) Heading on why teams choose Canvas, with a call to action. 3) Closing heading with a call to action. Reply Approved and I will build it.',
    );
    await expect(progressMessage).toHaveCount(0);
    expect(requests).toBe(1);
    await canvas.testInPreviewFrame(
      '[data-component-id^="canvas_test_sdc:"]',
      async (components) => {
        await expect(components).toHaveCount(0);
      },
    );

    // The user approves. The turn hops three times: the first hop narrates,
    // the second carries the first `operations` batch (hero, heading, CTA) and
    // the third the batch below it (heading, CTA) plus the answer. Each batch
    // is applied with the exact uuid, nodePath and fieldValues the fixture
    // specifies.
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/approved.json
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/approved-2.json
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/approved-3.json
    await ai.submitQuery('Approved');
    await expect(progressMessage.last()).toContainText(
      'Placing the hero section.',
    );

    // The Tool is fixed for the turn. While it runs, the pill is locked and
    // cannot be removed, and the Tools trigger is disabled: deep-chat marks it
    // with its disabled class and aria-disabled. deep-chat still fires the
    // trigger's click handler in that state, so a click event is dispatched
    // past Playwright's actionability check to show the handler ignores it.
    await expect(pill).toHaveClass(/locked/);
    await expect(removeButton).toBeDisabled();
    await expect(toolsTrigger).toHaveClass(/custom-button-container-disabled/);
    await expect(toolsTrigger).toBeDisabled();
    await toolsTrigger.dispatchEvent('click');
    await expect(menu).toBeHidden();

    releaseSecondPlacementHop();
    await expect(answer.last()).toHaveText(
      'The landing page sections are in place.',
    );
    await expect(progressMessage.last()).toContainText(
      'Placing the closing section.',
    );
    await expect(
      progressMessage.last().locator('.aiCompletedIcon'),
    ).toBeVisible();
    expect(requests).toBe(4);

    // The turn has ended: the pill and the Tools trigger are usable again. The
    // menu opens, and the Tool can be cleared.
    await expect(pill).not.toHaveClass(/locked/);
    await expect(removeButton).toBeEnabled();
    await expect(toolsTrigger).not.toHaveClass(
      /custom-button-container-disabled/,
    );
    await expect(toolsTrigger).toBeEnabled();
    await toolsTrigger.click();
    await expect(menu).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await removeButton.click();
    await expect(pill).toBeHidden();

    // Both batches landed on the canvas in the fixtures' order.
    await canvas.testInPreviewFrame(
      '[data-component-id^="canvas_test_sdc:"]',
      async (components) => {
        await expect(components).toHaveCount(5);
        await expect(components.nth(0)).toHaveAttribute(
          'data-component-id',
          'canvas_test_sdc:my-hero',
        );
        await expect(components.nth(0)).toContainText(
          'Build Faster with Canvas',
        );
        await expect(components.nth(1)).toHaveText('Why teams choose Canvas');
        await expect(components.nth(2)).toHaveText('Start a free trial');
        await expect(components.nth(3)).toHaveText(
          'Ready to launch your page?',
        );
        await expect(components.nth(4)).toHaveText('Contact the Canvas team');
      },
    );

    // The same order shows in the layers panel, by component name.
    await canvas.openLayersPanel();
    await expect(
      page.getByTestId('canvas-primary-panel').getByRole('treeitem'),
    ).toHaveText([
      /Hero/,
      /Heading/,
      /Call to Absolute Action/,
      /Heading/,
      /Call to Absolute Action/,
    ]);

    // The user asks for an edit. Hop 1 narrates and hop 2 returns
    // `component_updates` keyed by the placed headings' UUIDs; the new text
    // renders in the preview.
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/update_the_headings.json
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/update_the_headings-2.json
    await ai.submitQuery('Update the headings');
    await expect(answer.last()).toHaveText('Both headings are updated.');
    expect(requests).toBe(6);
    await canvas.testInPreviewFrame(
      '[data-component-id="canvas_test_sdc:heading"]',
      async (headings) => {
        await expect(headings).toHaveCount(2);
        await expect(headings.nth(0)).toHaveText('Canvas in five minutes');
        await expect(headings.nth(1)).toHaveText('Launch day is today');
      },
    );

    // The user asks for the page metadata. Hop 2 returns `canvas_page_data`
    // with the title and meta description, which reach the page-data form.
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/set_the_page_title_and_description.json
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/set_the_page_title_and_description-2.json
    await ai.submitQuery('Set the page title and description');
    await expect(answer.last()).toHaveText(
      'The title and description are set.',
    );
    expect(requests).toBe(8);
    await expect(page.getByRole('textbox', { name: 'Title*' })).toHaveValue(
      'Canvas Campus',
    );
    await expect(
      page.getByRole('textbox', { name: 'Meta description' }),
    ).toHaveValue('Visit the Canvas campus and see the builder in action.');
  });
});
