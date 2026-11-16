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
   * 3. Has a page of its own: `block_content`, `media` without stand-alone
   *    URLs, and the special case `comment` (it redirects).
   * 4. No component tree of its own: `canvas_page`.
   *
   * @param string $entity_type_id
   *   The entity type to check.
   * @param string[] $modules
   *   The modules that provide the entity type.
   * @param bool $media_standalone_url
   *   The `media.settings:standalone_url` setting.
   * @param bool $expected
   *   Whether the entity type is expected to support content templates.
   *
   * @see \Drupal\canvas\ContentTemplateRepository::isSupportedEntityType()
   */
  #[TestWith(['node', ['node'], FALSE, TRUE])]
  #[TestWith(['taxonomy_term', ['taxonomy'], FALSE, TRUE])]
  #[TestWith(['user', [], FALSE, TRUE])]
  #[TestWith(['media', [], TRUE, TRUE])]
  #[TestWith(['path_alias', [], FALSE, FALSE])]
  #[TestWith(['contact_message', ['contact'], FALSE, FALSE])]
  #[TestWith(['block_content', ['block_content'], FALSE, FALSE])]
  #[TestWith(['media', [], FALSE, FALSE])]
  #[TestWith(['comment', ['comment'], FALSE, FALSE])]
  #[TestWith([Page::ENTITY_TYPE_ID, [], FALSE, FALSE])]
  public function testIsSupportedEntityType(string $entity_type_id, array $modules, bool $media_standalone_url, bool $expected): void {
    if ($modules !== []) {
      $this->enableModules($modules);
    }
    if ($media_standalone_url) {
      $this->installConfig(['media']);
      $this->config('media.settings')->set('standalone_url', TRUE)->save();
    }
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $entity_type_manager->clearCachedDefinitions();

    $this->assertSame($expected, ContentTemplateRepository::isSupportedEntityType($entity_type_manager->getDefinition($entity_type_id)));
  }

}
