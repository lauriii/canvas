<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional\Update;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\experience_builder\Entity\Folder;
use Drupal\FunctionalTests\Update\UpdatePathTestBase;

/**
 * @covers experience_builder_update_112000()
 * @group experience_builder
 */
final class InstallFolderEntityTypeUpdateTest extends UpdatePathTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-xb-0.7.2-alpha1.filled.php.gz';
  }

  /**
   * Tests installing Folder entity type.
   */
  public function testCollapseInputs(): void {
    $entity_type = \Drupal::entityDefinitionUpdateManager()->getEntityType(Folder::ENTITY_TYPE_ID);
    $this->assertNull($entity_type);

    $this->runUpdates();

    $entity_type = \Drupal::entityTypeManager()->getDefinition(Folder::ENTITY_TYPE_ID);
    assert($entity_type instanceof EntityTypeInterface);
    $this->assertEquals(Folder::class, $entity_type->getOriginalClass());
  }

}
