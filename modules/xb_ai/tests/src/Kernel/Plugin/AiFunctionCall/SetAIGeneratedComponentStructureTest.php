<?php

declare(strict_types=1);

namespace Drupal\Tests\xb_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\xb_ai\XbAiPageBuilderHelper;

/**
 * Tests for the SetAIGeneratedComponentStructure function call plugin.
 *
 * @group xb_ai
 */
final class SetAIGeneratedComponentStructureTest extends KernelTestBase {

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
    'experience_builder',
    'system',
    'user',
    'xb_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');

    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    $privileged_user = $this->createUser(['use experience builder ai']);
    $unprivileged_user = $this->createUser();
    if (!$privileged_user instanceof User || !$unprivileged_user instanceof User) {
      throw new \Exception('Failed to create test users');
    }
    $this->privilegedUser = $privileged_user;
    $this->unprivilegedUser = $unprivileged_user;
  }

  /**
   * Tests setting component structure with proper permissions and valid data.
   */
  public function testSetComponentStructureWithPermissionsAndValidData(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $valid_yaml = <<<YAML
reference_nodepath: [0, 0, 1, 0]
placement: 'below'
components:
  - sdc.xb_test_sdc.card:
      props:
        title: 'Test Card'
        content: 'Test content'
YAML;

    $mock_helper = $this->createMock(XbAiPageBuilderHelper::class);
    $mock_helper->expects($this->once())
      ->method('extractComponentIds')
      ->with($this->callback(fn($arg) => is_array($arg)))
      ->willReturn(['sdc.xb_test_sdc.card']);

    $mock_helper->expects($this->once())
      ->method('validateComponentsInAiResponse')
      ->with(['sdc.xb_test_sdc.card']);

    $expected_output = [
      'operations' => [
        [
          'operation' => 'ADD',
          'components' => [
            [
              'id' => 'sdc.xb_test_sdc.card',
              'nodePath' => [0, 0, 1, 1],
              'fieldValues' => [
                'title' => 'Test Card',
                'content' => 'Test content',
              ],
            ],
          ],
        ],
      ],
      'message' => 'The changes have been made.',
    ];

    $mock_helper->expects($this->once())
      ->method('customYamlToArrayMapper')
      ->with($valid_yaml)
      ->willReturn($expected_output);

    $this->container->set('xb_ai.page_builder_helper', $mock_helper);

    $tool = $this->functionCallManager->createInstance('xb_ai:set_component_structure');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $tool->setContextValue('component_structure', $valid_yaml);
    $tool->execute();

    $result = $tool->getReadableOutput();
    $this->assertEquals(Json::encode($expected_output), $result);
  }

  /**
   * Tests setting component structure without proper permissions.
   */
  public function testSetComponentStructureWithoutPermissions(): void {
    $this->container->get('current_user')->setAccount($this->unprivilegedUser);

    $tool = $this->functionCallManager->createInstance('xb_ai:set_component_structure');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    // Expect an exception to be thrown.
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');

    $tool->setContextValue('component_structure', 'test: value');
    $tool->execute();
  }

  /**
   * Tests setting component structure with invalid reference nodepath.
   */
  public function testSetComponentStructureWithInvalidReferenceNodepath(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $invalid_yaml = <<<YAML
reference_nodepath: [0, 1, 2]  # Invalid because it has an odd number of elements
placement: 'below'
components:
  - id: 'sdc.xb_test_sdc.card'
    name: 'Card'
YAML;

    $tool = $this->functionCallManager->createInstance('xb_ai:set_component_structure');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    $tool->setContextValue('component_structure', $invalid_yaml);
    $tool->execute();

    $result = $tool->getReadableOutput();
    $this->assertStringContainsString('Failed to process layout data:', $result);
    $this->assertStringContainsString('is incomplete and missing elements', $result);
  }

  /**
   * Tests setting component structure with invalid YAML.
   */
  public function testSetComponentStructureWithInvalidYaml(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $invalid_yaml = <<<YAML
reference_nodepath: [0, 0]
components:
  - id: 'sdc.xb_test_sdc.card'
    name: 'Card'
    props:
      title: 'Test Card'
      content: [invalid: yaml: structure
YAML;

    $tool = $this->functionCallManager->createInstance('xb_ai:set_component_structure');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    $tool->setContextValue('component_structure', $invalid_yaml);
    $tool->execute();

    $result = $tool->getReadableOutput();
    $this->assertStringContainsString('Failed to process layout data:', $result);
  }

  /**
   * Tests setting component structure with invalid component validation.
   */
  public function testSetComponentStructureWithInvalidComponents(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $valid_yaml = <<<YAML
reference_nodepath: [1, 0, 1, 0]
placement: 'above'
components:
  - invalid.component.id:
      props:
        title: 'Invalid Component'
YAML;

    $mock_helper = $this->createMock(XbAiPageBuilderHelper::class);
    $mock_helper->expects($this->once())
      ->method('extractComponentIds')
      ->with($this->callback(fn($arg) => is_array($arg)))
      ->willReturn(['invalid.component.id']);

    $mock_helper->expects($this->once())
      ->method('validateComponentsInAiResponse')
      ->with(['invalid.component.id'])
      ->willThrowException(new \Exception('The following component ids are incorrect: invalid.component.id'));

    $this->container->set('xb_ai.page_builder_helper', $mock_helper);

    $tool = $this->functionCallManager->createInstance('xb_ai:set_component_structure');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    $tool->setContextValue('component_structure', $valid_yaml);
    $tool->execute();

    $result = $tool->getReadableOutput();
    $this->assertStringContainsString('Failed to process layout data:', $result);
    $this->assertStringContainsString('The following component ids are incorrect: invalid.component.id', $result);
  }

  /**
   * Tests component structure with nested slots.
   */
  public function testComponentStructureWithNestedSlots(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $nested_yaml = <<<YAML
reference_nodepath: [0, 4, 0, 3, 1, 0]
placement: above
components:
  - sdc.xb_test_sdc.card:
      props:
        title: 'Card with nested content'
      slots:
        content:
          - sdc.xb_test_sdc.text:
              props:
                text: 'Nested text component'
YAML;

    $mock_helper = $this->createMock(XbAiPageBuilderHelper::class);
    $mock_helper->expects($this->once())
      ->method('extractComponentIds')
      ->with($this->callback(fn($arg) => is_array($arg)))
      ->willReturn(['sdc.xb_test_sdc.card', 'sdc.xb_test_sdc.text']);

    $mock_helper->expects($this->once())
      ->method('validateComponentsInAiResponse')
      ->with(['sdc.xb_test_sdc.card', 'sdc.xb_test_sdc.text']);

    $expected_output = [
      'operations' => [
        [
          'operation' => 'ADD',
          'components' => [
            [
              'id' => 'sdc.xb_test_sdc.card',
              'nodePath' => [0, 4, 0, 3, 1, 0],
              'fieldValues' => ['title' => 'Card with nested content'],
            ],
            [
              'id' => 'sdc.xb_test_sdc.text',
              'nodePath' => [0, 4, 0, 3, 1, 0, 0, 0],
              'fieldValues' => ['text' => 'Nested text component'],
            ],
          ],
        ],
      ],
      'message' => 'The changes have been made.',
    ];

    $mock_helper->expects($this->once())
      ->method('customYamlToArrayMapper')
      ->with($nested_yaml)
      ->willReturn($expected_output);

    $this->container->set('xb_ai.page_builder_helper', $mock_helper);

    $tool = $this->functionCallManager->createInstance('xb_ai:set_component_structure');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    $tool->setContextValue('component_structure', $nested_yaml);
    $tool->execute();

    $result = $tool->getReadableOutput();
    $this->assertEquals(Json::encode($expected_output), $result);
  }

}
