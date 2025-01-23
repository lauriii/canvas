<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Entity;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Entity\PageViewBuilder;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Kernel\Traits\PageTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;

/**
 * @group experience_builder
 */
final class PageViewBuilderTest extends KernelTestBase {

  use GenerateComponentConfigTrait;
  use PageTrait;
  use RequestTrait;
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

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
    $this->installPageEntitySchema();

    $this->config('system.site')
      ->set('name', 'XB Test Site')
      ->set('slogan', 'Experience Builder Test Site')
      ->save();
  }

  public function testView(): void {
    $test_heading_text = $this->randomString();
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
          'component-block' => [
            'use_site_logo' => TRUE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
            'label_display' => FALSE,
            'label' => '',
          ],
        ]),
      ],
      'xb_test_field' => '3rd party based field should not be displayed!',
    ]);
    self::assertSaveWithoutViolations($sut);
    self::assertEquals(
      '3rd party based field should not be displayed!',
      $sut->xb_test_field->value
    );

    $view_builder = $this->container->get('entity_type.manager')->getViewBuilder('xb_page');
    self::assertInstanceOf(PageViewBuilder::class, $view_builder);

    // Verify `xb_test_field` is part of the display components, but then is not
    // rendered later.
    $build = [$sut->id() => []];
    $view_builder->buildComponents(
      $build,
      [$sut->id() => $sut],
      ['xb_page' => EntityViewDisplay::collectRenderDisplay($sut, 'default')],
      'default'
    );
    self::assertArrayHasKey('components', $build[$sut->id()]);
    self::assertArrayHasKey('xb_test_field', $build[$sut->id()]);

    // Render the page and verify the expected output. The content of
    // `xb_test_field` should not be rendered.
    $build = $view_builder->view($sut);
    $this->render($build);

    self::assertStringNotContainsString('Components', $this->getTextContent());
    self::assertStringNotContainsString($sut->description->value, $this->getTextContent());

    self::assertStringNotContainsString('Test field', $this->getTextContent());
    self::assertStringNotContainsString('3rd party based field should not be displayed!', $this->getTextContent());

    self::assertCount(1, $this->cssSelect('[data-component-id="xb_test_sdc:props-slots"]'));
    self::assertCount(1, $this->cssSelect('[data-component-id="xb_test_sdc:props-slots"] .component--props-slots--body'));
    self::assertCount(1, $this->cssSelect('[data-component-id="xb_test_sdc:props-slots"] .component--props-slots--footer'));
    self::assertCount(1, $this->cssSelect('[data-component-id="xb_test_sdc:props-slots"] .component--props-slots--colophon'));
    self::assertEquals(
      $test_heading_text,
      (string) $this->cssSelect('[data-component-id="xb_test_sdc:props-slots"] h1')[0]
    );

    self::assertStringContainsString('<a href="/" rel="home">XB Test Site</a>', $this->getRawContent());
    self::assertStringContainsString('Experience Builder Test Site', $this->getTextContent());

    // Verify `xb_test_page_xb_page_view` output was ignored, but attachments
    // were allowed.
    self::assertArrayHasKey('xb_test_page', $this->drupalSettings);
    self::assertEquals(['foo' => 'Bar'], $this->drupalSettings['xb_test_page']);
    self::assertStringNotContainsString('xb_test_page_xb_page_view markup', $this->getRawContent());
  }

  public function testConfiguredViewDisplayNotAllowed(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Pages do not have configurable view displays. The view display is computed from base field definitions, to ensure there is never a need for an update path.');

    EntityViewDisplay::create([
      'targetEntityType' => 'xb_page',
      'bundle' => 'xb_page',
      'mode' => 'default',
      'status' => TRUE,
    ])->save();

    $sut = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'path' => ['alias' => '/test-page'],
      'components' => [
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [],
        ]),
        'props' => self::encodeXBData([]),
      ],
    ]);
    self::assertSaveWithoutViolations($sut);

    $view_builder = $this->container->get('entity_type.manager')->getViewBuilder('xb_page');
    $build = $view_builder->view($sut);
    $this->render($build);
  }

}
