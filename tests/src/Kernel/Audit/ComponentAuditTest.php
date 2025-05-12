<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Audit;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\experience_builder\Audit\ComponentAudit;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Entity\Pattern;

/**
 * @coversDefaultClass \Drupal\experience_builder\Audit\ComponentAudit
 * @group experience_builder
 * @todo Improve in
 *   https://www.drupal.org/project/experience_builder/issues/3522953
 */
class ComponentAuditTest extends ComponentAuditTestBase {

  /**
   * @covers ::getContentRevisionsUsingComponent
   */
  public function testGetContentRevisionsUsingComponent(): void {
    $audit = $this->container->get(ComponentAudit::class);
    $component = Component::load('sdc.xb_test_sdc.props-slots');
    \assert($component instanceof ComponentInterface);
    $config = $audit->getContentRevisionsUsingComponent($component);
    self::assertCount(0, $config);

    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => $this->tree,
    ]);
    $page->save();
    $revisionId1 = $page->getRevisionId();
    $page->setNewRevision();
    $page->set('components', [])->save();
    $page->save();

    $config = $audit->getContentRevisionsUsingComponent($component);
    self::assertEquals([$page->uuid()], \array_map(static fn(ContentEntityInterface $page): string|null => $page->uuid(), $config));
    self::assertEquals([$revisionId1], \array_map(static fn(ContentEntityInterface $page): int|null|string => $page->getRevisionId(), $config));
  }

  protected function createTestPattern(array $tree): Pattern {
    $pattern = Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test Pattern',
      'component_tree' => $tree,
    ]);
    $pattern->save();
    return $pattern;
  }

  protected function createTestPageRegion(array $tree): PageRegion {
    $page_region = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => $tree,
    ]);
    $page_region->save();
    return $page_region;
  }

  protected function createTestContentTemplate(array $tree): ContentTemplate {
    $entity_type_id = Page::ENTITY_TYPE_ID;
    $view_mode = 'reverse';
    EntityViewMode::create([
      'id' => \implode('.', [$entity_type_id, $view_mode]),
      'label' => 'Reverse',
      'targetEntityType' => $entity_type_id,
    ])->save();
    $content_template = ContentTemplate::create([
      'id' => \implode('.', [$entity_type_id, $entity_type_id, $view_mode]),
      'content_entity_type_id' => $entity_type_id,
      'content_entity_type_bundle' => $entity_type_id,
      'content_entity_type_view_mode' => $view_mode,
      'component_tree' => $tree,
    ]);
    $content_template->save();
    return $content_template;
  }

  /**
   * @covers ::getConfigEntityDependenciesUsingComponent
   * @dataProvider configProvider
   */
  public function testGetConfigEntityDependenciesUsingComponent(string $config_entity_type_id): void {
    $audit = $this->container->get(ComponentAudit::class);
    $component = Component::load('sdc.xb_test_sdc.props-slots');
    \assert($component instanceof ComponentInterface);
    $config = $audit->getConfigEntityDependenciesUsingComponent($component, $config_entity_type_id);
    self::assertCount(0, $config);
    $entity = match ($config_entity_type_id) {
      PageRegion::ENTITY_TYPE_ID => $this->createTestPageRegion($this->tree),
      Pattern::ENTITY_TYPE_ID => $this->createTestPattern($this->tree),
      ContentTemplate::ENTITY_TYPE_ID => $this->createTestContentTemplate($this->tree),
      default => throw new \InvalidArgumentException()
    };
    $config = $audit->getConfigEntityDependenciesUsingComponent($component, $config_entity_type_id);
    self::assertCount(1, $config);
    self::assertEquals([$entity->id()], \array_values(\array_map(static fn(ConfigEntityInterface $entity): string|int|null => $entity->id(), $config)));
  }

  public static function configProvider(): iterable {
    yield PageRegion::ENTITY_TYPE_ID => [PageRegion::ENTITY_TYPE_ID];
    yield Pattern::ENTITY_TYPE_ID => [Pattern::ENTITY_TYPE_ID];
    yield ContentTemplate::ENTITY_TYPE_ID => [ContentTemplate::ENTITY_TYPE_ID];
  }

}
