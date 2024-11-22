<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\Entity\Page;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\PageTrait;

/**
 * @group experience_builder
 */
final class PageMetatagIntegrationTest extends KernelTestBase {

  use PageTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'block',
    'sdc',
    'sdc_test',
    'xb_test_sdc',
    // Modules providing field types + widgets for the component props defaults.
    'file',
    'image',
    'options',
    'link',
    'system',
    ...self::PAGE_TEST_MODULES,
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('xb_page');
  }

  public function testTags(): void {
    self::assertArrayNotHasKey(
      'metatags',
      $this->container->get('entity_field.manager')
        ->getFieldDefinitions('xb_page', 'xb_page')
    );
    $this->container->get('module_installer')->install(['metatag']);
    self::assertArrayHasKey(
      'metatags',
      $this->container->get('entity_field.manager')
        ->getFieldDefinitions('xb_page', 'xb_page')
    );
    $changes = $this->container->get('entity.definition_update_manager')->getChangeList();
    self::assertArrayNotHasKey('xb_page', $changes);

    $this->installConfig(['experience_builder']);

    $sut = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'path' => ['alias' => '/test-page'],
      'components' => [],
    ]);
    self::assertSaveWithoutViolations($sut);

    self::assertMetatags($sut, [
      [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'title',
            'content' => 'Test page |',
          ],
        ],
        'title',
      ],
      [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'description',
            'content' => 'This is a test page.',
          ],
        ],
        'description',
      ],
      [
        [
          '#tag' => 'link',
          '#attributes' => [
            'rel' => 'canonical',
            'href' => '/test-page',
          ],
        ],
        'canonical_url',
      ],
    ]);
  }

  private static function assertMetatags(Page $page, array $expected): void {
    $metatags = metatag_get_tags_from_route($page);
    self::assertEquals($expected, $metatags['#attached']['html_head']);
  }

}
