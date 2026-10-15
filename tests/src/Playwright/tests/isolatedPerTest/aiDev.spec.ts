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
    // each turn to the number it should have sent to the backend, the
    // conversation_id each carries is what ties the turns into one chat, and
    // the last_successful_request_id names the turn each resumes from.
    let requests = 0;
    const conversationIds = new Set<unknown>();
    const bodies: Record<string, unknown>[] = [];
    await page.route('**/admin/api/canvas/ai-dev', async (route) => {
      requests += 1;
      const body = route.request().postDataJSON();
      bodies.push(body);
      conversationIds.add(body.conversation_id);
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

    // Three more requests, all of one conversation: the backend keeps the
    // agent's history under that id between the two turns.
    expect(requests).toBe(5);
    expect(conversationIds.size).toBe(1);
    expect([...conversationIds][0]).toMatch(/^conv_/);
    // The first turn had nothing to resume. The chat accepted it, so every
    // hop of the second turn names it as the checkpoint to resume from.
    // @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::prepareAgent()
    expect(bodies[0].request_id).toMatch(/^req_/);
    expect(bodies.map((body) => body.last_successful_request_id)).toEqual([
      '',
      '',
      ...Array(3).fill(bodies[0].request_id),
    ]);

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
    // DOM, which CSS locators pierce. The stop button is a custom button too,
    // so each is addressed by the class deep-chat derives from its position.
    const toolsTrigger = chat.locator('.custom-button.inside-start');
    const menu = page.getByTestId('canvas-ai-tool-selector');
    const builderRow = menu.getByRole('button', {
      name: /Drupal Canvas Page Agent/,
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
    await expect(pill).toContainText('Drupal Canvas Page Agent');
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

  test('Stopping a running turn', async ({ page, drupal, canvas, ai }) => {
    // Hold the second hop of the "Approved" turn so the turn is still running
    // when stop is pressed, count the hops to show none follows it, and keep
    // each body to show which turn the hops after the stop resume from.
    let requests = 0;
    const bodies: Record<string, unknown>[] = [];
    let releaseHeldHop!: () => void;
    const heldHop = new Promise<void>((resolve) => {
      releaseHeldHop = resolve;
    });
    await page.route('**/admin/api/canvas/ai-dev', async (route) => {
      requests += 1;
      bodies.push(route.request().postDataJSON());
      if (requests === 3) {
        await heldHop;
      }
      // Stop aborts the request this route is holding, and a route whose
      // request is gone can no longer be continued.
      await route.continue().catch(() => {});
    });
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas();
    await ai.openPanel();

    const chat = page.getByTestId('canvas-ai-panel').locator('deep-chat');
    const progressMessage = chat.locator('.html-message');
    const answer = chat.locator('.text-message.ai-message-text');
    // Both controls are deep-chat custom buttons, told apart by the class
    // deep-chat derives from the position each was given.
    const toolsTrigger = chat.locator('.custom-button.inside-start');
    const stopButton = chat.locator('.custom-button.inside-end');
    const menu = page.getByTestId('canvas-ai-tool-selector');
    const builderRow = menu.getByRole('button', {
      name: /Drupal Canvas Page Agent/,
    });

    // The chat is idle, so there is nothing to stop and the button is hidden.
    await expect(stopButton).toBeHidden();

    await toolsTrigger.click();
    await builderRow.click();
    await expect(menu).toBeHidden();

    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/build_me_a_landing_page.json
    await ai.submitQuery('Build me a landing page');
    await expect(answer.last()).toContainText('Here is the plan:');
    await expect(stopButton).toBeHidden();
    expect(requests).toBe(1);

    // The user approves, and stops the turn while its second hop is in flight.
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/approved.json
    await ai.submitQuery('Approved');
    await expect(progressMessage.last()).toContainText(
      'Placing the hero section.',
    );
    await expect(stopButton).toBeVisible();
    await expect(toolsTrigger).toBeDisabled();

    await stopButton.click();

    // The turn closes on the notice rather than on the agent's answer: on the
    // request path onResponse() is the only thing that ends a turn, so without
    // it the loader would spin for good.
    await expect(answer.last()).toHaveText(
      'You interrupted the agent. What would you like to change?',
    );
    // The narration of the hops that did land stays, on a status row that
    // reports stopped. The agent did not finish its work, so the completed
    // check must not be shown.
    await expect(
      progressMessage.last().locator('.aiStoppedIcon'),
    ).toBeVisible();
    await expect(
      progressMessage.last().locator('.aiCompletedIcon'),
    ).toBeHidden();
    await expect(progressMessage.last().locator('.aiLoader')).toBeHidden();

    // No hop follows the one that was in flight. The backend is not told about
    // the stop: the hop it was executing may end and store what it produced,
    // and the turns below show why that does not matter.
    releaseHeldHop();
    expect(requests).toBe(3);

    // The chat is usable again: the stop button is hidden, the Tools trigger
    // is unlocked and the input takes another prompt.
    await expect(stopButton).toBeHidden();
    await expect(toolsTrigger).toBeEnabled();
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/what_is_a_cms.json
    await ai.submitQuery('What is a CMS?');
    await expect(answer.last()).toContainText(
      'A Content Management System (CMS) is a software application',
    );

    // Every hop names the turn the chat last accepted as the checkpoint to
    // resume from. The first turn had none to name. The stopped turn's hops
    // named the first turn, and so does the turn after the stop: the stopped
    // turn was never accepted, so whatever it left on the server is never
    // resumed.
    // @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::prepareAgent()
    expect(requests).toBe(4);
    const acceptedTurn = bodies[0].request_id;
    expect(acceptedTurn).toMatch(/^req_/);
    expect(bodies[1].request_id).not.toBe(acceptedTurn);
    expect(bodies.map((body) => body.last_successful_request_id)).toEqual([
      '',
      acceptedTurn,
      acceptedTurn,
      acceptedTurn,
    ]);
  });

  test('Stopping during a placement batch', async ({
    page,
    drupal,
    canvas,
    ai,
  }) => {
    // "Approved" runs three hops. The first places nothing, the second places
    // three components and the third places two, one second apart.
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/approved.json
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/approved-2.json
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/approved-3.json
    // Replace the page's timers and Date with a clock the test controls.
    await page.clock.install();
    let requests = 0;
    await page.route('**/admin/api/canvas/ai-dev', async (route) => {
      requests += 1;
      // The first two hops run unchanged: the first places no components (see
      // approved.json) and the second places three (see approved-2.json).
      // The third hop's response reaches the page only after the page clock
      // is frozen. A frozen clock never fires setTimeout, so the third hop
      // places its first component but not its second, which waits on a
      // one-second setTimeout. The test can press stop between the two.
      if (requests === 3) {
        const response = await route.fetch();
        const now = await page.evaluate(() => Date.now());
        // Fast-forward the page clock one second and pause it there. The
        // clock keeps running after Date.now() is read, so pausing at that
        // exact time could be in the past by the time pauseAt() runs.
        await page.clock.pauseAt(now + 1000);
        await route.fulfill({ response });
        return;
      }
      await route.continue();
    });
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas();
    await canvas.openLayersPanel();
    await ai.openPanel();

    const chat = page.getByTestId('canvas-ai-panel').locator('deep-chat');
    const progressMessage = chat.locator('.html-message');
    const answer = chat.locator('.text-message.ai-message-text');
    const stopButton = chat.locator('.custom-button.inside-end');
    const layers = page
      .getByTestId('canvas-primary-panel')
      .getByRole('treeitem');

    await ai.submitQuery('Approved');
    // Three components from the second hop and the first from the third.
    await expect(layers).toHaveCount(4);
    await stopButton.click();

    // Fire the pending one-second wait between the third hop's components.
    await page.clock.runFor(3000);
    // The stop button hides once the turn has ended.
    await expect(stopButton).toBeHidden();
    // The turn was stopped, so the page holds only the three components from
    // the second hop and the first from the third. The third hop's second
    // component is not placed.
    await expect(layers).toHaveCount(4);
    // The turn closes on the stopped notice, not on the answer the third hop
    // carried, and its status row reports stopped.
    await expect(answer.last()).toHaveText(
      'You interrupted the agent. What would you like to change?',
    );
    await expect(
      answer.filter({ hasText: 'The landing page sections are in place.' }),
    ).toHaveCount(0);
    await expect(
      progressMessage.last().locator('.aiStoppedIcon'),
    ).toBeVisible();
  });

  test('Tools dropdown', async ({ page, drupal, canvas, ai }) => {
    // The body of every request the chat sends, in order: the Tool a message
    // was sent with travels as its `selected_tool` key.
    // @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::resolveAgentId()
    const bodies: Record<string, unknown>[] = [];
    await page.route('**/admin/api/canvas/ai-dev', async (route) => {
      bodies.push(route.request().postDataJSON());
      await route.continue();
    });

    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas();
    await ai.openPanel();

    const chat = page.getByTestId('canvas-ai-panel').locator('deep-chat');
    // The Tools menu trigger is a deep-chat custom button inside its shadow
    // DOM, which CSS locators pierce. The stop button is a custom button too,
    // so each is addressed by the class deep-chat derives from its position.
    const toolsTrigger = chat.locator('.custom-button.inside-start');
    const menu = page.getByTestId('canvas-ai-tool-selector');
    const rows = menu.getByRole('button');
    const componentRow = menu.getByRole('button', {
      name: /Drupal Canvas Component Agent/,
    });
    const builderRow = menu.getByRole('button', {
      name: /Drupal Canvas Page Agent/,
    });
    const pill = page.getByTestId('canvas-ai-active-tool');
    const removeButton = pill.getByRole('button', {
      name: 'Remove the selected tool',
    });
    const answer = chat.locator('.text-message.ai-message-text');

    // The menu lists every agent canvas_dev_ai.settings offers as a Tool, in
    // the configured order, each with its label and description. Nothing is
    // selected to begin with.
    // @see canvas_dev_ai_install()
    // @see \Drupal\canvas_dev_ai\Hook\CanvasDevAiHooks::jsSettingsAlter()
    await toolsTrigger.click();
    await expect(menu).toBeVisible();
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toContainText('Drupal Canvas Component Agent');
    await expect(rows.nth(0)).toContainText(
      'Creates and edits React components for Drupal Canvas.',
    );
    await expect(rows.nth(1)).toContainText('Drupal Canvas Page Agent');
    await expect(rows.nth(1)).toContainText(
      'Builds and extends pages using existing components, edits components already on a page, and sets the page title and description.',
    );
    await expect(componentRow).toHaveAttribute('aria-pressed', 'false');
    await expect(builderRow).toHaveAttribute('aria-pressed', 'false');
    await expect(pill).toBeHidden();
    // Escape closes the menu. Clicking the trigger would reopen it: Radix
    // takes a click in deep-chat's shadow DOM for an outside interaction.
    await menu.press('Escape');
    await expect(menu).toBeHidden();

    // With no Tool selected, a message is sent without a `selected_tool` key.
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/what_is_a_cms.json
    await ai.submitQuery('What is a CMS?');
    await expect(answer).toHaveCount(1);
    expect(bodies).toHaveLength(1);
    expect(bodies[0]).not.toHaveProperty('selected_tool');

    // Selecting a Tool closes the menu and shows the Tool in the pill; the
    // menu marks it as the pressed row.
    await toolsTrigger.click();
    await builderRow.click();
    await expect(menu).toBeHidden();
    await expect(pill).toContainText('Drupal Canvas Page Agent');
    await toolsTrigger.click();
    await expect(builderRow).toHaveAttribute('aria-pressed', 'true');
    await expect(componentRow).toHaveAttribute('aria-pressed', 'false');
    await menu.press('Escape');
    await expect(menu).toBeHidden();

    // A message sent with a Tool selected carries that Tool's id.
    await ai.submitQuery('What is a CMS?');
    await expect(answer).toHaveCount(2);
    expect(bodies).toHaveLength(2);
    expect(bodies[1].selected_tool).toBe('drupal_canvas_page_agent');

    // Selecting a different Tool replaces it in the pill, and in the next
    // request.
    await toolsTrigger.click();
    await componentRow.click();
    await expect(menu).toBeHidden();
    await expect(pill).toContainText('Drupal Canvas Component Agent');
    await expect(pill).not.toContainText('Page Agent');
    await ai.submitQuery('What is a CMS?');
    await expect(answer).toHaveCount(3);
    expect(bodies).toHaveLength(3);
    expect(bodies[2].selected_tool).toBe('canvas_component_agent');

    // The pill's Remove button clears the selection: the pill is removed, no
    // row is pressed, and the next request carries no key.
    await removeButton.click();
    await expect(pill).toBeHidden();
    await toolsTrigger.click();
    await expect(componentRow).toHaveAttribute('aria-pressed', 'false');
    await expect(builderRow).toHaveAttribute('aria-pressed', 'false');
    await menu.press('Escape');
    await expect(menu).toBeHidden();
    await ai.submitQuery('What is a CMS?');
    await expect(answer).toHaveCount(4);
    expect(bodies).toHaveLength(4);
    expect(bodies[3]).not.toHaveProperty('selected_tool');

    // Selecting the active Tool again clears it the same way.
    await toolsTrigger.click();
    await componentRow.click();
    await expect(pill).toContainText('Drupal Canvas Component Agent');
    await toolsTrigger.click();
    await componentRow.click();
    await expect(menu).toBeHidden();
    await expect(pill).toBeHidden();
    await toolsTrigger.click();
    await expect(componentRow).toHaveAttribute('aria-pressed', 'false');
    await expect(builderRow).toHaveAttribute('aria-pressed', 'false');
    await menu.press('Escape');
    await expect(menu).toBeHidden();
    await ai.submitQuery('What is a CMS?');
    await expect(answer).toHaveCount(5);
    expect(bodies).toHaveLength(5);
    expect(bodies[4]).not.toHaveProperty('selected_tool');
  });

  test('No Tools configured', async ({ page, drupal, canvas, ai }) => {
    const bodies: Record<string, unknown>[] = [];
    await page.route('**/admin/api/canvas/ai-dev', async (route) => {
      bodies.push(route.request().postDataJSON());
      await route.continue();
    });

    // An administrator turns every Tool off on the Agents & Tools form.
    // @see \Drupal\canvas_dev_ai\Form\CanvasDevAiAgentSelectionForm
    await drupal.loginAsAdmin();
    await page.goto('/admin/config/ai/canvas-ai-agent-selection');
    await page
      .getByRole('checkbox', { name: 'Drupal Canvas Component Agent' })
      .uncheck();
    await page
      .getByRole('checkbox', { name: 'Drupal Canvas Page Agent' })
      .uncheck();
    await page.getByRole('button', { name: 'Save configuration' }).click();
    await expect(
      page.getByText('The configuration options have been saved.'),
    ).toBeVisible();
    await drupal.logout();

    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas();
    await ai.openPanel();

    const chat = page.getByTestId('canvas-ai-panel').locator('deep-chat');
    const answer = chat.locator('.text-message.ai-message-text');

    // The chat renders without the Tools menu trigger, so there is no menu
    // and no pill either.
    await expect(
      page.getByRole('textbox', { name: 'Build me a' }),
    ).toBeVisible();
    await expect(chat.locator('.custom-button.inside-start')).toHaveCount(0);
    await expect(page.getByTestId('canvas-ai-tool-selector')).toBeHidden();
    await expect(page.getByTestId('canvas-ai-active-tool')).toBeHidden();

    // The chat itself works, and its requests carry no `selected_tool` key.
    // @see modules/canvas_ai/tests/modules/canvas_ai_test/fixtures/what_is_a_cms.json
    await ai.submitQuery('What is a CMS?');
    await expect(answer).toHaveCount(1);
    expect(bodies).toHaveLength(1);
    expect(bodies[0]).not.toHaveProperty('selected_tool');
  });
});
