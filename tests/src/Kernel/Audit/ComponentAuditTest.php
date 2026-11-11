<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Audit;

use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Drupal\canvas\Audit\ComponentAudit.
 *
 * @todo Improve in
 *   https://www.drupal.org/project/canvas/issues/3522953
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ComponentAudit::class)]
#[Group('canvas')]
class ComponentAuditTest extends ComponentAuditTestBase {

  /**
   * Tests get content revisions using component.
   *
   * @legacy-covers ::getContentRevisionsUsingAuditTarget
   */
  public function testGetContentRevisionsUsingAuditTarget(): void {
    $audit = $this->container->get(ComponentAudit::class);
    $component = Component::load('sdc.canvas_test_sdc.my-cta');
    \assert($component instanceof ComponentInterface);
    self::assertCount(1, $component->getVersions());
    $old_version = $component->getActiveVersion();
    $content = $audit->getContentRevisionsUsingAuditTarget($component);
    self::assertCount(0, $content);

    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => $this->tree,
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $revisionId1 = $page->getRevisionId();
    $page->setNewRevision();
    $page->set('components', [])->save();
    $page->save();

    // Now enable the 'canvas_test_storable_prop_shape_alter' module to change the
    // field type used for populating the href prop.
    // @see \Drupal\canvas_test_storable_prop_shape_alter\Hook\CanvasTestStorablePropShapeAlterHooks::storablePropShapeAlter()
    \Drupal::service(ModuleInstallerInterface::class)
      ->install(['canvas_test_storable_prop_shape_alter']);
    $component = Component::load('sdc.canvas_test_sdc.my-cta');
    \assert($component instanceof ComponentInterface);
    self::assertCount(2, $component->getVersions());
    $new_version = $component->getActiveVersion();

    // 1. All versions.
    $content = $audit->getContentRevisionsUsingAuditTarget($component);
    self::assertEquals([$page->uuid()], \array_map(static fn(ContentEntityInterface $page): string|null => $page->uuid(), $content));
    self::assertEquals([$revisionId1], \array_map(static fn(ContentEntityInterface $page): int|null|string => $page->getRevisionId(), $content));

    // 2. Active (i.e. new) version: no uses yet.
    $content = $audit->getContentRevisionsUsingAuditTarget($component, [$new_version]);
    self::assertEquals([], \array_map(static fn(ContentEntityInterface $page): string|null => $page->uuid(), $content));
    self::assertEquals([], \array_map(static fn(ContentEntityInterface $page): int|null|string => $page->getRevisionId(), $content));

    // 3. Old version.
    $content = $audit->getContentRevisionsUsingAuditTarget($component, [$old_version]);
    self::assertEquals([$page->uuid()], \array_map(static fn(ContentEntityInterface $page): string|null => $page->uuid(), $content));
    self::assertEquals([$revisionId1], \array_map(static fn(ContentEntityInterface $page): int|null|string => $page->getRevisionId(), $content));
  }

  /**
   * Tests auto-save usages are computed once, until an auto-save is written.
   *
   * @legacy-covers ::hasAutoSaveUsages
   */
  public function testHasAutoSaveUsages(): void {
    $audit = $this->container->get(ComponentAudit::class);
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    $this->installEntitySchema('path_alias');
    $used = Component::load('sdc.canvas_test_sdc.my-cta');
    $unused = Component::load('sdc.canvas_test_sdc.druplicon');
    \assert($used instanceof ComponentInterface);
    \assert($unused instanceof ComponentInterface);
    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => [],
    ]);
    $page->save();
    self::assertFalse($audit->hasAutoSaveUsages($used));

    // Saving an auto-save invalidates the computed usages.
    $page->set('components', $this->tree);
    $auto_save_manager->saveEntity($page);
    self::assertTrue($audit->hasAutoSaveUsages($used));
    self::assertFalse($audit->hasAutoSaveUsages($unused));
    self::assertTrue($audit->hasUsages($used, RevisionAuditEnum::AutoSave));

    // The usages are computed once: a change to the auto-save store that does
    // not invalidate the auto-save cache tag goes unnoticed.
    $this->container->get('keyvalue')->get(AutoSaveManager::AUTO_SAVE_STORE)->deleteAll();
    self::assertTrue($audit->hasAutoSaveUsages($used));
    Cache::invalidateTags([AutoSaveManager::CACHE_TAG]);
    self::assertFalse($audit->hasAutoSaveUsages($used));

    // Deleting an auto-save invalidates the computed usages.
    $auto_save_manager->saveEntity($page);
    self::assertTrue($audit->hasAutoSaveUsages($used));
    $auto_save_manager->delete($page);
    self::assertFalse($audit->hasAutoSaveUsages($used));
  }

  protected static function createTestPattern(array $tree): Pattern {
    $pattern = Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test Pattern',
      'component_tree' => $tree,
    ]);
    $pattern->save();
    return $pattern;
  }

  protected static function createTestPageRegion(array $tree): PageRegion {
    $page_region = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => $tree,
    ]);
    $page_region->save();
    return $page_region;
  }

  protected function createTestContentTemplate(array $tree): ContentTemplate {
    $entity_type_id = 'node';
    $bundle = 'foo';
    $view_mode = 'reverse';

    EntityViewMode::create([
      'id' => \implode('.', [$entity_type_id, $view_mode]),
      'label' => 'Reverse',
      'targetEntityType' => $entity_type_id,
    ])->save();
    NodeType::create([
      'type' => $bundle,
      'name' => $this->randomString(),
    ])->save();
    $content_template = ContentTemplate::create([
      'id' => \implode('.', [$entity_type_id, $bundle, $view_mode]),
      'content_entity_type_id' => $entity_type_id,
      'content_entity_type_bundle' => $bundle,
      'content_entity_type_view_mode' => $view_mode,
      'component_tree' => $tree,
    ]);
    $content_template->save();
    return $content_template;
  }

  /**
   * Tests get config entity dependencies using component.
   *
   * @legacy-covers ::getConfigEntityDependenciesUsingAuditTarget
   */
  #[DataProvider('configProvider')]
  public function testGetConfigEntityDependenciesUsingAuditTarget(string $config_entity_type_id): void {
    $audit = $this->container->get(ComponentAudit::class);
    $component = Component::load('sdc.canvas_test_sdc.my-cta');
    \assert($component instanceof ComponentInterface);
    self::assertCount(1, $component->getVersions());
    $old_version = $component->getActiveVersion();
    $config = $audit->getConfigEntityDependenciesUsingAuditTarget($component, $config_entity_type_id);
    self::assertCount(0, $config);
    $entity = match ($config_entity_type_id) {
      PageRegion::ENTITY_TYPE_ID => $this->createTestPageRegion($this->tree),
      Pattern::ENTITY_TYPE_ID => $this->createTestPattern($this->tree),
      ContentTemplate::ENTITY_TYPE_ID => $this->createTestContentTemplate($this->tree),
      default => throw new \InvalidArgumentException()
    };

    // Now enable the 'canvas_test_storable_prop_shape_alter' module to change the
    // field type used for populating the href prop.
    // @see \Drupal\canvas_test_storable_prop_shape_alter\Hook\CanvasTestStorablePropShapeAlterHooks::storablePropShapeAlter()
    \Drupal::service(ModuleInstallerInterface::class)
      ->install(['canvas_test_storable_prop_shape_alter']);
    $component = Component::load('sdc.canvas_test_sdc.my-cta');
    \assert($component instanceof ComponentInterface);
    self::assertCount(2, $component->getVersions());
    $new_version = $component->getActiveVersion();

    // 1. All versions.
    $config = $audit->getConfigEntityDependenciesUsingAuditTarget($component, $config_entity_type_id);
    self::assertCount(1, $config);
    self::assertEquals([$entity->id()], \array_values(\array_map(static fn(ConfigEntityInterface $entity): string|int|null => $entity->id(), $config)));

    // 2. Active (i.e. new) version: no uses yet.
    $config = $audit->getConfigEntityDependenciesUsingAuditTarget($component, $config_entity_type_id, [$new_version]);
    self::assertEquals([], \array_values(\array_map(static fn(ConfigEntityInterface $entity): string|int|null => $entity->id(), $config)));

    // 3. Old version.
    $config = $audit->getConfigEntityDependenciesUsingAuditTarget($component, $config_entity_type_id, [$old_version]);
    self::assertEquals([$entity->id()], \array_values(\array_map(static fn(ConfigEntityInterface $entity): string|int|null => $entity->id(), $config)));
  }

  public static function configProvider(): iterable {
    yield PageRegion::ENTITY_TYPE_ID => [PageRegion::ENTITY_TYPE_ID];
    yield Pattern::ENTITY_TYPE_ID => [Pattern::ENTITY_TYPE_ID];
    yield ContentTemplate::ENTITY_TYPE_ID => [ContentTemplate::ENTITY_TYPE_ID];
  }

}
