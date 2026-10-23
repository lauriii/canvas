<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas_headless\ExternalComponentSync;
use Drupal\canvas_headless\FrontendUrl;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that headless frontends receive each image's focal point.
 *
 * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef::IMAGE_WITH_FOCAL_POINT
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
final class FocalPointContentTest extends CanvasKernelTestBase {

  use MediaTypeCreationTrait;
  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'serialization',
    'consumers',
    'simple_oauth',
    'custom_elements',
    'canvas_headless',
    'canvas_test_focal_point',
  ];

  /**
   * Tests local and external code components in the content API.
   */
  public function testFocalPoints(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('media');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installConfig(['image', 'media_library', 'canvas_headless', 'canvas_test_focal_point']);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    // An external component, synchronized from the headless frontend's
    // component metadata.
    $frontend = FrontendUrl::fromConfig('https://frontend.example');
    self::assertInstanceOf(FrontendUrl::class, $frontend);
    $result = $this->container->get(ExternalComponentSync::class)->synchronize($frontend, [
      'version' => 1,
      'components' => [
        [
          'machineName' => 'focal_point_external',
          'name' => 'Focal point external',
          'status' => TRUE,
          'required' => ['image'],
          'props' => [
            'image' => [
              'title' => 'Image',
              'type' => 'object',
              '$ref' => JsonSchemaObjectRef::IMAGE_WITH_FOCAL_POINT,
              'examples' => [['src' => 'https://placehold.co/1200x600.png', 'width' => 1200, 'height' => 600, 'alt' => 'A placeholder']],
            ],
          ],
          'slots' => [],
        ],
      ],
    ]);
    self::assertSame([], $result['errors']);
    self::assertSame(1, $result['created']);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    $media = [];
    foreach (['image-1.png', 'image-2.jpg'] as $name) {
      $this->container->get(FileSystemInterface::class)->copy($this->root . '/core/tests/fixtures/files/' . $name, PublicStream::basePath(), FileExists::Replace);
      $file = File::create(['uri' => 'public://' . $name, 'status' => 1]);
      $file->save();
      $entity = Media::create([
        'bundle' => 'image',
        'name' => $name,
        'field_media_image' => [['target_id' => $file->id(), 'alt' => $name]],
      ]);
      $entity->save();
      $media[] = $entity->id();
    }

    // Burn uid 1, which bypasses access checks.
    $this->createUser();
    $this->setUpCurrentUser(permissions: ['access content', 'view media']);
    $page = Page::create([
      'title' => 'Focal points',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => '5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4',
          'component_id' => 'js.canvas_test_focal_point_image',
          'inputs' => [
            'image' => ['target_id' => $media[0], 'focal_point' => ['x' => 25, 'y' => 75]],
            'gallery' => [['target_id' => $media[1]]],
          ],
        ],
        [
          'uuid' => 'f0b54c8e-7f43-4b0f-a1b4-0f0e8e9f2c11',
          'component_id' => 'js.focal_point_external',
          'inputs' => [
            'image' => ['target_id' => $media[1], 'focal_point' => ['x' => 10, 'y' => 20]],
          ],
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $response = $this->request(Request::create('/canvas/content-api?' . http_build_query(['requestUri' => '/page/' . $page->id()])));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $data = \json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $props = self::propsByElement($data['content']);

    $local = $props['js-canvas-test-focal-point-image'];
    self::assertSame(['x' => 25, 'y' => 75], $local['image']['focalPoint']);
    self::assertStringContainsString('image-1', $local['image']['src']);
    // Images without a focal point get the center.
    self::assertSame(['x' => 50, 'y' => 50], $local['gallery'][0]['focalPoint']);

    $external = $props['js-focal-point-external'];
    self::assertSame(['x' => 10, 'y' => 20], $external['image']['focalPoint']);
    self::assertStringContainsString('image-2', $external['image']['src']);
  }

  /**
   * Collects the props of each custom element in a content tree.
   *
   * @return array<string, array<string, mixed>>
   *   Props keyed by element name.
   */
  private static function propsByElement(array $tree): array {
    $props = [];
    if (\is_string($tree['element'] ?? NULL) && \is_array($tree['props'] ?? NULL)) {
      $props[$tree['element']] = $tree['props'];
    }
    foreach ($tree as $child) {
      if (\is_array($child)) {
        $props += self::propsByElement($child);
      }
    }
    return $props;
  }

}
