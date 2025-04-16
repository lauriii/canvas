<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\experience_builder\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\Entity\ContentTemplate
 * @group experience_builder
 */
final class ContentTemplateTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // The two only modules Drupal truly requires.
    'system',
    'user',
    // The module being tested.
    'experience_builder',
    // The content entity type being tested plus bundle fields.
    'node',
    'field',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['node', 'user']);
    NodeType::create(['type' => 'helpful', 'name' => 'Helpful'])->save();
  }

  /**
   * @covers ::label
   *
   * @testWith ["node.helpful.full", "Helpful content items — Full content view"]
   *           ["user.user.compact", "Users — Compact view"]
   */
  public function testLabel(string $id, string $expected_label): void {
    [$entity_type_id, $bundle, $view_mode] = explode('.', $id, 3);

    $template = ContentTemplate::create([
      'id' => $id,
      'content_entity_type_id' => $entity_type_id,
      'content_entity_type_bundle' => $bundle,
      'content_entity_type_view_mode' => $view_mode,
    ]);
    $this->assertSame($expected_label, (string) $template->label());
  }

  /**
   * @covers \experience_builder_entity_type_alter
   */
  public function testOnlyContentEntitiesCanUseTemplates(): void {
    $manager = \Drupal::entityTypeManager();
    $definition = $manager->getDefinition('node');
    assert($definition instanceof EntityTypeInterface);
    $this->assertTrue($definition->hasHandlerClass(ContentTemplateAwareViewBuilder::DECORATED_HANDLER_KEY));
    $this->assertSame(ContentTemplateAwareViewBuilder::class, $definition->getViewBuilderClass());

    // Config entities have no view builder and XB doesn't touch them.
    $definition = $manager->getDefinition('user_role');
    assert($definition instanceof EntityTypeInterface);
    $this->assertFalse($definition->hasViewBuilderClass());
    $this->assertFalse($definition->hasHandlerClass(ContentTemplateAwareViewBuilder::DECORATED_HANDLER_KEY));

    // XB pages are left alone despite being content entities.
    $definition = $manager->getDefinition('xb_page');
    assert($definition instanceof EntityTypeInterface);
    $this->assertFalse($definition->hasHandlerClass(ContentTemplateAwareViewBuilder::DECORATED_HANDLER_KEY));
  }

}
