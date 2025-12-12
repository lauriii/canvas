<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\PropShape\PersistentPropShapeRepository;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests the most complex prop expression dependencies functionally.
 *
 * - Functional tests are most realistic, but are slow.
 * - Kernel tests risk simulating only a subset of reality, but are faster.
 *
 * This functional test then complements the much more complete kernel test
 * coverage to keep the kernel tests "honest".
 *
 * @see \Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testCalculateDependencies()
 *
 * @group canvas
 */
class PropExpressionDependenciesTest extends FunctionalTestBase {

  use EntityReferenceFieldCreationTrait;
  use ImageFieldCreationTrait;
  use MediaTypeCreationTrait;
  use GenerateComponentConfigTrait;

  const SDC_IMAGE_COMPONENT = 'sdc.canvas_test_sdc.image';
  const JS_IMAGE_COMPONENT = 'js.imagine';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'node',
    'system',
    'taxonomy',
    'text',
    'filter',
    'user',
    'file',
    'image',
    'media',
    'media_library',
    'views',
    'path',
    'canvas_test_sdc',
    // Ensure field type overrides are installed and hence testable.
    'canvas',
  ];

  protected $defaultTheme = 'stark';

  /**
   * Ensures multiple references are tested correctly.
   *
   * This is the functional test equivalent for the "Reference field type that
   * fetches a reference of a reference." test case in the kernel test coverage.
   *
   * This is also a functional test complement for StorablePropShape altering.
   *
   * @see \Drupal\Tests\canvas\Kernel\PropShapeRepositoryTest::testStorablePropShapeAlter()
   */
  #[TestWith([self::SDC_IMAGE_COMPONENT])]
  #[TestWith([self::JS_IMAGE_COMPONENT])]
  public function testIntermediateDependencies(string $component_id): void {
    if ($component_id === self::JS_IMAGE_COMPONENT) {
      $js_component = JavaScriptComponent::create([
        'machineName' => 'imagine',
        'name' => $this->getRandomGenerator()->sentences(5),
        'status' => TRUE,
        'props' => [
          'image' => [
            'title' => 'Image',
            'type' => 'object',
            'examples' => [
              [
                'src' => 'https://placehold.co/1200x900@2x.png',
                'width' => 1200,
                'height' => 900,
                'alt' => 'Example image placeholder',
              ],
            ],
            '$ref' => 'json-schema-definitions://canvas.module/image',
          ],
        ],
        'required' => ['image'],
        'slots' => [],
        'css' => [
          'original' => '',
          'compiled' => '',
        ],
        'js' => [
          'original' => '',
          'compiled' => '',
        ],
        'dataDependencies' => [],
      ]);
      self::assertCount(0, $js_component->getTypedData()->validate());
      $js_component->save();
    }

    $imageComponent = Component::load($component_id);
    \assert($imageComponent instanceof ComponentInterface);
    $imageComponentSource = $imageComponent->getComponentSource();
    \assert($imageComponentSource instanceof GeneratedFieldExplicitInputUxComponentSourceBase);
    // @phpstan-ignore-next-line
    $expression_string = $imageComponentSource->getDefaultExplicitInput()['image']['expression'];
    self::assertStringNotContainsString('entity:media', $expression_string);

    $propShapeRepository = $this->container->get(PropShapeRepositoryInterface::class);
    self::assertInstanceOf(PersistentPropShapeRepository::class, $propShapeRepository);
    // Trigger a cache-write in PropShapeRepository — this happens on kernel
    // shutdown normally, but in a test we need to call it manually.
    $propShapeRepository->destruct();
    $propShapeRepository->reset();

    $image_uri = $this->getRandomGenerator()
      ->image(uniqid('public://') . '.png', '200x200', '400x400');
    $this->assertFileExists($image_uri);
    $file = File::create(['uri' => $image_uri]);
    $file->save();

    // Creating a MediaType should regenerate the Component.
    // @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
    $mediaType = $this->createMediaType('image');

    // Trigger a cache write in PropShapeRepository — this happens on kernel
    // shutdown normally, but in a test we need to call it manually.
    $propShapeRepository->destruct();

    $media = Media::create([
      'bundle' => $mediaType->id(),
      'name' => 'Test image',
      'field_media_image' => $file,
    ]);
    $media->save();

    // @phpstan-ignore-next-line
    $expression_string = Component::load($component_id)->getComponentSource()->getDefaultExplicitInput()['image']['expression'];
    self::assertStringContainsString('entity:media', $expression_string);

    $page = Page::create([
      'title' => 'A simple page',
      'components' => [
        // An image: references a media item.
        [
          'uuid' => 'c990c4ee-341a-4f38-ab5d-e75b3de1fa1f',
          'component_id' => $component_id,
          'component_version' => Component::load($component_id)?->getActiveVersion(),
          'inputs' => [
            'image' => [
              'target_id' => $media->id(),
            ],
          ],
        ],
      ],
    ]);
    $page->save();

    $item = $page->getComponentTree()->first();
    self::assertNotNull($item);

    $deps = $item->calculateFieldItemValueDependencies($page);
    self::assertArrayHasKey('content', $deps);

    self::assertSame([
      $file->getConfigDependencyName(),
      $media->getConfigDependencyName(),
    ], $deps['content']);
  }

}
