<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Entity;

use Drupal\Component\Serialization\Json;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\PageTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;

final class PageTest extends KernelTestBase {

  use GenerateComponentConfigTrait;
  use MediaTypeCreationTrait;
  use PageTrait;
  use TestDataUtilitiesTrait;

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

  public function testDefinition(): void {
    $sut = $this->container->get('entity_type.manager')
      ->getDefinition('xb_page');
    self::assertNotNull($sut);
    self::assertEquals(
      [
        'canonical' => '/page/{xb_page}',
        'delete-form' => '/page/{xb_page}/delete',
        'edit-form' => '/page/{xb_page}/edit',
        'create' => '/page/add',
        'revision_delete_form' => '/page/{xb_page}/revisions/{xb_page_revision}/delete',
        'revision_revert_form' => '/page/{xb_page}/revisions/{xb_page_revision}/revert',
        'version-history' => '/page/{xb_page}/revisions',
      ],
      $sut->getLinkTemplates()
    );
  }

  public function testImageFieldDefinition(): void {
    $image_media_type = $this->createMediaType('image');
    // Create a `file` media type to ensure that the field definition is
    // correctly filtered to only allow media types that use `image`.
    $this->createMediaType('file');

    $fields = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('xb_page', 'xb_page');
    self::assertArrayHasKey('image', $fields);
    $field = $fields['image'];
    self::assertEquals([
      'target_type' => 'media',
      'handler' => 'default',
      'handler_settings' => [
        'target_bundles' => [$image_media_type->id()],
      ],
    ], $field->getSettings());
    self::assertEquals([
      'type' => 'media_library_widget',
      'settings' => [
        'media_types' => [],
      ],
    ], $field->getDisplayOptions('form'));

    // Verify adding a new media type causes the base field's settings to be
    // automatically updated.
    $second_image_media_type = $this->createMediaType('image');
    $fields = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('xb_page', 'xb_page');
    self::assertArrayHasKey('image', $fields);
    $field = $fields['image'];
    self::assertEqualsCanonicalizing([
      'target_type' => 'media',
      'handler' => 'default',
      'handler_settings' => [
        'target_bundles' => [$image_media_type->id(), $second_image_media_type->id()],
      ],
    ], $field->getSettings());

  }

  public function testEntity(): void {
    $test_heading_text = $this->randomString();

    $this->generateComponentConfig();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('xb_page');

    $sut = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'path' => ['alias' => '/test-page'],
      'components' => [
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [
            [
              'uuid' => 'component-sdc',
              'component' => 'sdc.xb_test_sdc.props-slots',
            ],
            [
              'uuid' => 'component-block',
              'component' => 'block.system_branding_block',
            ],
          ],
        ]),
        'props' => self::encodeXBData([
          'component-sdc' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => $test_heading_text,
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'component-block' => [],
        ]),
      ],
    ]);
    self::assertSaveWithoutViolations($sut);
    self::assertEquals('Test page', $sut->label());
    self::assertEquals('This is a test page.', $sut->description->value);
    self::assertEquals('/test-page', $sut->get('path')->first()?->getValue()['alias']);

    $components = $sut->components->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $components);
    self::assertEquals(
      [
        ComponentTreeStructure::ROOT_UUID => [
          'component-sdc' => [
            'component' => 'sdc.xb_test_sdc.props-slots',
            'props' => [
              'heading' => $test_heading_text,
            ],
            'slots' => [
              'the_body' => '<p>Example value for <strong>the_body</strong> slot in <strong>prop-slots</strong> component.</p>',
              'the_footer' => 'Example value for <strong>the_footer</strong>.',
              'the_colophon' => '',
            ],
          ],
          'component-block' => [
            'component' => 'block.system_branding_block',
            'settings' => [],
          ],
        ],
      ],
      Json::decode($components->hydrated->getContent() ?: '')
    );
  }

}
