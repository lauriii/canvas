<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_oauth\Kernel;

use Drupal\canvas_oauth\MediaScopesHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests OAuth2 scope creation for media types.
 */
#[CoversClass(MediaScopesHelper::class)]
#[Group('canvas_oauth')]
class MediaScopesHelperTest extends CanvasKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'consumers',
    'canvas_oauth',
    'simple_oauth',
    'serialization',
    'image',
    'media',
    'file',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('media');
    $this->installEntitySchema('file');
    $this->installConfig(['field', 'system']);
  }

  /**
   * Tests that image and file media types get create scopes, others do not.
   */
  public function testEnsureMediaScopes(): void {
    $this->createMediaType('image', ['id' => 'photos']);
    $this->createMediaType('file', ['id' => 'documents']);
    $this->createMediaType('audio_file', ['id' => 'podcasts']);
    $this->createMediaType('video_file', ['id' => 'clips']);

    \Drupal::classResolver(MediaScopesHelper::class)->ensureMediaScopes();

    $scope_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('oauth2_scope');
    $this->assertNotNull($scope_storage->load('canvas_media_photos_create'));
    $this->assertNotNull($scope_storage->load('canvas_media_documents_create'));
    $this->assertNull($scope_storage->load('canvas_media_podcasts_create'));
    $this->assertNull($scope_storage->load('canvas_media_clips_create'));

    $document_scope = $scope_storage->load('canvas_media_documents_create');
    \assert($document_scope instanceof Oauth2Scope);
    $this->assertSame('canvas:media:documents:create', $document_scope->getName());

    // Re-running must not error or duplicate scopes.
    \Drupal::classResolver(MediaScopesHelper::class)->ensureMediaScopes();
    $this->assertCount(2, \array_filter(
      $scope_storage->loadMultiple(),
      static fn (object $scope): bool => \str_starts_with((string) $scope->id(), 'canvas_media_') && \str_ends_with((string) $scope->id(), '_create'),
    ));
  }

}
