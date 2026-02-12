<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\canvas\Entity\Component;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\canvas_ai\Traits\FunctionalCallTestTrait;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai\Plugin\AiFunctionCall\SetAIGeneratedTemplateData;
use Drupal\Core\Extension\ModuleInstallerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Yaml\Yaml;

/**
 * @coversDefaultClass \Drupal\canvas_ai\Plugin\AiFunctionCall\SetAIGeneratedTemplateData
 * @group canvas_ai
 */
final class SetAIGeneratedTemplateDataTest extends KernelTestBase {

  use FunctionalCallTestTrait;
  use UserCreationTrait;

  protected FunctionCallPluginManager $functionCallManager;

  protected AccountInterface $privilegedUser;

  protected AccountInterface $unprivilegedUser;

  protected MockObject $mockTempStore;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ai_agents',
    'system',
    'user',
    'canvas',
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');

    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    $privileged_user = $this->createUser([CanvasAiPermissions::USE_CANVAS_AI]);
    $unprivileged_user = $this->createUser();
    self::assertInstanceOf(AccountInterface::class, $privileged_user);
    self::assertInstanceOf(AccountInterface::class, $unprivileged_user);
    $this->privilegedUser = $privileged_user;
    $this->unprivilegedUser = $unprivileged_user;
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_sdc']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')
      ->getEditable('system.theme')
      ->set('default', 'stark')
      ->save();
    $this->mockTempStore = $this->createMock(CanvasAiTempStore::class);
    $this->container->set('canvas_ai.tempstore', $this->mockTempStore);
  }

  /**
   * Tests the tool output with valid component structure.
   *
   * @dataProvider templateDataToolDataProvider
   */
  public function testSetTemplateDataTool(string $component_structure_yaml, array $current_layout, array $expected_output): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $layout_json = \json_encode($current_layout);

    $this->mockTempStore->expects($this->once())
      ->method('getData')
      ->with(CanvasAiTempStore::CURRENT_LAYOUT_KEY)
      ->willReturn($layout_json);

    $tool = $this->functionCallManager->createInstance('canvas_ai:set_template_data');
    $this->assertInstanceOf(SetAIGeneratedTemplateData::class, $tool);

    $tool->setContextValue('component_structure', $component_structure_yaml);
    $tool->execute();

    $result = $tool->getStructuredOutput();
    $this->assertEquals($expected_output,
      $result,
      "The component structure was not processed correctly"
    );
  }

  /**
   * Tests validation errors.
   */
  public function testValidationError(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $invalid_nested_yaml = <<<YAML
content:
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

    $mock_layout = [
      "regions" => [
        "content" => [
          "nodePathPrefix" => [0],
          "components" => [],
        ],
      ],
    ];
    $layout_json = \json_encode($mock_layout);

    $this->mockTempStore->expects($this->once())
      ->method('getData')
      ->with(CanvasAiTempStore::CURRENT_LAYOUT_KEY)
      ->willReturn($layout_json);

    $result = $this->getTemplateToolOutput($invalid_nested_yaml);
    $this->assertSame('Failed to save: Component validation errors: components.0.[sdc.canvas_test_sdc.two_column].slots.column_one.0.[sdc.canvas_test_sdc.my-hero].props.cta1href: The property cta1href is required.', self::normalizeErrorString($result));
  }

  /**
   * Tests invalid region error.
   */
  public function testInvalidRegionError(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $invalid_region_yaml = <<<YAML
invalid_region:
  - sdc.canvas_test_sdc.heading:
      props:
        text: 'Some text'
        element: 'h1'
YAML;

    $mock_layout = [
      "regions" => [
        "content" => [
          "nodePathPrefix" => [0],
          "components" => [],
        ],
        "sidebar" => [
          "nodePathPrefix" => [1],
          "components" => [],
        ],
      ],
    ];
    $layout_json = \json_encode($mock_layout);

    $this->mockTempStore->expects($this->once())
      ->method('getData')
      ->with(CanvasAiTempStore::CURRENT_LAYOUT_KEY)
      ->willReturn($layout_json);

    $result = $this->getTemplateToolOutput($invalid_region_yaml);
    $this->assertSame('Failed to save: Region "invalid_region" does not exist. Available regions are: content, sidebar.', self::normalizeErrorString($result));
  }

  /**
   * Tests the tool output with components with required image prop.
   */
  public function testTemplateDataOutputWithComponentsWithRequiredImageProp(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    // If a component has an image prop and that prop is required, the component data passed to the tool must include the
    // exact default values for the image prop, which can be obtained from ContentTemplate::normalizeForClientSide(). Otherwise,
    // the validation will fail.
    $component = Component::load('sdc.canvas_test_sdc.card');
    \assert($component instanceof Component);
    $clientNormalized = $component->normalizeForClientSide()->values;
    // Get the default values for the image prop of the card component.
    $default_values = $clientNormalized["propSources"]["image"]["default_values"]["resolved"];

    $structure = [
      'content' => [
        [
          'sdc.canvas_test_sdc.card' => [
            'props' => [
              'title' => 'Test Card',
              'content' => 'Test content',
              'loading' => 'lazy',
              'image' => $default_values,
            ],
          ],
        ],
        [
          'sdc.canvas_test_sdc.heading' => [
            'props' => [
              'text' => 'Some text',
              'element' => 'h1',
            ],
          ],
        ],
      ],
    ];

    $valid_yaml = Yaml::dump($structure);

    $mock_layout = [
      "regions" => [
        "content" => [
          "nodePathPrefix" => [0],
          "components" => [],
        ],
      ],
    ];

    $this->mockTempStore->expects($this->once())
      ->method('getData')
      ->with(CanvasAiTempStore::CURRENT_LAYOUT_KEY)
      ->willReturn(\json_encode($mock_layout));

    $expected_output = [
      'operations' => [
        [
          'operation' => 'ADD',
          'components' => [
            [
              'id' => 'sdc.canvas_test_sdc.card',
              'nodePath' => [0, 0],
              'fieldValues' => [
                'title' => 'Test Card',
                'content' => 'Test content',
                'loading' => 'lazy',
                'image' => $default_values,
              ],
            ],
            [
              'id' => 'sdc.canvas_test_sdc.heading',
              'nodePath' => [0, 1],
              'fieldValues' => ['text' => 'Some text', 'element' => 'h1'],
            ],
          ],
        ],
      ],
    ];

    $tool = $this->functionCallManager->createInstance('canvas_ai:set_template_data');
    $this->assertInstanceOf(SetAIGeneratedTemplateData::class, $tool);

    $tool->setContextValue('component_structure', $valid_yaml);
    $tool->execute();

    $result = $tool->getStructuredOutput();
    $this->assertEquals($expected_output, $result);
  }

  private function getTemplateToolOutput(string $yaml): string {
    return $this->getToolOutput('canvas_ai:set_template_data', ['component_structure' => $yaml]);
  }

  /**
   * Data provider for testSetTemplateDataTool.
   */
  public static function templateDataToolDataProvider(): array {
    return [
      'simple_input' => [
        'component_structure_yaml' => <<<YAML
content:
  - sdc.canvas_test_sdc.my-hero:
      props:
        heading: 'My Hero'
        subheading: 'SubSnub'
        cta1: 'View it!'
        cta1href: 'https://canvas-example.com'
        cta2: 'Click it!'
sidebar:
  - sdc.canvas_test_sdc.heading:
      props:
        text: 'Some text'
        element: 'h1'
YAML,
        'current_layout' => [
          "regions" => [
            "content" => [
              "nodePathPrefix" => [0],
              "components" => [],
            ],
            "sidebar" => [
              "nodePathPrefix" => [1],
              "components" => [],
            ],
          ],
        ],
        'expected_output' => [
          'operations' => [
            [
              'operation' => 'ADD',
              'components' => [
                [
                  'id' => 'sdc.canvas_test_sdc.my-hero',
                  'nodePath' => [0, 0],
                  'fieldValues' => [
                    'heading' => 'My Hero',
                    'subheading' => 'SubSnub',
                    'cta1' => 'View it!',
                    'cta1href' => 'https://canvas-example.com',
                    'cta2' => 'Click it!',
                  ],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [1, 0],
                  'fieldValues' => ['text' => 'Some text', 'element' => 'h1'],
                ],
              ],
            ],
          ],
        ],
      ],
      'nested_input' => [
        'component_structure_yaml' => <<<YAML
content:
  - sdc.canvas_test_sdc.two_column:
      props:
        width: '50'
      slots:
        column_one:
          - sdc.canvas_test_sdc.heading:
              props:
                text: 'Some text'
                element: 'h1'
        column_two:
          - sdc.canvas_test_sdc.heading:
              props:
                text: 'Some text'
                element: 'h1'
sidebar:
  - sdc.canvas_test_sdc.two_column:
      props:
        width: '50'
      slots:
        column_one:
          - sdc.canvas_test_sdc.heading:
              props:
                text: 'Some text'
                element: 'h1'
        column_two:
          - sdc.canvas_test_sdc.heading:
              props:
                text: 'Some text'
                element: 'h1'
          - sdc.canvas_test_sdc.two_column:
              props:
                width: '50'
              slots:
                column_one:
                  - sdc.canvas_test_sdc.heading:
                      props:
                        text: 'Some text'
                        element: 'h1'
                column_two:
                  - sdc.canvas_test_sdc.heading:
                      props:
                        text: 'Some text'
                        element: 'h1'
YAML,
        'current_layout' => [
          "regions" => [
            "content" => [
              "nodePathPrefix" => [0],
              "components" => [],
            ],
            "sidebar" => [
              "nodePathPrefix" => [1],
              "components" => [],
            ],
          ],
        ],
        'expected_output' => [
          'operations' => [
            [
              'operation' => 'ADD',
              'components' => [
                [
                  'id' => 'sdc.canvas_test_sdc.two_column',
                  'nodePath' => [0, 0],
                  'fieldValues' => [
                    'width' => '50',
                  ],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [0, 0, 0, 0],
                  'fieldValues' => ['text' => 'Some text', 'element' => 'h1'],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [0, 0, 1, 0],
                  'fieldValues' => ['text' => 'Some text', 'element' => 'h1'],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.two_column',
                  'nodePath' => [1, 0],
                  'fieldValues' => [
                    'width' => '50',
                  ],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [1, 0, 0, 0],
                  'fieldValues' => ['text' => 'Some text', 'element' => 'h1'],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [1, 0, 1, 0],
                  'fieldValues' => ['text' => 'Some text', 'element' => 'h1'],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.two_column',
                  'nodePath' => [1, 0, 1, 1],
                  'fieldValues' => [
                    'width' => '50',
                  ],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [1, 0, 1, 1, 0, 0],
                  'fieldValues' => ['text' => 'Some text', 'element' => 'h1'],
                ],
                [
                  'id' => 'sdc.canvas_test_sdc.heading',
                  'nodePath' => [1, 0, 1, 1, 1, 0],
                  'fieldValues' => ['text' => 'Some text', 'element' => 'h1'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

}
