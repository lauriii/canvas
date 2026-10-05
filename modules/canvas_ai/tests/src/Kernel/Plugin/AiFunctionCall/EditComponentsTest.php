<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai\Plugin\AiFunctionCall\EditComponents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas_ai\Traits\FunctionalCallTestTrait;
use Drupal\Tests\canvas_ai\Traits\ImageMediaPropTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for the EditComponents function call plugin.
 */
#[Group('canvas_ai')]
final class EditComponentsTest extends CanvasKernelTestBase {

  use FunctionalCallTestTrait;
  use ImageMediaPropTestTrait;
  use UserCreationTrait;

  /**
   * The UUID of the image component in ::getLayoutWithImageComponent().
   */
  private const IMAGE_COMPONENT_UUID = 'b3e4a0a2-6f4b-4a2e-9f5c-1d6f0c6b9a11';

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
    $this->installEntitySchema('file');
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
   * Tests valid prop edits applied to several components in one call.
   */
  public function testEditExistingComponentProps(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout());

    $edits = [
      [
        'component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96',
        'props' => 'text: "Updated heading"',
      ],
      [
        'component_uuid' => '43bb2ace-34cf-42d6-b43b-86d665309290',
        'props' => "heading: \"Updated hero\"\ncta1: \"Learn more\"",
      ],
    ];

    $tool = $this->functionCallManager->createInstance('canvas_ai:edit_components');
    $this->assertInstanceOf(EditComponents::class, $tool);
    $tool->setContextValue('component_edits', $edits);
    $tool->execute();

    self::assertEquals([
      'component_updates' => [
        '72384115-a8ee-44bc-9a13-de1c7a4d9b96' => ['text' => 'Updated heading'],
        '43bb2ace-34cf-42d6-b43b-86d665309290' => ['heading' => 'Updated hero', 'cta1' => 'Learn more'],
      ],
    ], $tool->getStructuredOutput());
    $this->assertStringContainsString('The updates were applied successfully.', $tool->getReadableOutput());
  }

  /**
   * Tests that an empty edits list is rejected by context validation.
   *
   * The empty/absent case is enforced by the context-definition schema
   * (validateContexts), not the tool, so that seam is asserted directly.
   */
  public function testEmptyEditsListFailsContextValidation(): void {
    $tool = $this->functionCallManager->createInstance('canvas_ai:edit_components');
    $this->assertInstanceOf(EditComponents::class, $tool);
    $tool->setContextValue('component_edits', []);
    $this->assertGreaterThan(0, $tool->validateContexts()->count());
  }

  /**
   * Tests edit records with a malformed shape, checked before any component lookup.
   */
  #[DataProvider('malformedEditDataProvider')]
  public function testEditComponentsWithMalformedEdits(array $edits, string $expected_error): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout());

    $result = $this->getToolOutput('canvas_ai:edit_components', ['component_edits' => $edits]);
    $this->assertSame($expected_error, self::normalizeErrorString($result));
  }

  /**
   * Tests the error string the tool reports for edits that fail validation.
   */
  #[DataProvider('editValidationErrorProvider')]
  public function testEditComponentsValidationErrors(array $edits, string $expected_error): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout());

    $tool = $this->functionCallManager->createInstance('canvas_ai:edit_components');
    $this->assertInstanceOf(EditComponents::class, $tool);
    $tool->setContextValue('component_edits', $edits);
    $tool->execute();

    $this->assertSame($expected_error, self::normalizeErrorString($tool->getReadableOutput()));
    $this->assertSame([], $tool->getStructuredOutput());
  }

  /**
   * Tests that an invalid boolean or integer prop value is rejected.
   *
   * `BooleanData::getCastedValue()` casts any non-empty string to `TRUE`,
   * and `IntegerData::getCastedValue()` casts any non-numeric string to `0`,
   * so silently letting an unrecognized value like `"maybe"` or `"canvas"`
   * through would store the wrong value with no error anywhere.
   *
   * @see \Drupal\canvas_ai\AiResponseValidator::collectPrimitiveTypeViolations()
   */
  public function testEditComponentInvalidPrimitiveTypeValueReportsError(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, json_encode([
      'regions' => [
        'content' => [
          'nodePathPrefix' => [0],
          'components' => [
            [
              'name' => 'sdc.canvas_test_sdc.shoe_badge',
              'uuid' => '1f7f5b2b-3b34-4a1a-9b8a-8f7c9a6d5e21',
              'props' => ['variant' => 'primary'],
            ],
            [
              'name' => 'sdc.canvas_test_sdc.required-integer',
              'uuid' => '2a6b1c3d-4e5f-4a7b-8c9d-0e1f2a3b4c5d',
              'props' => ['count' => 42],
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    $edits = [
      ['component_uuid' => '1f7f5b2b-3b34-4a1a-9b8a-8f7c9a6d5e21', 'props' => 'pill: "maybe"'],
      ['component_uuid' => '2a6b1c3d-4e5f-4a7b-8c9d-0e1f2a3b4c5d', 'props' => 'count: "canvas"'],
    ];
    $result = $this->getToolOutput('canvas_ai:edit_components', ['component_edits' => $edits]);
    $normalized = self::normalizeErrorString($result);
    $this->assertStringStartsWith('Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - Component validation errors:', $normalized);
    $this->assertStringContainsString('components.0.[sdc.canvas_test_sdc.shoe_badge].props.pill: Component `sdc.canvas_test_sdc.shoe_badge`: the `pill` prop value "maybe" cannot be stored: expected a boolean (`true` or `false`).', $normalized);
    $this->assertStringContainsString('components.0.[sdc.canvas_test_sdc.required-integer].props.count: Component `sdc.canvas_test_sdc.required-integer`: the `count` prop value "canvas" cannot be stored: expected an integer.', $normalized);
  }

  /**
   * Tests that editing without proper permissions throws.
   */
  public function testEditComponentsWithoutPermissions(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->unprivilegedUser);

    $tool = $this->functionCallManager->createInstance('canvas_ai:edit_components');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');

    $tool->setContextValue('component_edits', [['component_uuid' => 'test', 'props' => 'text: value']]);
    $tool->execute();
  }

  /**
   * Tests editing a component to use a media item the user cannot view.
   *
   * An image prop is populated by a reference to a media item, and Canvas
   * resolves that reference with the current user's access rights: an agent
   * must not be able to put media on the page for a user who may not see it.
   */
  public function testEditComponentsWithInaccessibleMedia(): void {
    $media = $this->createImageMedia();
    $edits = [
      [
        'component_uuid' => self::IMAGE_COMPONENT_UUID,
        'props' => 'image: ' . $media->id(),
      ],
    ];

    // The first account ::setUp() creates is user 1, which bypasses access
    // checks: the very same media item raises no validation error for it.
    $this->assertSame(1, (int) $this->privilegedUser->id());
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getLayoutWithImageComponent());
    $result = $this->getToolOutput('canvas_ai:edit_components', ['component_edits' => $edits]);
    $this->assertStringContainsString('The updates were applied successfully.', $result);

    // User A may use Canvas AI, but may not view the media item.
    $user_a = $this->createUserWithoutMediaAccess();
    $this->assertFalse($media->access('view', $user_a));
    $this->container->get(AccountProxyInterface::class)->setAccount($user_a);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getLayoutWithImageComponent());
    $result = $this->getToolOutput('canvas_ai:edit_components', ['component_edits' => $edits]);
    $this->assertSame(\sprintf('Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - %s', self::MEDIA_ACCESS_DENIED_MESSAGE), self::normalizeErrorString($result));
  }

  /**
   * Returns a layout with a single image component in the content region.
   *
   * @return string
   *   The JSON-encoded layout.
   */
  private function getLayoutWithImageComponent(): string {
    return json_encode([
      'regions' => [
        'content' => [
          'nodePathPrefix' => [0],
          'components' => [
            [
              'name' => 'sdc.canvas_test_sdc.image',
              'uuid' => self::IMAGE_COMPONENT_UUID,
              'props' => [],
            ],
          ],
        ],
      ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * Data provider for edit records with a malformed shape.
   *
   * @return array
   *   An array of test cases.
   */
  public static function malformedEditDataProvider(): array {
    return [
      'missing_component_uuid' => [
        'edits' => [['props' => 'text: "x"']],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - The component_uuid key is missing in the edit.',
      ],
      'missing_props' => [
        'edits' => [['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96']],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - The props value must be a YAML mapping of prop names to values, one "prop_name: value" pair per line.',
      ],
      'empty_props_map' => [
        'edits' => [['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96', 'props' => '{}']],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - The edit must contain at least one prop change.',
      ],
      // The model sent the mapping as JSON instead of as a YAML string.
      'props_not_a_string' => [
        'edits' => [['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96', 'props' => ['text' => 'x']]],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - The props value must be a string containing a YAML mapping of prop names to values.',
      ],
      'scalar_props' => [
        'edits' => [['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96', 'props' => 'just a string']],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - The props value must be a YAML mapping of prop names to values, one "prop_name: value" pair per line.',
      ],
      'unparseable_yaml' => [
        'edits' => [['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96', 'props' => 'text: 2233-33-33']],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - The props value is not valid YAML: The date "2233-33-33" could not be parsed as it is an invalid date (near "text: 2233-33-33"). Rewrite it with every string value quoted — unquoted dash-separated values such as 2233-33-33 are read as invalid dates, and HTML or multi-line text must be quoted too.',
      ],
      'component_uuid_and_props_both_missing' => [
        'edits' => [[]],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - The component_uuid key is missing in the edit. - The props value must be a YAML mapping of prop names to values, one "prop_name: value" pair per line.',
      ],
    ];
  }

  /**
   * Data provider for edits that fail component-structure validation.
   *
   * @return array
   *   An array of test cases.
   */
  public static function editValidationErrorProvider(): array {
    return [
      'unknown_uuid' => [
        'edits' => [
          ['component_uuid' => 'defd2f6c-f27d-422b-b397-b793df89d922', 'props' => 'text: "Does not matter"'],
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - Component defd2f6c-f27d-422b-b397-b793df89d922 was not found on the page.',
      ],
      'undefined_prop' => [
        'edits' => [
          ['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96', 'props' => 'nonexistent_prop: "value"'],
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - Component validation errors: components.0.[sdc.canvas_test_sdc.heading].props.nonexistent_prop: Component `sdc.canvas_test_sdc.heading`: the `nonexistent_prop` prop is not defined. (code garbage)',
      ],
      'out_of_enum_value' => [
        'edits' => [
          ['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96', 'props' => 'style: "flashy"'],
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - Component validation errors: components.0.[sdc.canvas_test_sdc.heading].props.style: Does not have a value in the enumeration ["primary","secondary"]. The provided value is: "flashy".',
      ],
      'error_in_first_edit_valid_second' => [
        'edits' => [
          ['component_uuid' => 'defd2f6c-f27d-422b-b397-b793df89d922', 'props' => 'text: "Does not matter"'],
          ['component_uuid' => '43bb2ace-34cf-42d6-b43b-86d665309290', 'props' => 'heading: "Updated hero"'],
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - Component defd2f6c-f27d-422b-b397-b793df89d922 was not found on the page.',
      ],
      'valid_first_edit_error_second' => [
        'edits' => [
          ['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96', 'props' => 'text: "Updated heading"'],
          ['component_uuid' => '43bb2ace-34cf-42d6-b43b-86d665309290', 'props' => 'nonexistent_prop: "value"'],
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 1 - Component validation errors: components.0.[sdc.canvas_test_sdc.my-hero].props.nonexistent_prop: Component `sdc.canvas_test_sdc.my-hero`: the `nonexistent_prop` prop is not defined. (code garbage)',
      ],
      'a_different_error_kind_in_each_edit' => [
        'edits' => [
          ['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96', 'props' => 'text: 2233-33-33'],
          ['component_uuid' => '43bb2ace-34cf-42d6-b43b-86d665309290', 'props' => 'nonexistent_prop: "value"'],
          ['component_uuid' => 'defd2f6c-f27d-422b-b397-b793df89d922', 'props' => 'text: "Does not matter"'],
        ],
        'expected_error' => 'Nothing was applied. Fix every error listed below and call the tool again. ## Edit 0 - The props value is not valid YAML: The date "2233-33-33" could not be parsed as it is an invalid date (near "text: 2233-33-33"). Rewrite it with every string value quoted — unquoted dash-separated values such as 2233-33-33 are read as invalid dates, and HTML or multi-line text must be quoted too. ## Edit 1 - Component validation errors: components.0.[sdc.canvas_test_sdc.my-hero].props.nonexistent_prop: Component `sdc.canvas_test_sdc.my-hero`: the `nonexistent_prop` prop is not defined. (code garbage) ## Edit 2 - Component defd2f6c-f27d-422b-b397-b793df89d922 was not found on the page.',
      ],
    ];
  }

  /**
   * Returns a layout with a heading and a hero component in the content region.
   *
   * @return string
   *   The JSON-encoded layout.
   */
  private function getCurrentLayout(): string {
    return json_encode([
      'regions' => [
        'content' => [
          'nodePathPrefix' => [0],
          'components' => [
            [
              'name' => 'sdc.canvas_test_sdc.heading',
              'uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96',
              'props' => [
                'text' => 'Original heading',
                'element' => 'h2',
              ],
            ],
            [
              'name' => 'sdc.canvas_test_sdc.my-hero',
              'uuid' => '43bb2ace-34cf-42d6-b43b-86d665309290',
              'props' => [
                'heading' => 'Original hero',
                'cta1href' => '/original',
              ],
            ],
          ],
        ],
      ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

}
