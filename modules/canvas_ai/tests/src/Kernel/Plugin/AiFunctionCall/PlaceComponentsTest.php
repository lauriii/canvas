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
use Drupal\Tests\canvas_ai\Traits\ImageMediaPropTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

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
  use ImageMediaPropTestTrait;
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
    'field',
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
    $this->setUpImageMediaType();
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
   * Tests that an invalid boolean or integer prop value is rejected.
   *
   * @see \Drupal\canvas_ai\AiResponseValidator::collectPrimitiveTypeViolations()
   */
  public function testInvalidPrimitiveTypeValueReportsError(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout('multi_region_empty'));

    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.shoe_badge:
            props:
              variant: "primary"
              pill: "maybe"
        - sdc.canvas_test_sdc.required-integer:
            props:
              count: "canvas"
        - sdc.canvas_test_sdc.shoe_badge:
            props:
              variant: "primary"
              pill: "false"
        YAML),
    ]);

    $normalized = self::normalizeErrorString($result);
    $this->assertStringStartsWith('Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component validation errors:', $normalized);
    // A boolean prop does not accept a string like `"maybe"`.
    $this->assertStringContainsString('components.0.[sdc.canvas_test_sdc.shoe_badge].props.pill: Component `sdc.canvas_test_sdc.shoe_badge`: the `pill` prop value "maybe" cannot be stored: expected a boolean (`true` or `false`).', $normalized);
    // An integer prop does not accept a string like `"canvas"`.
    $this->assertStringContainsString('components.1.[sdc.canvas_test_sdc.required-integer].props.count: Component `sdc.canvas_test_sdc.required-integer`: the `count` prop value "canvas" cannot be stored: expected an integer.', $normalized);
    // A boolean prop does not accept the string `"false"` either.
    $this->assertStringContainsString('components.2.[sdc.canvas_test_sdc.shoe_badge].props.pill: Component `sdc.canvas_test_sdc.shoe_badge`: the `pill` prop value "false" cannot be stored: expected a boolean (`true` or `false`).', $normalized);
  }

  /**
   * Tests placing components with invalid placement parameters.
   */
  #[DataProvider('invalidPlacementDataProvider')]
  public function testPlaceComponentsWithInvalidYaml(string $layout_type, array $operations, string $expected_error): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    // Set the current layout to a valid layout.
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout($layout_type));

    $result = $this->getComponentToolOutput($operations);
    $this->assertSame($expected_error, self::normalizeErrorString($result));
  }

  /**
   * Tests the error string the tool reports for invalid placements.
   */
  #[DataProvider('placementValidationErrorProvider')]
  public function testPlacementValidationErrors(string $layout_type, array $operations, string $expected_error): void {
    $this->createTestCodeComponent();
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout($layout_type));

    // A component list entry that is not a mapping of component ID to data
    // must fail instead of being silently skipped.
    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.druplicon: {}
        - 'just_a_string'
        YAML),
    ]);
    $this->assertSame('Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Entry 1 of the components list must be a mapping keyed by the component ID, with its props and slots under it.', self::normalizeErrorString($result));

    // The same entry three slot levels deep is reported at its full path.
    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.two_column:
            props:
              width: 50
            slots:
              column_one:
                - sdc.canvas_test_sdc.two_column:
                    props:
                      width: 50
                    slots:
                      column_two:
                        - 'just_a_string'
        YAML),
    ]);
    $this->assertSame('Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component validation errors: components.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.two_column].slots.column_two.0: Component entry "just_a_string" cannot be processed: it does not contain the component details in the expected YAML format. (code garbage)', self::normalizeErrorString($result));

    // A slot value that is not a list of component groups must fail instead
    // of being silently skipped.
    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.two_column:
            props:
              width: 50
            slots:
              column_one: 'not_a_list'
        YAML),
    ]);
    $this->assertSame('Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component validation errors: components.0.[sdc.canvas_test_sdc.two_column].slots.column_one: The `column_one` slot value "not_a_list" cannot be processed: a slot must hold a YAML list of components. (code garbage)', self::normalizeErrorString($result));

    // A `slots` value that is not a mapping of slot names to component lists
    // must fail instead of being silently skipped.
    $result = $this->getComponentToolOutput([
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.two_column:
            props:
              width: 50
            slots: 'not_a_mapping'
        YAML),
    ]);
    $this->assertSame('Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component validation errors: components.0.[sdc.canvas_test_sdc.two_column].slots: The `slots` value "not_a_mapping" cannot be processed: each slot name must be a key holding its own list of components. (code garbage)', self::normalizeErrorString($result));

    // Code components (JS source) resolve props the same way as SDCs, so a
    // prop the component does not define must fail for them too.
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout($layout_type));

    $tool = $this->functionCallManager->createInstance('canvas_ai:place_components');
    $this->assertInstanceOf(PlaceComponents::class, $tool);
    $tool->setContextValue('operations', $operations);
    $tool->execute();

    $this->assertSame($expected_error, self::normalizeErrorString($tool->getReadableOutput()));
    $this->assertSame([], $tool->getStructuredOutput());
  }

  /**
   * Tests the exact readable output text sent back to the agent.
   */
  public function testPredictedLayoutSentBackToAgent(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout('multi_region_empty'));

    $tool = $this->functionCallManager->createInstance('canvas_ai:place_components');
    $this->assertInstanceOf(PlaceComponents::class, $tool);
    $tool->setContextValue('operations', [
      self::buildOperation(<<<YAML
        - sdc.canvas_test_sdc.heading:
            props:
              text: "Some text"
              element: "h1"
        YAML),
    ]);
    $tool->execute();

    $assigned_uuids = self::collectOperationUuids($tool->getStructuredOutput());
    $this->assertCount(1, $assigned_uuids);
    $uuid = $assigned_uuids[0];

    $expected = <<<TEXT
      Components placed successfully.
      The placed components with their assigned UUIDs:
      operations:
        -
          target: content
          placement: inside
          reference_uuid: ''
          components:
            -
              sdc.canvas_test_sdc.heading:
                props:
                  text: 'Some text'
                  element: h1
                uuid: $uuid

      The expected page layout after placement (UUID tree):
      header: {  }
      content:
        $uuid: {  }
      footer: {  }

      These UUIDs are valid immediately — use them as reference_uuid for the next section in this same turn. The page layout you were given at the start of this turn does not list them yet and will only do so from your next turn, so for anything placed during this turn this result is authoritative and that layout is not. This is expected, not a sign that the layout is missing or that you should wait.

      This result is a continuation point, not a stopping point: if any section from your approved plan is still unplaced, your next output MUST be the next place_components call — a turn with text and no tool call would freeze the build here. Only once every planned section is on the page do you stop and write the closing confirmation.
      TEXT;

    $this->assertSame($expected, $tool->getReadableOutput());
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
    $this->assertSame('Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component with UUID "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee" not found in layout', self::normalizeErrorString($result));
  }

  /**
   * Tests that reference errors are accumulated with later operation errors.
   */
  public function testPlaceComponentsAccumulatesUnknownReferenceErrors(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout('multi_region_non_empty'));

    $tool = $this->functionCallManager->createInstance('canvas_ai:place_components');
    $this->assertInstanceOf(PlaceComponents::class, $tool);
    $tool->setContextValue('operations', [
      self::buildOperation('- sdc.canvas_test_sdc.heading: { props: { text: "First", element: "h1" } }', placement: 'below', reference_uuid: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'),
      self::buildOperation('- sdc.canvas_test_sdc.heading: { props: { text: "Second", element: "h1" } }', placement: 'below'),
    ]);
    $tool->execute();

    $this->assertSame('Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component with UUID "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee" not found in layout ## Operation 1 - The reference_uuid must be provided for above/below placement.', self::normalizeErrorString($tool->getReadableOutput()));
    $this->assertSame([], $tool->getStructuredOutput());
  }

  /**
   * Tests placing a media item the current user is not allowed to view.
   *
   * An image prop is populated by a reference to a media item, and Canvas
   * resolves that reference with the current user's access rights: an agent
   * must not be able to put media on the page for a user who may not see it.
   */
  public function testPlaceComponentsWithInaccessibleMedia(): void {
    $media = $this->createImageMedia();
    $components_yaml = <<<YAML
      - sdc.canvas_test_sdc.image:
          props:
            image: {$media->id()}
      YAML;

    // The first account ::setUp() creates is user 1, which bypasses access
    // checks: the very same media item raises no validation error for it.
    $this->assertSame(1, (int) $this->privilegedUser->id());
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout('multi_region_empty'));
    $result = $this->getComponentToolOutput([self::buildOperation($components_yaml)]);
    $this->assertStringStartsWith('Components placed successfully.', $result);

    // User A may use Canvas AI, but may not view the media item.
    $user_a = $this->createUserWithoutMediaAccess();
    $this->assertFalse($media->access('view', $user_a));
    $this->container->get(AccountProxyInterface::class)->setAccount($user_a);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout('multi_region_empty'));
    $result = $this->getComponentToolOutput([self::buildOperation($components_yaml)]);
    $this->assertSame(\sprintf('Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - %s', self::MEDIA_ACCESS_DENIED_MESSAGE), self::normalizeErrorString($result));
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
   * Data provider for invalid placement test cases.
   *
   * @return array
   *   An array of test cases.
   */
  public static function placementValidationErrorProvider(): array {
    return [
      'nested_component_missing_required_prop' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
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
                          cta2: 'Click it!'
            YAML),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component validation errors: components.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required.',
      ],
      'errors_on_two_of_three_top_level_components' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.two_column:
                props:
                  width: 50
                slots:
                  column_one:
                    - sdc.canvas_test_sdc.two_column:
                        props:
                          width: 33
                        slots:
                          column_one:
                            - sdc.canvas_test_sdc.heading:
                                props:
                                  text: 'A heading'
                                  element: 'h2'
                                  nonexistent_prop: 'Bogus'
            - sdc.canvas_test_sdc.props-no-slots:
                props:
                  heading: 'A valid heading'
            - sdc.canvas_test_sdc.heading:
                props:
                  text: 'Section title'
                  element: 'not-a-real-element'
            YAML),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component validation errors: components.2.[sdc.canvas_test_sdc.heading].props.element: Does not have a value in the enumeration ["div","h1","h2","h3","h4","h5","h6"]. The provided value is: "not-a-real-element". components.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.heading].props.nonexistent_prop: Component `sdc.canvas_test_sdc.heading`: the `nonexistent_prop` prop is not defined. (code garbage)',
      ],
      'errors_in_the_second_and_third_operations' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: 'A valid heading'
                  element: 'h1'
            YAML),
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.my-hero:
                props:
                  subheading: 'SubSnub'
                  cta1: 'View it!'
                  cta1href: 'https://example.com'
            - sdc.canvas_test_sdc.heading:
                props:
                  text: 'Section title'
                  element: 'not-a-real-element'
            YAML, target: 'header'),
          self::buildOperation(<<<YAML
            - js.test-code-component:
                props:
                  heading: 'A valid heading'
                  nonexistent_prop: 'This prop does not exist'
            YAML, target: 'footer'),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 1 - Component validation errors: components.0.[sdc.canvas_test_sdc.my-hero].props.heading: The property heading is required. components.1.[sdc.canvas_test_sdc.heading].props.element: Does not have a value in the enumeration ["div","h1","h2","h3","h4","h5","h6"]. The provided value is: "not-a-real-element". ## Operation 2 - Component validation errors: components.0.[js.test-code-component].props.nonexistent_prop: Component `js.test-code-component`: the `nonexistent_prop` prop is not defined. (code garbage)',
      ],
      'unparseable_yaml_and_invalid_placement_in_one_operation' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: 2233-33-33
            YAML, placement: 'sideways'),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - The placement key is missing or invalid in the operation. - The components value is not valid YAML: The date "2233-33-33" could not be parsed as it is an invalid date (near "text: 2233-33-33"). Rewrite it with every string value quoted — unquoted dash-separated values such as 2233-33-33 are read as invalid dates, and HTML or multi-line text must be quoted too.',
      ],
      'a_different_error_kind_in_each_operation' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: 2233-33-33
            YAML),
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.my-hero:
                props:
                  heading: 'My Hero'
            YAML, target: 'header'),
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: 'Some text'
                  element: 'h2'
            YAML, target: 'footer', placement: 'below'),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - The components value is not valid YAML: The date "2233-33-33" could not be parsed as it is an invalid date (near "text: 2233-33-33"). Rewrite it with every string value quoted — unquoted dash-separated values such as 2233-33-33 are read as invalid dates, and HTML or multi-line text must be quoted too. ## Operation 1 - Component validation errors: components.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required. ## Operation 2 - The reference_uuid must be provided for above/below placement.',
      ],
      'nonexistent_component_ids' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - invalid.component.id:
                props:
                  title: 'Invalid Component'
            - sdc.canvas_test_sdc.two_column:
                props:
                  width: 50
                slots:
                  column_one:
                    - sdc.canvas_test_sdc.invalid_component:
                        props:
                          heading: 'My Hero'
            YAML),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component validation errors: components.0.[invalid.component.id]: The \'canvas.component.invalid.component.id\' config does not exist. components.1.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.invalid_component]: The \'canvas.component.sdc.canvas_test_sdc.invalid_component\' config does not exist.',
      ],
      'component_without_a_props_key' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation('- sdc.canvas_test_sdc.my-hero: {}'),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component validation errors: components.0.[sdc.canvas_test_sdc.my-hero].props.heading: The property heading is required. components.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required.',
      ],
      'invalid_slot_name' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.two_column:
                props:
                  width: 50
                slots:
                  not_real_slot:
                    - sdc.canvas_test_sdc.heading:
                        props:
                          text: 'Some text'
                          element: 'h2'
            YAML),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component validation errors: components.0.[sdc.canvas_test_sdc.two_column]: Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc.canvas_test_sdc.two_column</em>: <em class="placeholder">not_real_slot</em>. Valid slot names are: <em class="placeholder">column_one, column_two</em>.',
      ],
      'props_a_component_does_not_define' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.druplicon:
                props:
                  heading: 'Druplicon defines no props'
            - sdc.canvas_test_sdc.druplicon:
                props: 'heading: Not a mapping'
            - sdc.canvas_test_sdc.props-no-slots:
                props:
                  nonexistent_prop: 'This prop does not exist'
            YAML),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Component validation errors: components.2.[sdc.canvas_test_sdc.props-no-slots].props.heading: The property heading is required. components.0.[sdc.canvas_test_sdc.druplicon].props.heading: Component `sdc.canvas_test_sdc.druplicon`: the `heading` prop is not defined. (code garbage) components.1.[sdc.canvas_test_sdc.druplicon].props: Component `sdc.canvas_test_sdc.druplicon`: the props must be a mapping of prop names to values. (code garbage) components.2.[sdc.canvas_test_sdc.props-no-slots].props.nonexistent_prop: Component `sdc.canvas_test_sdc.props-no-slots`: the `nonexistent_prop` prop is not defined. (code garbage)',
      ],
    ];
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
      'test_placement_inside_empty_slot' => [
        'layout_type' => 'multi_region_with_slots',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "In the empty column"
                  element: "h2"
            YAML, target: '2f957795-e30a-46a0-acfe-868adc0685bf/column_one'),
        ],
        'expected_output' => [
          'operations' => [
            [
              'operation' => 'ADD',
              'components' => [
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [1, 0, 0, 0],
                  'fieldValues' => [
                    'text' => 'In the empty column',
                    'element' => 'h2',
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
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - The reference_uuid must be provided for above/below placement.',
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
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - The target content has "inside" placement specified, but it contains child components. Select any child component in the target and use "above" or "below" placement instead.',
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
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - The target key is missing in the operation.',
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
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - The placement key is missing or invalid in the operation.',
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
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - The reference_uuid is not required for inside placement.',
      ],
      'test_empty_components' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation('[]'),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - The operation must contain components.',
      ],
      'test_components_not_a_list' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation('this is not a YAML list'),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - The components value must be a YAML list.',
      ],
      // The model sent the list as JSON instead of as a YAML string.
      'test_components_not_a_string' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          [
            'target' => 'content',
            'placement' => 'inside',
            'components' => [
              ['sdc.canvas_test_sdc.heading' => ['props' => ['text' => 'Some text', 'element' => 'h1']]],
            ],
          ],
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - The components value must be a string containing a YAML list.',
      ],
      // Bare component IDs parse as strings, not mappings.
      'test_component_list_items_not_mappings' => [
        'layout_type' => 'multi_region_empty',
        'operations' => [
          self::buildOperation("- sdc.canvas_test_sdc.heading\n- sdc.canvas_test_sdc.druplicon: {}\n- sdc.canvas_test_sdc.druplicon"),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Entry 0 of the components list must be a mapping keyed by the component ID, with its props and slots under it. - Entry 2 of the components list must be a mapping keyed by the component ID, with its props and slots under it.',
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
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Region "sidebar" does not exist. Available regions are: header, content, footer.',
      ],
      'test_unknown_slot_parent' => [
        'layout_type' => 'multi_region_with_slots',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML, target: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/column_one'),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Invalid slot "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/column_one". Component with UUID "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee" not found in layout',
      ],
      'test_unknown_slot_name' => [
        'layout_type' => 'multi_region_with_slots',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML, target: '2f957795-e30a-46a0-acfe-868adc0685bf/column_three'),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Slot "column_three" does not exist on component "2f957795-e30a-46a0-acfe-868adc0685bf".',
      ],
      // The druplicon is a child of column_two and has no slots of its own.
      'test_child_component_as_slot_parent' => [
        'layout_type' => 'multi_region_with_slots',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML, target: '4e45ef4c-501c-4612-b02b-1911e88a4592/column_one'),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - Slot "column_one" does not exist on component "4e45ef4c-501c-4612-b02b-1911e88a4592".',
      ],
      'test_inside_slot_with_children' => [
        'layout_type' => 'multi_region_with_slots',
        'operations' => [
          self::buildOperation(<<<YAML
            - sdc.canvas_test_sdc.heading:
                props:
                  text: "Some text"
                  element: "h1"
            YAML, target: '2f957795-e30a-46a0-acfe-868adc0685bf/column_two'),
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Operation 0 - The target 2f957795-e30a-46a0-acfe-868adc0685bf/column_two has "inside" placement specified, but it contains child components. Select any child component in the target and use "above" or "below" placement instead.',
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
      // A two_column component whose column_one slot is empty and whose
      // column_two slot holds a druplicon.
      'multi_region_with_slots' => json_encode([
        'regions' => [
          'header' => [
            'nodePathPrefix' => [0],
            'components' => [],
          ],
          'content' => [
            'nodePathPrefix' => [1],
            'components' => [
              [
                'name' => 'sdc.canvas_test_sdc.two_column',
                'uuid' => '2f957795-e30a-46a0-acfe-868adc0685bf',
                'slots' => [
                  '2f957795-e30a-46a0-acfe-868adc0685bf/column_one' => [
                    'components' => [],
                  ],
                  '2f957795-e30a-46a0-acfe-868adc0685bf/column_two' => [
                    'components' => [
                      [
                        'name' => 'sdc.canvas_test_sdc.druplicon',
                        'uuid' => '4e45ef4c-501c-4612-b02b-1911e88a4592',
                      ],
                    ],
                  ],
                ],
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
