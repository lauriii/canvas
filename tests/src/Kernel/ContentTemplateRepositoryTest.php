<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\ContentTemplateRepository;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests ContentTemplateRepository.
 */
#[CoversClass(ContentTemplateRepository::class)]
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class ContentTemplateRepositoryTest extends CanvasKernelTestBase {

  /**
   * Tests which entity types support content templates.
   *
   * The entity types that are not eligible, by the condition they do not meet:
   * 1. Opted into Field UI: `path_alias`.
   * 2. Stored: `contact_message`.
   * 3. No component tree of its own: `canvas_page`.
   *
   * `comment` is excluded by name.
   *
   * @param string $entity_type_id
   *   The entity type to check.
   * @param string[] $modules
   *   The modules that provide the entity type.
   * @param bool $expected
   *   Whether the entity type is expected to support content templates.
   *
   * @see \Drupal\canvas\ContentTemplateRepository::isSupportedEntityType()
   */
  #[TestWith(['node', ['node'], TRUE])]
  #[TestWith(['taxonomy_term', ['taxonomy'], TRUE])]
  #[TestWith(['user', [], TRUE])]
  #[TestWith(['media', [], TRUE])]
  #[TestWith(['block_content', ['block_content'], TRUE])]
  #[TestWith(['path_alias', [], FALSE])]
  #[TestWith(['contact_message', ['contact'], FALSE])]
  #[TestWith([Page::ENTITY_TYPE_ID, [], FALSE])]
  #[TestWith(['comment', ['comment'], FALSE])]
  public function testIsSupportedEntityType(string $entity_type_id, array $modules, bool $expected): void {
    if ($modules !== []) {
      $this->enableModules($modules);
    }
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $entity_type_manager->clearCachedDefinitions();

    $this->assertSame($expected, ContentTemplateRepository::isSupportedEntityType($entity_type_manager->getDefinition($entity_type_id)));
  }

}
