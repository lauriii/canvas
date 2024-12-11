<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Entity;

use Drupal\experience_builder\Entity\Page;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\PageTrait;

/**
 * @group experience_builder
 * @requires function Drupal\metatag\MetatagManager::tagsFromEntity
 */
final class PageMetatagIntegrationTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use PageTrait;
  use TestFileCreationTrait;

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
    $this->installPageEntitySchema();
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('media');
    $this->installEntitySchema('user');
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

    $media_type = $this->createMediaType('image');
    $image_file = File::create([
      // @phpstan-ignore-next-line
      'uri' => $this->getTestFiles('image')[0]->uri,
    ]);
    $image_file->save();
    $media_image = Media::create([
      'bundle' => $media_type->id(),
      'name' => 'Test image',
      'field_media_image' => [
        'target_id' => $image_file->id(),
        'alt' => 'default alt',
        'title' => 'default title',
      ],
    ]);
    $media_image->save();

    $sut = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'path' => ['alias' => '/test-page'],
      'components' => [],
      'image' => $media_image->id(),
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
      [
        [
          '#tag' => 'link',
          '#attributes' => [
            'rel' => 'image_src',
            'href' => $image_file->createFileUrl(FALSE),
          ],
        ],
        'image_src',
      ],
    ]);
  }

  private static function assertMetatags(Page $page, array $expected): void {
    $metatags = metatag_get_tags_from_route($page);
    self::assertEquals($expected, $metatags['#attached']['html_head']);
  }

}
