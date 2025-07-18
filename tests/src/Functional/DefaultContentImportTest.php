<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * @group experience_builder
 */
class DefaultContentImportTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder', 'xb_test_sdc'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function testImportDefaultContentWithXbData(): void {
    $finder = new Finder(__DIR__ . '/../../fixtures/default_content_export');
    $this->container->get(Importer::class)->importContent($finder);

    // The imported page should have some XB data.
    /** @var \Drupal\experience_builder\Entity\Page $page */
    $page = $this->container->get(EntityRepositoryInterface::class)
      ->loadEntityByUuid(Page::ENTITY_TYPE_ID, '20354d7a-e4fe-47af-8ff6-187bca92f3f7');
    $xb_field = $page->get('components')->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $xb_field);
    $this->assertFalse($xb_field->isEmpty());
  }

}
