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
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for the EditComponents function call plugin.
 */
#[Group('canvas_ai')]
final class EditComponentsTest extends CanvasKernelTestBase {

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
   * Tests that a record missing component_uuid or props is caught by the tool.
   *
   * The ComplexToolItems schema does not validate a record's child fields, so
   * context validation passes for both; the tool's own guard is what rejects
   * them (and, for missing props, prevents an uncaught Yaml::parse TypeError).
   */
  public function testEditMalformedRecordReportsError(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);

    $cases = [
      'missing component_uuid' => [['props' => 'text: "x"']],
      'missing props' => [['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96']],
    ];
    foreach ($cases as $case => $edits) {
      $tool = $this->functionCallManager->createInstance('canvas_ai:edit_components');
      $this->assertInstanceOf(EditComponents::class, $tool);
      $tool->setContextValue('component_edits', $edits);
      $this->assertCount(0, $tool->validateContexts(), $case);
      $tool->execute();
      $this->assertSame('Failed to edit components: Each edit must provide a "component_uuid" and its prop changes.', self::normalizeErrorString($tool->getReadableOutput()), $case);
    }
  }

  /**
   * Tests that a props value that is not a mapping is rejected.
   *
   * A bare string parses as valid YAML, and the tool used to forward it as
   * the component's updates, which the client then spread into the model one
   * character at a time.
   */
  public function testScalarPropsValueReportsError(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout());

    $edits = [['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96', 'props' => 'just a string']];
    $result = $this->getToolOutput('canvas_ai:edit_components', ['component_edits' => $edits]);
    $this->assertSame('Failed to edit components: The props value for component 72384115-a8ee-44bc-9a13-de1c7a4d9b96 must be a YAML mapping of prop names to values, one "prop_name: value" pair per line.', self::normalizeErrorString($result));
  }

  /**
   * Tests that editing a UUID absent from the page is reported as an error.
   */
  public function testEditUnknownUuidReportsError(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout());

    $edits = [
      [
        'component_uuid' => 'defd2f6c-f27d-422b-b397-b793df89d922',
        'props' => 'text: "Does not matter"',
      ],
    ];

    $result = $this->getToolOutput('canvas_ai:edit_components', ['component_edits' => $edits]);
    $this->assertSame('Failed to edit components: Component defd2f6c-f27d-422b-b397-b793df89d922 was not found on the page.', self::normalizeErrorString($result));
  }

  /**
   * Tests that unparseable props YAML produces an instructive error.
   *
   * Unquoted date-like values such as 2233-33-33 make Symfony YAML throw a
   * cryptic invalid-date ParseException; the tool must tell the model to
   * quote string values instead of echoing the raw parse error.
   */
  public function testUnparseablePropsYamlGetsInstructiveError(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout());

    $edits = [
      [
        'component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96',
        'props' => 'text: 2233-33-33',
      ],
    ];
    $result = $this->getToolOutput('canvas_ai:edit_components', ['component_edits' => $edits]);

    $normalized = self::normalizeErrorString($result);
    $this->assertStringStartsWith('Failed to edit components:', $normalized);
    $this->assertStringContainsString('The props value is not valid YAML:', $normalized);
    $this->assertStringContainsString('Rewrite it with every string value quoted', $normalized);
  }

  /**
   * Tests that editing a component runs its prop values through the validator.
   *
   * An undefined prop name and an out-of-enum value both reach the shared
   * response validator, which rejects each with a precise message.
   */
  public function testEditComponentValidationTriggers(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->container->get(CanvasAiTempStore::class)->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout());

    // 'style' is an enum prop on the heading component (primary|secondary).
    $cases = [
      'undefined prop' => [
        'props' => 'nonexistent_prop: "value"',
        'expected' => 'Failed to edit components: Component validation errors: components.0.[sdc.canvas_test_sdc.heading].props.nonexistent_prop: Component `sdc.canvas_test_sdc.heading`: the `nonexistent_prop` prop is not defined. (code garbage)',
      ],
      'out-of-enum value' => [
        'props' => 'style: "flashy"',
        'expected' => 'Failed to edit components: Component validation errors: components.0.[sdc.canvas_test_sdc.heading].props.style: Does not have a value in the enumeration ["primary","secondary"]. The provided value is: "flashy".',
      ],
    ];
    foreach ($cases as $case => $data) {
      $edits = [['component_uuid' => '72384115-a8ee-44bc-9a13-de1c7a4d9b96', 'props' => $data['props']]];
      $result = $this->getToolOutput('canvas_ai:edit_components', ['component_edits' => $edits]);
      $this->assertSame($data['expected'], self::normalizeErrorString($result), $case);
    }
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
