<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_ai\Plugin\AiFunctionCall\SetAIGeneratedComponentStructure;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\canvas_ai\Traits\FunctionalCallTestTrait;
use Drupal\user\Entity\User;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the SetAIGeneratedComponentStructure function call plugin.
 *
 * @group canvas_ai
 */
final class SetAIGeneratedComponentStructureTest extends KernelTestBase {

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
    'canvas',
    'media',
    'system',
    'user',
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    $privileged_user = $this->createUser([CanvasAiPermissions::USE_CANVAS_AI]);
    $unprivileged_user = $this->createUser();
    if (!$privileged_user instanceof User || !$unprivileged_user instanceof User) {
      throw new \Exception('Failed to create test users');
    }
    $this->privilegedUser = $privileged_user;
    $this->unprivilegedUser = $unprivileged_user;
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_sdc']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')
      ->getEditable('system.theme')
      ->set('default', 'stark')
      ->save();
  }

  /**
   * Tests setting component structure with proper permissions and valid data.
   *
   * @dataProvider componentStructureDataProvider
   */
  public function testSetComponentStructureWithPermissionsAndValidData(string $layout_type, string $yaml_input, array $expected_output): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);
    // Set the current layout to a valid layout.
    $this->container->get('canvas_ai.tempstore')->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout($layout_type));

    $tool = $this->functionCallManager->createInstance('canvas_ai:set_component_structure');
    $this->assertInstanceOf(SetAIGeneratedComponentStructure::class, $tool);
    $tool->setContextValue('component_structure', $yaml_input);
    $tool->execute();
    self::assertEquals($expected_output, $tool->getStructuredOutput());
  }

  /**
   * Tests setting component structure without proper permissions.
   */
  public function testSetComponentStructureWithoutPermissions(): void {
    $this->container->get('current_user')->setAccount($this->unprivilegedUser);

    $tool = $this->functionCallManager->createInstance('canvas_ai:set_component_structure');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    // Expect an exception to be thrown.
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');

    $tool->setContextValue('component_structure', 'test: value');
    $tool->execute();
  }

  /**
   * Tests setting component structure with invalid YAML.
   *
   * @dataProvider invalidComponentStructureDataProvider
   */
  public function testSetComponentStructureWithInvalidYaml(string $layout_type, string $yaml_input, array $expected_error): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);
    // Set the current layout to a valid layout.
    $this->container->get('canvas_ai.tempstore')->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout($layout_type));

    $result = $this->getComponentToolOutput($yaml_input);
    $expected_error = 'Failed to process layout data: ' . Yaml::dump($expected_error);
    $this->assertStringContainsString($expected_error, $result);
  }

  /**
   * Tests setting component structure with invalid component validation.
   */
  public function testSetComponentStructureWithInvalidComponents(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);
    // Set the current layout to a valid layout.
    $this->container->get('canvas_ai.tempstore')->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $this->getCurrentLayout('multi_region_empty'));

    $valid_yaml = <<<YAML
      operations:
        - target: 'content'
          reference_uuid: ''
          placement: 'inside'
          components:
          - invalid.component.id:
              props:
                title: 'Invalid Component'
      YAML;

    $result = $this->getComponentToolOutput($valid_yaml);
    $this->assertSame("Failed to process layout data: Component validation errors: components.0.[invalid.component.id]: The 'canvas.component.invalid.component.id' config does not exist.", self::normalizeErrorString($result));

    $invalid_nested_component = <<<YAML
      operations:
        - target: 'content'
          reference_uuid: ''
          placement: 'inside'
          components:
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
      YAML;
    $result = $this->getComponentToolOutput($invalid_nested_component);
    $this->assertSame("Failed to process layout data: Component validation errors: components.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.invalid_component]: The 'canvas.component.sdc.canvas_test_sdc.invalid_component' config does not exist.", self::normalizeErrorString($result));
  }

  /**
   * Tests component validation logic.
   */
  public function testValidateComponent(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $invalid_yaml = <<<YAML
      operations:
        - target: 'content'
          reference_uuid: ''
          placement: 'inside'
          components:
            - sdc.canvas_test_sdc.my-hero:
                props:
                  subheading: 'SubSnub'
                  cta1: 'View it!'
                  cta1href: 'https://canvas-example.com'
                  cta2: 'Click it!'
      YAML;

    $result = $this->getComponentToolOutput($invalid_yaml);
    $this->assertSame("Failed to process layout data: Component validation errors: components.0.[sdc.canvas_test_sdc.my-hero].props.heading: The property heading is required.", self::normalizeErrorString($result));
    // Ensure we gracefully 'props' not being set.
    $decoded = Yaml::parse($invalid_yaml);
    unset($decoded['operations'][0]['components'][0]['sdc.canvas_test_sdc.my-hero']['props']);
    $result = $this->getComponentToolOutput(Yaml::dump($decoded));
    $this->assertSame('Failed to process layout data: Component validation errors: components.0.[sdc.canvas_test_sdc.my-hero].props.heading: The property heading is required. components.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required.', self::normalizeErrorString($result));

    $invalid_nested_yaml = <<<YAML
operations:
  - target: 'content'
    reference_uuid: ''
    placement: 'inside'
    components:
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
    $result = $this->getComponentToolOutput($invalid_nested_yaml);
    $this->assertSame('Failed to process layout data: Component validation errors: components.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required.', self::normalizeErrorString($result));

    // Ensure we error on invalid slot names.
    $decoded = Yaml::parse($invalid_nested_yaml);
    $decoded['operations'][0]['components'][0]['sdc.canvas_test_sdc.two_column']['slots']['not_real_slot'] = $decoded['operations'][0]['components'][0]['sdc.canvas_test_sdc.two_column']['slots']['column_one'];
    $invalid_slot_name_yaml = Yaml::dump($decoded);
    $result = $this->getComponentToolOutput($invalid_slot_name_yaml);
    $this->assertSame('Failed to process layout data: Component validation errors: components.0.[sdc.canvas_test_sdc.two_column]: Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc.canvas_test_sdc.two_column</em>: <em class="placeholder">not_real_slot</em>. Valid slot names are: <em class="placeholder">column_one, column_two</em>. components.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required. components.0.[sdc.canvas_test_sdc.two_column].slots.not_real_slot.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required.', self::normalizeErrorString($result));
  }

  private function getComponentToolOutput(string $yaml): string {
    return $this->getToolOutput('canvas_ai:set_component_structure', ['component_structure' => $yaml]);
  }

  /**
   * Data provider for component structure test cases.
   *
   * @return array
   */
  public static function componentStructureDataProvider(): array {
    return [
      'test_placement_inside_single' => [
        'layout_type' => 'multi_region_empty',
        'yaml_input' => <<<YAML
          operations:
            - target: 'content'
              reference_uuid: ''
              placement: 'inside'
              components:
                - sdc.canvas_test_sdc.heading:
                    props:
                      text: "Some text"
                      element: "h1"
          YAML,
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
        'yaml_input' => <<<YAML
          operations:
            - target: 'content'
              reference_uuid: ''
              placement: 'inside'
              components:
                - sdc.canvas_test_sdc.heading:
                    props:
                      text: "Some text"
                      element: "h1"
            - target: 'footer'
              reference_uuid: ''
              placement: 'inside'
              components:
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
          YAML,
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
        'yaml_input' => <<<YAML
          operations:
            - target: 'content'
              reference_uuid: '72384115-a8ee-44bc-9a13-de1c7a4d9b96'
              placement: 'below'
              components:
                - sdc.canvas_test_sdc.heading:
                    props:
                      text: "After existing component"
                      element: "h2"
          YAML,
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
        'yaml_input' => <<<YAML
          operations:
            - target: 'content'
              reference_uuid: '72384115-a8ee-44bc-9a13-de1c7a4d9b96'
              placement: 'above'
              components:
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
            - target: 'content'
              reference_uuid: '72384115-a8ee-44bc-9a13-de1c7a4d9b96'
              placement: 'below'
              components:
                - sdc.canvas_test_sdc.heading:
                    props:
                      text: "Below existing component"
                      element: "h2"
            - target: 'header'
              reference_uuid: ''
              placement: 'inside'
              components:
                - sdc.canvas_test_sdc.heading:
                    props:
                      text: "Some text"
                      element: "h1"
          YAML,
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
   * Provides different invalid component structure test cases.
   *
   * @return array
   *   An array of test cases.
   */
  public static function invalidComponentStructureDataProvider(): array {
    return [
      'test_invalid_below_placement' => [
        'layout_type' => 'multi_region_empty',
        'yaml_input' => <<<YAML
          operations:
            - target: 'content'
              reference_uuid: ''
              placement: 'below'
              components:
                - sdc.canvas_test_sdc.heading:
                    props:
                      text: "Some text"
                      element: "h1"
          YAML,
        'expected_error' => [
          'Operation 0' => [
            'The reference_uuid must be provided for above/below placement.',
          ],
        ],
      ],
      'test_invalid_inside_placement' => [
        'layout_type' => 'multi_region_non_empty',
        'yaml_input' => <<<YAML
          operations:
            - target: 'content'
              reference_uuid: ''
              placement: 'inside'
              components:
                - sdc.canvas_test_sdc.heading:
                    props:
                      text: "Some text"
                      element: "h1"
          YAML,
        'expected_error' => [
          'Operation 0' => [
            'The target content has "inside" placement specified, but it contains child components. Select any child component in the target and use "above" or "below" placement instead.',
          ],
        ],
      ],
      'test_invalid_placement_value' => [
        'layout_type' => 'multi_region_empty',
        'yaml_input' => <<<YAML
          operations:
            - target: 'content'
              reference_uuid: ''
              placement: 'invalid_placement'
              components:
                - sdc.canvas_test_sdc.heading:
                    props:
                      text: "Some text"
                      element: "h1"
          YAML,
        'expected_error' => [
          'Operation 0' => [
            'The placement key is missing or invalid in the operation.',
          ],
        ],
      ],
      'test_inside_placement_with_reference_uuid' => [
        'layout_type' => 'multi_region_empty',
        'yaml_input' => <<<YAML
          operations:
            - target: 'content'
              reference_uuid: 'some-uuid-123'
              placement: 'inside'
              components:
                - sdc.canvas_test_sdc.heading:
                    props:
                      text: "Some text"
                      element: "h1"
          YAML,
        'expected_error' => [
          'Operation 0' => [
            'The reference_uuid is not required for inside placement.',
          ],
        ],
      ],
      'test_empty_components' => [
        'layout_type' => 'multi_region_empty',
        'yaml_input' => <<<YAML
          operations:
            - target: 'content'
              reference_uuid: ''
              placement: 'inside'
              components: []
          YAML,
        'expected_error' => [
          'Operation 0' => [
            'The operation must contain components.',
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
