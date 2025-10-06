<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the GetNodeFields function call plugin.
 *
 * @group canvas_ai
 */
final class GetNodeFieldsTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The function call plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $functionCallManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
    'node',
    'user',
    'ai',
    'system',
    'field',
    'field_ui',
    'text',
    'canvas',
    'canvas_ai',
    'media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install the necessary entity schemas.
    $this->installEntitySchema('user');

    // Set up the dependencies.
    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    $this->entityTypeManager = $this->container->get('entity_type.manager');

    $privileged_user = $this->createUser([CanvasAiPermissions::USE_CANVAS_AI]);
    $unprivileged_user = $this->createUser();
    if (!$privileged_user instanceof User || !$unprivileged_user instanceof User) {
      throw new \Exception('Failed to create test users');
    }
    $this->privilegedUser = $privileged_user;
    $this->unprivilegedUser = $unprivileged_user;

    $node_type_storage = $this->entityTypeManager->getStorage('node_type');
    $node_type_storage->create([
      'type' => 'article',
      'name' => 'Article',
      'description' => 'An article content type for testing.',
    ])->save();

    // Add body field to the article content type.
    $field_storage = $this->entityTypeManager->getStorage('field_storage_config');
    $field_storage->create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => 1,
    ])->save();
    $field_instance = $this->entityTypeManager->getStorage('field_config');
    $field_instance->create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Body',
      'required' => FALSE,
      'settings' => [
        'max_length' => 5000,
        'text_processing' => 1,
      ],
    ])->save();

    // Add media image field to the article content type.
    $field_storage->create([
      'field_name' => 'media_image_field',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'media',
      ],
    ])->save();
    $field_instance->create([
      'label' => 'A Media Image Field',
      'field_name' => 'media_image_field',
      'entity_type' => 'node',
      'bundle' => 'article',
      'field_type' => 'entity_reference',
      'required' => FALSE,
      'settings' => [
        'handler' => 'default',
        'handler_settings' => [
          'target_bundles' => ['image'],
        ],
      ],
    ])->save();

  }

  /**
   * Tests the GetNodeFields function call.
   */
  public function testGetNodeFields(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);
    // Get the function call plugin.
    $function_call = $this->functionCallManager->createInstance('ai_agent:get_node_fields');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $function_call);

    // Set the function call context.
    $function_call->setContextValue('node_type', 'article');

    // Execute the function call with the 'article' content type.
    $function_call->execute();
    $result = $function_call->getReadableOutput();

    // Assert that the result is an string and contains expected keys.
    $this->assertIsString($result);

    $parsed_result = Yaml::parse($result);
    $this->assertArrayHasKey('fields', $parsed_result);
    $this->assertArrayHasKey('body', $parsed_result['fields']);
    $this->assertArrayHasKey('field_type', $parsed_result['fields']['body']);
    $this->assertArrayHasKey('field_settings', $parsed_result['fields']['body']);
    $this->assertArrayHasKey('cardinality', $parsed_result['fields']['body']);
    $this->assertArrayHasKey('reference_entity_fields', $parsed_result);
    $this->assertArrayHasKey('media_image_field', $parsed_result['reference_entity_fields']);
    $this->assertArrayHasKey('cardinality', $parsed_result['reference_entity_fields']['media_image_field']);
  }

  /**
   * Try to get fields for a non-existing node type.
   */
  public function testGetNonExistingNodeTypeFields(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);
    // Get the function call plugin.
    $function_call = $this->functionCallManager->createInstance('ai_agent:get_node_fields');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $function_call);

    // Set the function call context for a non-existing content type.
    $function_call->setContextValue('node_type', 'non_existing_type');

    // Execute the function call with a non-existing content type.
    $function_call->execute();
    $result = $function_call->getReadableOutput();

    // Assert that the result is an empty string or contains an error message.
    $this->assertIsString($result);
    $this->assertSame('Node type with name "non_existing_type" does not exist.', $result);
  }

  /**
   * Tests the GetNodeFields function call without proper permissions.
   */
  public function testGetNodeFieldsWithoutPermissions(): void {
    $this->container->get('current_user')->setAccount($this->unprivilegedUser);
    $tool = $this->functionCallManager->createInstance('ai_agent:get_node_fields');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    // Expect an exception to be thrown.
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');
    $tool->execute();
  }

}
