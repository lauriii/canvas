<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Functional\Form;

use Drupal\canvas\Plugin\ComponentPluginManager;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\canvas\Traits\CreateTestJsComponentTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Functional test for the Canvas AI Component Description Settings Form.
 */
#[Group('canvas')]
#[Group('canvas_ai')]
final class CanvasAiComponentDescriptionSettingsFormTest extends BrowserTestBase {

  use CreateTestJsComponentTrait;

  /**
   * The route name for the form.
   */
  private const ROUTE_NAME = 'canvas_ai.component_description_settings';

  /**
   * The admin user.
   */
  private AccountInterface $adminUser;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = TRUE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_ai',
    'canvas_test_sdc',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $admin_user = $this->drupalCreateUser([
      'administer components',
      'create canvas_page',
    ]);
    \assert($admin_user instanceof AccountInterface);
    $this->adminUser = $admin_user;
    $this->drupalLogin($admin_user);
  }

  /**
   * Tests that the form title is displayed correctly.
   */
  public function testFormTitleDisplay(): void {

    // Get the expected title from the route definition.
    $route = \Drupal::service('router.route_provider')->getRouteByName(self::ROUTE_NAME);
    $expected_title = $route->getDefault('_title');

    // Navigate to the form using the route.
    $this->drupalGet(Url::fromRoute(self::ROUTE_NAME));

    // Assert that the page loads successfully.
    $this->assertSession()->statusCodeEquals(200);

    // Assert that the form title is displayed correctly.
    $this->assertSession()->pageTextContains($expected_title);
  }

  /**
   * Tests that a validation error is shown when form is submitted without data.
   */
  public function testFormValidationError(): void {
    // Navigate to the form.
    $this->drupalGet(Url::fromRoute(self::ROUTE_NAME));

    // Submit the form without enabling any sources.
    $this->submitForm([], 'Save configuration');

    // Assert that the validation error is displayed.
    $this->assertSession()->pageTextContains('At least one source must be enabled.');
  }

  /**
   * Tests that the form displays correct default values when loaded.
   */
  public function testFormDefaultValues(): void {
    // Load the 'Canvas test SDC with props and slots' component definition.
    $definitions = \Drupal::service(ComponentPluginManager::class)->getDefinitions();
    $test_sdc_component = $definitions['canvas_test_sdc:props-slots'];

    // Navigate to the form.
    $this->drupalGet(Url::fromRoute(self::ROUTE_NAME));

    // Assert that the form contains a details element for the 'Canvas test SDC with props and slots' component.
    // cspell:disable-next-line
    $this->assertSession()->elementExists('css', 'details#edit-component-context-sdc-components-sdccanvas-test-sdcprops-slots');

    // Assert that the Description field is present.
    $this->assertSession()->fieldExists('component_context[sdc][components][sdc.canvas_test_sdc.props-slots][description]');
    // Verify that the description field contains the correct default value.
    // The 'Canvas test SDC with props and slots' component does not have a description
    // in its definition, so the default value loaded in the form will be its name.
    $this->assertSession()->fieldValueEquals(
      'component_context[sdc][components][sdc.canvas_test_sdc.props-slots][description]',
      $test_sdc_component['description'] ?? $test_sdc_component['name']
    );

    // Just to be safe, verify the description of the 'sdc.canvas_test_sdc.heading' component as well.
    $this->assertSession()->fieldValueEquals(
      'component_context[sdc][components][sdc.canvas_test_sdc.heading][description]',
      $definitions['canvas_test_sdc:heading']['description']
    );

    // Now verify that fields are available to override the prop descriptions, and
    // those fields have the correct default values.
    $test_sdc_component_props = $test_sdc_component['props']['properties'];
    $this->assertIsArray($test_sdc_component_props);
    foreach ($test_sdc_component_props as $prop_name => $prop_definition) {
      // Attribute props are explicitly skipped.
      // @see Drupal\canvas_ai\CanvasAiPageBuilderHelper::processSdc().
      if (($prop_definition['type'] ?? '') === 'Drupal\Core\Template\Attribute') {
        continue;
      }
      $this->assertSession()->elementExists('css', "textarea[name='component_context[sdc][components][sdc.canvas_test_sdc.props-slots][props][$prop_name][description]']");
      $this->assertSession()->fieldValueEquals(
        "component_context[sdc][components][sdc.canvas_test_sdc.props-slots][props][$prop_name][description]",
        $prop_definition['description'] ?? 'No description available'
      );
    }

    // Now verify that fields are available to override the slot descriptions, and
    // those fields have the correct default values.
    $test_sdc_component_slots = $test_sdc_component['slots'];
    $this->assertIsArray($test_sdc_component_slots);
    foreach ($test_sdc_component_slots as $slot_name => $slot_definition) {
      $this->assertSession()->elementExists('css', "textarea[name='component_context[sdc][components][sdc.canvas_test_sdc.props-slots][slots][$slot_name][description]']");
      $this->assertSession()->fieldValueEquals(
        "component_context[sdc][components][sdc.canvas_test_sdc.props-slots][slots][$slot_name][description]",
        $slot_definition['description'] ?? 'No description available'
      );
    }

    // Assert that the form contains a details element for the 'System branding' block component.
    // cspell:disable-next-line
    $this->assertSession()->elementExists('css', 'details#edit-component-context-block-components-blocksystem-branding-block');
    // Verify that the description field contains the correct default value. For blocks,
    // this will be the same as the block's label.
    // @see Drupal\canvas_ai\CanvasAiPageBuilderHelper::getAllComponentsKeyedBySource().
    // Get the block plugin manager
    $block_manager = \Drupal::service('plugin.manager.block');
    $plugin_block = $block_manager->createInstance('system_branding_block', []);
    \assert($plugin_block instanceof BlockPluginInterface);
    // Get the admin label.
    $definition = $plugin_block->getPluginDefinition();
    \assert(\is_array($definition));
    $label = $definition['admin_label']->__toString();
    $this->assertSession()->fieldValueEquals(
      'component_context[block][components][block.system_branding_block][description]',
      $label
    );

    // Verify the form exposes non-excluded block props and hides excluded ones.
    // Drive the assertion from the raw schema so the test stays correct if the
    // block gains or loses settings in future Drupal versions.
    $raw_schema = \Drupal::service('config.typed')->getDefinition('block.settings.system_branding_block');
    $mapping = array_filter(\is_array($raw_schema['mapping'] ?? NULL) ? $raw_schema['mapping'] : [], 'is_array');
    $excluded = ['id', 'provider', 'admin_label', 'context_mapping'];
    foreach ($excluded as $key) {
      $this->assertSession()->elementNotExists(
        'css',
        "textarea[name='component_context[block][components][block.system_branding_block][props][$key][description]']"
      );
    }
    foreach (array_diff_key($mapping, array_flip($excluded)) as $prop_name => $unused) {
      $this->assertSession()->elementExists(
        'css',
        "textarea[name='component_context[block][components][block.system_branding_block][props][$prop_name][description]']"
      );
    }
  }

  /**
   * Tests that the form submits and saves data correctly.
   */
  public function testFormSubmit(): void {
    // Navigate to the form.
    $this->drupalGet(Url::fromRoute(self::ROUTE_NAME));

    // Assert that the Two column component details element exists.
    // cspell:disable-next-line
    $this->assertSession()->elementExists('css', 'details#edit-component-context-sdc-components-sdccanvas-test-sdctwo-column');

    // Enable SDC source and update descriptions for the Two column component.
    // Also update the description for the System branding block component.
    $edit = [
      'component_context[sdc][enabled]' => 1,
      'component_context[sdc][components][sdc.canvas_test_sdc.two_column][description]' => 'Two column description updated',
      'component_context[sdc][components][sdc.canvas_test_sdc.two_column][props][width][description]' => 'Width prop description updated',
      'component_context[sdc][components][sdc.canvas_test_sdc.two_column][slots][column_one][description]' => 'Column one slot description updated',
      'component_context[sdc][components][sdc.canvas_test_sdc.two_column][slots][column_two][description]' => 'Column two slot description updated',
      'component_context[block][components][block.system_branding_block][description]' => 'Branding block description updated',
      'component_context[block][components][block.system_branding_block][props][use_site_logo][description]' => 'Use site logo description updated',
    ];
    $this->submitForm($edit, 'Save configuration');

    // Assert that the status message is displayed.
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Assert that the default values are updated correctly.
    $this->assertSession()->fieldValueEquals('component_context[sdc][enabled]', '1');
    $this->assertSession()->fieldValueEquals('component_context[block][enabled]', '');
    $this->assertSession()->fieldValueEquals('component_context[sdc][components][sdc.canvas_test_sdc.two_column][description]', 'Two column description updated');
    $this->assertSession()->fieldValueEquals('component_context[sdc][components][sdc.canvas_test_sdc.two_column][props][width][description]', 'Width prop description updated');
    $this->assertSession()->fieldValueEquals('component_context[sdc][components][sdc.canvas_test_sdc.two_column][slots][column_one][description]', 'Column one slot description updated');
    $this->assertSession()->fieldValueEquals('component_context[sdc][components][sdc.canvas_test_sdc.two_column][slots][column_two][description]', 'Column two slot description updated');
    $this->assertSession()->fieldValueEquals('component_context[block][components][block.system_branding_block][description]', 'Branding block description updated');
    $this->assertSession()->fieldValueEquals('component_context[block][components][block.system_branding_block][props][use_site_logo][description]', 'Use site logo description updated');
  }

  /**
   * Tests the component context built after saving the form.
   *
   * Enabled sources mark their required props; disabled sources are excluded.
   *
   * @see \Drupal\canvas_ai\CanvasAiPageBuilderHelper::getComponentContextForAi()
   */
  public function testComponentContextAfterFormSubmit(): void {
    // Create a code component so the JS source is available on the form.
    $this->createMyCtaComponentFromSdc();
    // getComponentContextForAi() is access-gated, so act as the admin here too.
    $this->container->get('current_user')->setAccount($this->adminUser);

    // Enable every source.
    $this->drupalGet(Url::fromRoute(self::ROUTE_NAME));
    $this->submitForm([
      'component_context[sdc][enabled]' => 1,
      'component_context[block][enabled]' => 1,
      'component_context[js][enabled]' => 1,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Each enabled source marks its required props.
    $context = $this->loadComponentContext();
    $this->assertTrue($context['sdc.canvas_test_sdc.props-slots']['props']['heading']['required'] ?? FALSE, 'Required SDC prop must be marked.');
    $this->assertTrue($context['block.system_branding_block']['props']['label_display']['required'] ?? FALSE, 'Required block prop must be marked.');
    $this->assertTrue($context['js.my-cta']['props']['text']['required'] ?? FALSE, 'Required code component prop must be marked.');

    // Disable the SDC source; keep block and JS enabled.
    $this->drupalGet(Url::fromRoute(self::ROUTE_NAME));
    $this->submitForm([
      'component_context[sdc][enabled]' => FALSE,
      'component_context[block][enabled]' => 1,
      'component_context[js][enabled]' => 1,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // The disabled SDC source is excluded from the context.
    $context = $this->loadComponentContext();
    $this->assertArrayNotHasKey('sdc.canvas_test_sdc.props-slots', $context);
    $this->assertArrayHasKey('block.system_branding_block', $context);
    $this->assertArrayHasKey('js.my-cta', $context);
  }

  /**
   * Reads the freshly saved component context sent to the agent.
   *
   * @return array
   *   The parsed component context, keyed by component ID.
   */
  private function loadComponentContext(): array {
    $this->container->get('config.factory')->reset('canvas_ai.component_description.settings');
    $yaml = \Drupal::service('canvas_ai.page_builder_helper')->getComponentContextForAi();
    return Yaml::parse($yaml) ?? [];
  }

}
