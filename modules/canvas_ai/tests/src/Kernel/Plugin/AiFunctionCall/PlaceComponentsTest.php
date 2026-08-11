<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai\Plugin\AiFunctionCall\PlaceComponents;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\canvas_ai\Traits\FunctionalCallTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the PlaceComponents function call plugin.
 *
 * This test is adapted from
 * \Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall\SetAIGeneratedComponentStructureTest
 * and will replace it once the set_component_structure tool is deleted.
 *
 * @see \Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall\SetAIGeneratedComponentStructureTest
 */
#[Group('canvas_ai')]
final class PlaceComponentsTest extends CanvasKernelTestBase {

  use CreateTestJsComponentTrait;
  use FunctionalCallTestTrait;
  use UserCreationTrait;

  /**
   * The function call plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $functionCallManager;

  /**
   * A test user with AI permissions.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $privilegedUser;

  /**
   * A test user without AI permissions.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $unprivilegedUser;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'ai',
    'ai_agents',
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installConfig(['canvas']);
    $this->installEntitySchema('file');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    $privileged_user = $this->createUser([CanvasAiPermissions::USE_CANVAS_AI]);
    $unprivileged_user = $this->createUser();
    if (!$privileged_user instanceof User || !$unprivileged_user instanceof User) {
      throw new \Exception('Failed to create test users');
    }
    $this->privilegedUser = $privileged_user;
    $this->unprivilegedUser = $unprivileged_user;
    $this->container->get(ConfigFactoryInterface::class)
      ->getEditable('system.theme')
      ->set('default', 'stark')
      ->save();
  }

  /**
   * Tests placing components with proper permissions and valid data.
   */
  #[DataProvider('placementDataProvider')]
  public function testPlaceComponentsWithPermissionsAndValidData(string $layout_type, array $operations, array $expected_output): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    // Set the current layout to a valid layout.
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout($layout_type));

    $tool = $this->functionCallManager->createInstance('canvas_ai:place_components');
    $this->assertInstanceOf(PlaceComponents::class, $tool);
    $tool->setContextValue('operations', $operations);
    $tool->execute();

    // Each placed component carries a backend-assigned UUID, so the frontend
    // can apply it and the model can chain reference_uuid across placements.
    // The UUIDs are non-deterministic: assert they are present, valid, and
    // unique, then compare the rest of the payload against the expected
    // nodePaths and field values.
    $structured_output = $tool->getStructuredOutput();
    $assigned_uuids = self::collectOperationUuids($structured_output);
    $this->assertNotEmpty($assigned_uuids);
    $this->assertSame($assigned_uuids, array_values(array_unique($assigned_uuids)));
    foreach ($assigned_uuids as $uuid) {
      $this->assertTrue(Uuid::isValid($uuid), \sprintf('"%s" is a valid UUID.', $uuid));
    }
    self::assertEquals($expected_output, self::stripOperationUuids($structured_output));

    // The readable output must tell the model those same UUIDs and the
    // predicted layout, so it can chain follow-up placements.
    $readable_output = $tool->getReadableOutput();
    self::assertStringStartsWith('Components placed successfully.', $readable_output);
    self::assertStringContainsString('This result is a continuation point, not a stopping point:', $readable_output);
    foreach ($assigned_uuids as $uuid) {
      self::assertStringContainsString($uuid, $readable_output);
    }
  }

  /**
   * Tests placing components without proper permissions.
   */
  public function testPlaceComponentsWithoutPermissions(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->unprivilegedUser);

    $tool = $this->functionCallManager->createInstance('canvas_ai:place_components');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    // Expect an exception to be thrown.
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');

    $tool->setContextValue('operations', []);
    $tool->execute();
  }

  /**
   * Tests that the rendered schema exposes the operations contract.
   */
  public function testOperationsSchema(): void {
    $tool = $this->functionCallManager->createInstance('canvas_ai:place_components');
    $this->assertInstanceOf(PlaceComponents::class, $tool);

    $rendered = $tool->normalize()->renderFunctionArray();
    $this->assertSame(['operations'], $rendered['parameters']['required']);

    $operations_schema = $rendered['parameters']['properties']['operations'];
    $this->assertSame('array', $operations_schema['type']);
    $this->assertSame(1, $operations_schema['minItems']);

    $item_schema = $operations_schema['items'];
    $this->assertSame('object', $item_schema['type']);
    $this->assertSame(['target', 'placement', 'components'], $item_schema['required']);
    $this->assertSame(['above', 'below', 'inside'], $item_schema['properties']['placement']['enum']);
    $this->assertArrayHasKey('target', $item_schema['properties']);
    $this->assertArrayHasKey('reference_uuid', $item_schema['properties']);
    $this->assertArrayHasKey('components', $item_schema['properties']);
  }

  /**
   * Tests that an empty operations list is rejected by context validation.
   *
   * The empty/absent case is enforced by the context-definition schema
   * (validateContexts), not the tool, so that seam is asserted directly.
   */
  public function testEmptyOperationsListFailsContextValidation(): void {
    $tool = $this->functionCallManager->createInstance('canvas_ai:place_components');
    $this->assertInstanceOf(PlaceComponents::class, $tool);
    $tool->setContextValue('operations', []);
    $this->assertGreaterThan(0, $tool->validateContexts()->count());
  }

  /**
   * Tests placing components with invalid placement parameters.
   */
  #[DataProvider('invalidPlacementDataProvider')]
  public function testPlaceComponentsWithInvalidYaml(string $layout_type, array $operations, array $expected_error): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    // Set the current layout to a valid layout.
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout($layout_type));

    $result = $this->getComponentToolOutput($operations);
    $expected_error = 'Failed to place components: ' . Yaml::dump($expected_error);
    $this->assertStringContainsString($expected_error, $result);
  }

  /**
   * Tests placing components with invalid component validation.
   */
  public function testPlaceComponentsWithInvalidComponents(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    // Set the current layout to a valid layout.
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout('multi_region_empty'));

    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - invalid.component.id:
            props:
              title: 'Invalid Component'
        YAML),
    ]);
    $this->assertSame("Failed to place components: Component validation errors: components.0.[invalid.component.id]: The 'canvas.component.invalid.component.id' config does not exist.", self::normalizeErrorString($result));

    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.two_column:
            props:
              width: 50
            slots:
              column_one:
                - sdc.canvas_test_sdc.invalid_component:
                    props:
                      heading: 'My Hero'
                      subheading: 'SubSnub'
                      cta1href: 'https://example.com'
                      cta1: 'View it!'
                      cta2: 'Click it!'
        YAML),
    ]);
    $this->assertSame("Failed to place components: Component validation errors: components.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.invalid_component]: The 'canvas.component.sdc.canvas_test_sdc.invalid_component' config does not exist.", self::normalizeErrorString($result));
  }

  /**
   * Tests component validation logic.
   */
  public function testValidateComponent(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);

    $components_yaml = <<<YAML
      - sdc.canvas_test_sdc.my-hero:
          props:
            subheading: 'SubSnub'
            cta1: 'View it!'
            cta1href: 'https://canvas-example.com'
            cta2: 'Click it!'
      YAML;
    $result = $this->getComponentToolOutput([self::buildOperation($components_yaml)]);
    $this->assertSame("Failed to place components: Component validation errors: components.0.[sdc.canvas_test_sdc.my-hero].props.heading: The property heading is required.", self::normalizeErrorString($result));
    // Ensure we gracefully handle 'props' not being set.
    $decoded_components = Yaml::parse($components_yaml);
    unset($decoded_components[0]['sdc.canvas_test_sdc.my-hero']['props']);
    $result = $this->getComponentToolOutput([self::buildOperation(Yaml::dump($decoded_components))]);
    $this->assertSame('Failed to place components: Component validation errors: components.0.[sdc.canvas_test_sdc.my-hero].props.heading: The property heading is required. components.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required.', self::normalizeErrorString($result));

    $nested_components_yaml = <<<YAML
      - sdc.canvas_test_sdc.two_column:
          props:
            width: 50
          slots:
            column_one:
              - sdc.canvas_test_sdc.my-hero:
                  props:
                    heading: 'My Hero'
                    subheading: 'SubSnub'
                    cta1: 'View it!'
                    cta2: 'Click it!'
      YAML;
    $result = $this->getComponentToolOutput([self::buildOperation($nested_components_yaml)]);
    $this->assertSame('Failed to place components: Component validation errors: components.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required.', self::normalizeErrorString($result));

    // Ensure we error on invalid slot names.
    $decoded_nested = Yaml::parse($nested_components_yaml);
    $decoded_nested[0]['sdc.canvas_test_sdc.two_column']['slots']['not_real_slot'] = $decoded_nested[0]['sdc.canvas_test_sdc.two_column']['slots']['column_one'];
    $result = $this->getComponentToolOutput([self::buildOperation(Yaml::dump($decoded_nested))]);
    $this->assertSame('Failed to place components: Component validation errors: components.0.[sdc.canvas_test_sdc.two_column]: Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc.canvas_test_sdc.two_column</em>: <em class="placeholder">not_real_slot</em>. Valid slot names are: <em class="placeholder">column_one, column_two</em>. components.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required. components.0.[sdc.canvas_test_sdc.two_column].slots.not_real_slot.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required.', self::normalizeErrorString($result));
  }

  /**
   * Tests that props that do not exist on a component fail validation.
   */
  public function testValidateComponentWithNonExistentProps(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);

    // A valid required prop plus a prop the component does not define.
    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.props-no-slots:
            props:
              heading: 'A valid heading'
              nonexistent_prop: 'This prop does not exist'
        YAML),
    ]);
    $this->assertSame('Failed to place components: Component validation errors: components.0.[sdc.canvas_test_sdc.props-no-slots].props.nonexistent_prop: Component `sdc.canvas_test_sdc.props-no-slots`: the `nonexistent_prop` prop is not defined. (code garbage)', self::normalizeErrorString($result));

    // Any prop sent to a component that defines no props must fail.
    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.druplicon:
            props:
              heading: 'Druplicon has no props'
        YAML),
    ]);
    $this->assertSame('Failed to place components: Component validation errors: components.0.[sdc.canvas_test_sdc.druplicon].props.heading: Component `sdc.canvas_test_sdc.druplicon`: the `heading` prop is not defined. (code garbage)', self::normalizeErrorString($result));

    // A non-existent prop on a component nested inside a slot.
    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.two_column:
            props:
              width: 50
            slots:
              column_one:
                - sdc.canvas_test_sdc.heading:
                    props:
                      text: 'A heading'
                      element: 'h2'
                      nonexistent_prop: 'Bogus'
        YAML),
    ]);
    $this->assertSame('Failed to place components: Component validation errors: components.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.heading].props.nonexistent_prop: Component `sdc.canvas_test_sdc.heading`: the `nonexistent_prop` prop is not defined. (code garbage)', self::normalizeErrorString($result));

    // A missing required prop and a non-existent prop are both reported.
    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.props-no-slots:
            props:
              nonexistent_prop: 'This prop does not exist'
        YAML),
    ]);
    $this->assertSame('Failed to place components: Component validation errors: components.0.[sdc.canvas_test_sdc.props-no-slots].props.heading: The property heading is required. components.0.[sdc.canvas_test_sdc.props-no-slots].props.nonexistent_prop: Component `sdc.canvas_test_sdc.props-no-slots`: the `nonexistent_prop` prop is not defined. (code garbage)', self::normalizeErrorString($result));

    // Props provided as a scalar instead of a mapping must also fail instead
    // of being silently dropped.
    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.druplicon:
            props: 'heading: Not a mapping'
        YAML),
    ]);
    $this->assertSame('Failed to place components: Component validation errors: components.0.[sdc.canvas_test_sdc.druplicon].props: Component `sdc.canvas_test_sdc.druplicon`: the props must be a mapping of prop names to values. (code garbage)', self::normalizeErrorString($result));

    // Code components (JS source) resolve props the same way as SDCs, so a
    // prop the component does not define must fail for them too.
    $this->createTestCodeComponent();
    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - js.test-code-component:
            props:
              heading: 'A valid heading'
              nonexistent_prop: 'This prop does not exist'
        YAML),
    ]);
    $this->assertSame('Failed to place components: Component validation errors: components.0.[js.test-code-component].props.nonexistent_prop: Component `js.test-code-component`: the `nonexistent_prop` prop is not defined. (code garbage)', self::normalizeErrorString($result));
  }

  /**
   * Tests placement against a reference_uuid that does not exist.
   */
  public function testPlaceComponentsWithUnknownReferenceUuid(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout('multi_region_non_empty'));

    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.heading:
            props:
              text: "Some text"
              element: "h1"
        YAML, placement: 'below', reference_uuid: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'),
    ]);
    $this->assertSame('Failed to place components: Component with UUID "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee" not found in layout', self::normalizeErrorString($result));
  }

  /**
   * Runs the place_components tool and returns its readable output.
   *
   * @param array $operations
   *   The operations to pass as the tool's 'operations' context value.
   *
   * @return string
   *   The tool's readable output.
   */
  private function getComponentToolOutput(array $operations): string {
    return $this->getToolOutput('canvas_ai:place_components', ['operations' => $operations]);
  }

  /**
   * Builds a single placement operation record.
   *
   * @param string $components_yaml
   *   The YAML list of components to place.
   * @param string $target
   *   The target region or 'parent-uuid/slot_name'.
   * @param string $placement
   *   The placement: 'inside', 'above', or 'below'.
   * @param string $reference_uuid
   *   The reference UUID for 'above'/'below' placement.
   *
   * @return array
   *   A single operation record, matching the place_components schema.
   */
  private static function buildOperation(string $components_yaml, string $target = 'content', string $placement = 'inside', string $reference_uuid = ''): array {
    return [
      'target' => $target,
      'placement' => $placement,
      'reference_uuid' => $reference_uuid,
      'components' => $components_yaml,
    ];
  }

  /**
   * Collects the assigned UUID of every component in a structured output.
   *
   * @param array $structured_output
   *   The tool's structured output.
   *
   * @return string[]
   *   The assigned UUIDs, in document order.
   */
  private static function collectOperationUuids(array $structured_output): array {
    $uuids = [];
    foreach ($structured_output['operations'] ?? [] as $operation) {
      foreach ($operation['components'] ?? [] as $component) {
        if (isset($component['uuid'])) {
          $uuids[] = $component['uuid'];
        }
      }
    }
    return $uuids;
  }

  /**
   * Removes the assigned UUID from every component in a structured output.
   *
   * @param array $structured_output
   *   The tool's structured output.
   *
   * @return array
   *   The structured output with each component's 'uuid' removed.
   */
  private static function stripOperationUuids(array $structured_output): array {
    foreach ($structured_output['operations'] as &$operation) {
      foreach ($operation['components'] as &$component) {
        unset($component['uuid']);
      }
      unset($component);
    }
    unset($operation);
    return $structured_output;
  }

  /**
   * Data provider for placement test cases.
   *
   * @return array
   *   An array of test cases.
   */
  public static function placementDataProvider(): array {
    return [
      'test_placement_inside_single' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML),
        ],
        'expected_output' => [
          'operations' => [
            [
              'operation' => 'ADD',
              'components' => [
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [1, 0],
                  'fieldValues' => [
                    'text' => 'Some text',
                    'element' => 'h1',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'test_placement_inside_multiple' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML),
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.two_column:
                props:
                  width: 50
                slots:
                  column_one:
                    - sdc.canvas_test_sdc.my-hero:
                        props:
                          heading: 'My Hero'
                          subheading: 'SubSnub'
                          cta1: 'View it!'
                          cta1href: 'https://example.com'
                          cta2: 'Click it!'
            YAML, target: 'footer'),
        ],
        'expected_output' => [
          'operations' => [
            [
              'operation' => 'ADD',
              'components' => [
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [1, 0],
                  'fieldValues' => [
                    'text' => 'Some text',
                    'element' => 'h1',
                  ],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.two_column',
                  'nodePath' => [2, 0],
                  'fieldValues' => [
                    'width' => 50,
                  ],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.my-hero',
                  'nodePath' => [2, 0, 0, 0],
                  'fieldValues' => [
                    'heading' => 'My Hero',
                    'subheading' => 'SubSnub',
                    'cta1' => 'View it!',
                    'cta1href' => 'https://example.com',
                    'cta2' => 'Click it!',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'test_placement_below' => [
        'layout_type' => 'multi_region_non_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "After existing component"
                  element: "h2"
            YAML, placement: 'below', reference_uuid: '72384115-a8ee-44bc-9a13-de1c7a4d9b96'),
        ],
        'expected_output' => [
          'operations' => [
            [
              'operation' => 'ADD',
              'components' => [
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [1, 1],
                  'fieldValues' => [
                    'text' => 'After existing component',
                    'element' => 'h2',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'test_placement_complex' => [
        'layout_type' => 'multi_region_non_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Above existing component"
                  element: "h2"
            - sdc.canvas_test_sdc.two_column:
                props:
                  width: 25
                slots:
                  column_two:
                    - sdc.canvas_test_sdc.druplicon: {}
                    - sdc.canvas_test_sdc.druplicon: {}
                    - sdc.canvas_test_sdc.druplicon: {}
            YAML, placement: 'above', reference_uuid: '72384115-a8ee-44bc-9a13-de1c7a4d9b96'),
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Below existing component"
                  element: "h2"
            YAML, placement: 'below', reference_uuid: '72384115-a8ee-44bc-9a13-de1c7a4d9b96'),
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML, target: 'header'),
        ],
        'expected_output' => [
          'operations' => [
            [
              'operation' => 'ADD',
              'components' => [
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [1, 0],
                  'fieldValues' => [
                    'text' => 'Above existing component',
                    'element' => 'h2',
                  ],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.two_column',
                  'nodePath' => [1, 1],
                  'fieldValues' => [
                    'width' => 25,
                  ],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.druplicon',
                  'nodePath' => [1, 1, 1, 0],
                  'fieldValues' => [],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.druplicon',
                  'nodePath' => [1, 1, 1, 1],
                  'fieldValues' => [],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.druplicon',
                  'nodePath' => [1, 1, 1, 2],
                  'fieldValues' => [],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [1, 3],
                  'fieldValues' => [
                    'text' => 'Below existing component',
                    'element' => 'h2',
                  ],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [0, 0],
                  'fieldValues' => [
                    'text' => 'Some text',
                    'element' => 'h1',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Provides different invalid placement test cases.
   *
   * @return array
   *   An array of test cases.
   */
  public static function invalidPlacementDataProvider(): array {
    return [
      'test_invalid_below_placement' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML, placement: 'below'),
        ],
        'expected_error' => [
          'Operation 0' => [
            'The reference_uuid must be provided for above/below placement.',
          ],
        ],
      ],
      'test_invalid_inside_placement' => [
        'layout_type' => 'multi_region_non_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML),
        ],
        'expected_error' => [
          'Operation 0' => [
            'The target content has "inside" placement specified, but it contains child components. Select any child component in the target and use "above" or "below" placement instead.',
          ],
        ],
      ],
      'test_missing_target' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          [
            'placement' => 'inside',
            'components' => <<<YAML
              - sdc.canvas_test_sdc.heading:
                  props:
                    text: "Some text"
                    element: "h1"
              YAML,
          ],
        ],
        'expected_error' => [
          'Operation 0' => [
            'The target key is missing in the operation.',
          ],
        ],
      ],
      'test_invalid_placement_value' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML, placement: 'invalid_placement'),
        ],
        'expected_error' => [
          'Operation 0' => [
            'The placement key is missing or invalid in the operation.',
          ],
        ],
      ],
      'test_inside_placement_with_reference_uuid' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML, reference_uuid: 'some-uuid-123'),
        ],
        'expected_error' => [
          'Operation 0' => [
            'The reference_uuid is not required for inside placement.',
          ],
        ],
      ],
      'test_empty_components' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation('[]'),
        ],
        'expected_error' => [
          'Operation 0' => [
            'The operation must contain components.',
          ],
        ],
      ],
      'test_components_not_a_list' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation('this is not a YAML list'),
        ],
        'expected_error' => [
          'Operation 0' => [
            'The components value must be a YAML list.',
          ],
        ],
      ],
      'test_unknown_target_region' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML, target: 'sidebar'),
        ],
        'expected_error' => [
          'Operation 0' => [
            'Region "sidebar" does not exist. Available regions are: header, content, footer.',
          ],
        ],
      ],
    ];
  }

  /**
   * Returns a predefined layout based on the type.
   *
   * @param string $type
   *   The type of layout to return.
   *
   * @return string
   *   The JSON-encoded layout.
   */
  private function getCurrentLayout(string $type): string {
    $layouts = [
      'multi_region_empty' => json_encode([
        'regions' => [
          'header' => [
            'nodePathPrefix' => [0],
            'components' => [],
          ],
          'content' => [
            'nodePathPrefix' => [1],
            'components' => [],
          ],
          'footer' => [
            'nodePathPrefix' => [2],
            'components' => [],
          ],
        ],
      ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      'multi_region_non_empty' => json_encode([
        'regions' => [
          'header' => [
            'nodePathPrefix' => [0],
            'components' => [],
          ],
          'content' => [
            'nodePathPrefix' => [1],
            'components' => [
              [
                'name' => 'sdc.canvas_test_sdc.heading',
                'uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96',
                'nodePath' => [1, 0],
              ],
            ],
          ],
          'footer' => [
            'nodePathPrefix' => [2],
            'components' => [],
          ],
        ],
      ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ];
    return $layouts[$type];
  }

}
