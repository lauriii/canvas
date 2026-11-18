<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\EntityHandlers;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\entity_test\Entity\EntityTestBundle;
use Drupal\entity_test\Entity\EntityTestUuidId;
use Drupal\entity_test\Entity\EntityTestWithBundle;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests ContentTemplateAwareViewBuilder with the original view builders.
 *
 * Without an enabled content template, the output must be what the decorated
 * view builder produces, plus the cacheability Canvas needs to notice a
 * template being created later.
 */
#[CoversClass(ContentTemplateAwareViewBuilder::class)]
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class ContentTemplateAwareViewBuilderTest extends CanvasKernelTestBase {

  use ContentTypeCreationTrait;
  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'node',
    'entity_test',
    'canvas_entity_test',
    'field',
    'media_test_source',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'filter', 'node']);
    $this->setUpCurrentUser(permissions: ['access content']);
  }

  /**
   * Tests a node in preview mode, without a content template.
   *
   * NodeViewBuilder does not render cache a node that is being previewed.
   *
   * @see \Drupal\node\NodeViewBuilder::getBuildDefaults()
   */
  public function testNodePreviewWithoutTemplate(): void {
    $this->createContentType(['type' => 'article']);
    $node = Node::create(['type' => 'article', 'title' => 'Sample node']);
    $node->save();
    $view_builder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    $this->assertInstanceOf(ContentTemplateAwareViewBuilder::class, $view_builder);

    $build = $view_builder->view($node, 'full');
    $this->assertContains('without-canvas', $build['#cache']['keys']);

    $node->in_preview = TRUE;
    $build = $view_builder->view($node, 'full');
    $this->assertCacheabilityWithoutTemplate($build);
  }

  /**
   * Tests an entity whose view builder overrides ::view(), without a template.
   *
   * @see \Drupal\canvas_entity_test\Hook\EntityTestHooks::entityTypeAlter()
   * @see \Drupal\entity_test\EntityTestViewBuilderOverriddenView::view()
   */
  public function testOverriddenViewWithoutTemplate(): void {
    $entity = $this->createUuidIdEntity();

    $build = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('entity_test_uuid_id')->view($entity, 'full');
    // EntityTestViewBuilderOverriddenView::view() renders only the label.
    $this->assertSame('Sample entity', $build[$entity->id()]['#plain_text']);
    $this->assertCacheabilityWithoutTemplate($build);
  }

  /**
   * Tests a list of entities of two bundles, of which one has a template.
   *
   * The decorated view builder's ::alterBuild() must only alter the entity
   * without a template.
   *
   * @see \Drupal\canvas_entity_test\AlterBuildTestViewBuilder
   */
  public function testAlterBuildInListWithAndWithoutTemplate(): void {
    $this->installEntitySchema('entity_test_with_bundle');
    EntityViewMode::create([
      'id' => 'entity_test_with_bundle.full',
      'label' => 'Full',
      'targetEntityType' => 'entity_test_with_bundle',
    ])->save();
    $entities = [];
    foreach (['with_template', 'without_template'] as $bundle) {
      EntityTestBundle::create(['id' => $bundle, 'label' => $bundle])->save();
      $entities[$bundle] = EntityTestWithBundle::create(['type' => $bundle, 'name' => $bundle]);
      $entities[$bundle]->save();
    }
    ContentTemplate::create([
      'id' => 'entity_test_with_bundle.with_template.full',
      'content_entity_type_id' => 'entity_test_with_bundle',
      'content_entity_type_bundle' => 'with_template',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
      'status' => TRUE,
    ])->save();

    $view_builder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('entity_test_with_bundle');
    $this->assertInstanceOf(ContentTemplateAwareViewBuilder::class, $view_builder);
    $build = $view_builder->buildMultiple($view_builder->viewMultiple($entities, 'full'));
    $this->assertArrayNotHasKey('#canvas_entity_test_altered', $build['with_template']);
    $this->assertTrue($build['without_template']['#canvas_entity_test_altered']);
  }

  /**
   * Tests a media item with a template, embedded in another entity.
   *
   * Media has no page of its own unless stand-alone media URLs are enabled.
   */
  public function testEmbeddedMediaWithTemplate(): void {
    $this->installEntitySchema('media');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['media']);
    $this->assertNotTrue($this->config('media.settings')->get('standalone_url'));
    $this->setUpCurrentUser(permissions: ['access content', 'view media']);
    $media_type = $this->createMediaType('test');
    $media = Media::create([
      'bundle' => $media_type->id(),
      'name' => 'Embedded media item',
      'field_media_test' => 'Source field value',
    ]);
    $media->save();
    ContentTemplate::create([
      'id' => 'media.' . $media_type->id() . '.full',
      'content_entity_type_id' => 'media',
      'content_entity_type_bundle' => $media_type->id(),
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
      'status' => TRUE,
    ])->save();

    // Embed the media item in a node, rendered in the `full` view mode.
    $this->createContentType(['type' => 'article']);
    FieldStorageConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_media', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Media'])->save();
    $this->container->get(EntityDisplayRepositoryInterface::class)->getViewDisplay('node', 'article', 'default')
      ->setComponent('field_media', ['type' => 'entity_reference_entity_view', 'settings' => ['view_mode' => 'full']])
      ->save();
    $node = Node::create(['type' => 'article', 'title' => 'Host node', 'field_media' => $media->id()]);
    $node->save();

    $build = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node')->view($node, 'full');
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    // The template's empty component tree replaces the media item's fields.
    $this->assertStringNotContainsString('Source field value', $html);
    $this->assertContains('config:canvas.content_template.media.' . $media_type->id() . '.full', $build['#cache']['tags']);
  }

  /**
   * Tests a content block with a template, shown by a block plugin.
   *
   * The block gets its attributes and contextual links from the render array
   * of its content block, so that render array must be built when the block
   * plugin returns it.
   *
   * @param string $plugin_id
   *   The base ID of the block plugin that shows the content block.
   * @param string[] $modules
   *   The modules to enable for that block plugin.
   *
   * @see \Drupal\block\BlockViewBuilder::preRender()
   * @see \Drupal\canvas\Block\BuildsContentBlockImmediatelyTrait
   */
  #[TestWith(['block_content', []])]
  #[TestWith(['inline_block', ['layout_discovery', 'layout_builder']])]
  public function testContentBlockWithTemplateInBlock(string $plugin_id, array $modules): void {
    $this->enableModules(['block', 'block_content', ...$modules]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    BlockContentType::create(['id' => 'basic', 'label' => 'Basic'])->save();
    $block_content = BlockContent::create(['type' => 'basic', 'info' => 'My content block']);
    $block_content->save();
    ContentTemplate::create([
      'id' => 'block_content.basic.full',
      'content_entity_type_id' => 'block_content',
      'content_entity_type_bundle' => 'basic',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
      'status' => TRUE,
    ])->save();

    $block_plugin = $this->container->get(BlockManagerInterface::class)->createInstance(...match ($plugin_id) {
      'block_content' => ['block_content:' . $block_content->uuid(), ['view_mode' => 'full']],
      'inline_block' => ['inline_block:basic', ['view_mode' => 'full', 'block_revision_id' => $block_content->getRevisionId()]],
      default => throw new \LogicException("Unexpected block plugin: $plugin_id"),
    });
    $this->assertInstanceOf(BlockPluginInterface::class, $block_plugin);
    // What BlockViewBuilder::preRender() receives from the block plugin.
    $content = $block_plugin->build();
    $this->assertContains('config:canvas.content_template.block_content.basic.full', $content['#cache']['tags']);
    $this->assertArrayHasKey('block_content', $content['#contextual_links'] ?? []);
    // The content block is built: its view builder's lazy callback is gone.
    $view_builder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('block_content');
    $this->assertNotContains([$view_builder, 'build'], $content['#pre_render'] ?? []);
  }

  /**
   * Creates an `entity_test_uuid_id` entity.
   *
   * `canvas_entity_test` makes that entity type eligible for content
   * templates, and gives it a view builder that overrides ::view().
   */
  private function createUuidIdEntity(): EntityTestUuidId {
    $this->installEntitySchema('entity_test_uuid_id');
    $entity = EntityTestUuidId::create(['name' => 'Sample entity']);
    $entity->save();
    return $entity;
  }

  /**
   * Asserts the cacheability that Canvas adds when no template exists.
   *
   * The render arrays this is used for are not render cached, so they have no
   * cache keys.
   *
   * @param array $build
   *   The render array of one entity.
   *
   * @see \Drupal\Tests\canvas\Kernel\NodeTemplatesTest
   */
  private function assertCacheabilityWithoutTemplate(array $build): void {
    $this->assertContains('route.name.is_canvas_editor_ui', $build['#cache']['contexts']);
    $this->assertContains('config:content_template_list', $build['#cache']['tags']);
    $this->assertArrayNotHasKey('keys', $build['#cache']);
  }

}
