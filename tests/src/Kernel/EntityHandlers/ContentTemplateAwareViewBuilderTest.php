<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\EntityHandlers;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\entity_test\Entity\EntityTestBundle;
use Drupal\entity_test\Entity\EntityTestUuidId;
use Drupal\entity_test\Entity\EntityTestWithBundle;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

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
