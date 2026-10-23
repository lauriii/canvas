<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\canvas\Kernel\ApiLayoutControllerTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests image props that store a focal point per image.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::FOCAL_POINT_ANNOTATION
 */
#[CoversClass(JsonSchemaPropsComponentSourceBase::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class FocalPointImagePropTest extends ApiLayoutControllerTestBase {

  use MediaTypeCreationTrait;

  protected static $modules = [
    'system',
    'user',
    'block',
    'path_alias',
    'file',
    'path',
    'image',
    'link',
    'options',
    'text',
    'media',
    'media_library',
    'views',
    'field',
    'filter',
    'ckeditor5',
    'editor',
    'canvas',
    'canvas_test_focal_point',
  ];

  /**
   * The IDs of two image media entities.
   *
   * @var list<int|string>
   */
  private array $media = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ThemeInstallerInterface::class)->install(['stark', 'canvas_stark']);
    $this->container->get(ConfigFactoryInterface::class)->getEditable('system.theme')->set('default', 'stark')->save();
    $this->installConfig(['canvas']);
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    // The media library widget previews selected media with an image style
    // and view mode that it sets up when a media type is created.
    $this->installConfig(['system', 'image', 'media_library']);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $this->setUpCurrentUser(permissions: ['view media', 'access content', Page::EDIT_PERMISSION, Page::CREATE_PERMISSION]);
    // Installs the code component.
    $this->installConfig(['canvas_test_focal_point']);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    $file_system = \Drupal::service(FileSystemInterface::class);
    foreach (['image-1.png', 'image-2.jpg'] as $name) {
      $file_system->copy(\Drupal::root() . '/core/tests/fixtures/files/' . $name, PublicStream::basePath(), FileExists::Replace);
      $file = File::create(['uri' => 'public://' . $name, 'status' => 1]);
      $file->save();
      $media = Media::create([
        'bundle' => 'image',
        'name' => $name,
        'field_media_image' => [['target_id' => $file->id(), 'alt' => $name]],
      ]);
      $media->save();
      $id = $media->id();
      \assert($id !== NULL);
      $this->media[] = $id;
    }
  }

  /**
   * Creates a page with the component.
   */
  private static function createPage(ComponentInterface $component, mixed $image_value, mixed $gallery_value): Page {
    // The collapsed input syntax: just the stored value.
    $inputs = ['image' => $image_value, 'gallery' => $gallery_value];
    $page = Page::create([
      'title' => 'Focal points',
      'components' => [
        [
          'uuid' => '5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4',
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => $inputs,
        ],
      ],
    ]);
    self::assertCount(0, $page->validate());
    $page->save();
    $page = Page::load($page->id());
    \assert($page instanceof Page);
    return $page;
  }

  /**
   * Tests that resolved images carry their focal point, or the center.
   */
  #[TestWith(['sdc.canvas_test_focal_point.focal-point-image'])]
  #[TestWith(['js.canvas_test_focal_point_image'])]
  public function testResolvedFocalPoints(string $component_id): void {
    $component = Component::load($component_id);
    \assert($component instanceof ComponentInterface);
    $page = self::createPage(
      $component,
      ['target_id' => $this->media[0], 'focal_point' => ['x' => 25, 'y' => 75]],
      [
        ['target_id' => $this->media[0], 'focal_point' => ['x' => 0, 'y' => 100]],
        ['target_id' => $this->media[1]],
        // Out of range: ignored.
        ['target_id' => $this->media[1], 'focal_point' => ['x' => 140, 'y' => 50]],
      ],
    );
    $item = $page->getComponentTree()->getComponentTreeItemByUuid('5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4');
    \assert($item !== NULL);
    $input = $component->getComponentSource()->getExplicitInput($item->getUuid(), $item);

    // The stored value keeps the focal point next to the media ID.
    self::assertSame(['x' => 25, 'y' => 75], $input['source']['image']['value']['focal_point']);
    self::assertSame(['x' => 25, 'y' => 75], $input['resolved']['image']->value['focalPoint']);
    self::assertArrayHasKey('src', $input['resolved']['image']->value);
    self::assertSame(
      [['x' => 0, 'y' => 100], ['x' => 50, 'y' => 50], ['x' => 50, 'y' => 50]],
      \array_column($input['resolved']['gallery']->value, 'focalPoint'),
    );
  }

  /**
   * Tests the focal point inputs and transforms in the component form.
   */
  public function testForm(): void {
    $component = Component::load('sdc.canvas_test_focal_point.focal-point-image');
    \assert($component instanceof ComponentInterface);
    $page = self::createPage(
      $component,
      ['target_id' => $this->media[0], 'focal_point' => ['x' => 25, 'y' => 75]],
      [['target_id' => $this->media[1]], ['target_id' => $this->media[0], 'focal_point' => ['x' => 10, 'y' => 20]]],
    );
    $item = $page->getComponentTree()->getComponentTreeItemByUuid('5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4');
    \assert($item !== NULL);
    $source = $component->getComponentSource();
    $model = $source->inputToClientModel($source->getExplicitInput($item->getUuid(), $item));

    $json = self::decodeResponse($this->request(Request::create(
      '/canvas/api/v0/form/component-instance/canvas_page/' . $page->id(),
      'PATCH',
      [
        'form_canvas_tree' => json_encode([
          'nodeType' => 'component',
          'slots' => [],
          'type' => "{$component->id()}@{$component->getActiveVersion()}",
          'uuid' => $item->getUuid(),
        ], JSON_THROW_ON_ERROR),
        'form_canvas_props' => json_encode($model, JSON_THROW_ON_ERROR),
        'form_canvas_selected' => $item->getUuid(),
      ],
    )));
    self::assertSame(['mediaFocalPoint' => ['multiple' => FALSE]], $json['transforms']['image']);
    self::assertSame(['mediaFocalPoint' => ['multiple' => TRUE]], $json['transforms']['gallery']);

    // The inputs are named by media ID, so they follow their image when the
    // images are reordered or removed.
    // The form renders with the default theme here, so this asserts the
    // widget's own markup rather than Canvas Stark's.
    // @see themes/canvas_stark/templates/container--focal-point.html.twig
    $crawler = new Crawler($json['html']);
    $value = static fn (string $name): ?string => $crawler->filter("input[name$='$name']")->count() ? $crawler->filter("input[name$='$name']")->attr('value') : NULL;
    self::assertSame('25', $value("[image][focal_points][media_{$this->media[0]}][x]"));
    self::assertSame('75', $value("[image][focal_points][media_{$this->media[0]}][y]"));
    self::assertSame('10', $value("[gallery][focal_points][media_{$this->media[0]}][x]"));
    self::assertSame('', $value("[gallery][focal_points][media_{$this->media[1]}][x]"));
    // Each image's picker previews that image.
    $sources = $crawler->filter('[data-canvas-focal-point-src]')->each(static fn (Crawler $node): string => (string) $node->attr('data-canvas-focal-point-src'));
    self::assertCount(3, $sources);
    self::assertStringContainsString('image-1', $sources[0]);
    self::assertStringContainsString('image-2', $sources[1]);
  }

  /**
   * Tests that only image props can store focal points.
   */
  public function testAnnotationRequiresImage(): void {
    $code_component = JavaScriptComponent::create([
      'machineName' => 'focal_point_on_a_string',
      'name' => 'Focal point on a string',
      'status' => FALSE,
      'props' => [
        'heading' => [
          'title' => 'Heading',
          'type' => 'string',
          'examples' => ['Hello'],
          JsonSchemaPropsComponentSourceBase::FOCAL_POINT_ANNOTATION => TRUE,
        ],
      ],
      'required' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ]);
    $messages = [];
    foreach ($code_component->getTypedData()->validate() as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    self::assertContains('"x-canvas-focal-point" on prop "heading" requires an image prop or a list of images.', $messages);
  }

}
