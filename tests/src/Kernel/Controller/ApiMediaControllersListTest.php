<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\Controller\ApiMediaControllers;
use Drupal\Core\File\FileExists;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiMediaControllers::class)]
#[CoversMethod(ApiMediaControllers::class, 'list')]
final class ApiMediaControllersListTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use MediaTypeCreationTrait;
  use RequestTrait;
  use VfsPublicStreamUrlTrait;
  use PredictableImageStyleItokTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
  ];

  private const string URL = '/canvas/api/v0/media/%s';

  /**
   * The media entity IDs, keyed by label.
   *
   * @var array<string, int>
   */
  private array $mediaIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    // The `medium` image style — used for the browse thumbnails — ships with
    // the `image` module.
    $this->installConfig(['field', 'image']);

    $this->setupPredictableItok();

    $media_type = $this->createMediaType('image', [
      'id' => 'image',
      'label' => 'Image',
    ]);
    // `administer code components` grants Canvas UI access.
    // @see \Drupal\canvas\Access\CanvasUiAccessCheck
    $this->setUpCurrentUser([], ['administer code components', 'access content', 'view media']);

    // A single image file backing all media entities.
    $source = \dirname(__DIR__, 3) . '/fixtures/images/gracie-big.jpg';
    $this->container->get('file_system')->copy($source, 'public://gracie-big.jpg', FileExists::Replace);
    $file = File::create(['uri' => 'public://gracie-big.jpg']);
    $file->setPermanent();
    $file->save();

    $source_field_definition = $media_type->getSource()->getSourceFieldDefinition($media_type);
    \assert($source_field_definition !== NULL);
    $source_field_name = $source_field_definition->getName();

    // 26 published "Dog NN" media, with descending `changed` timestamps so
    // "Dog 01" is the most recently changed, plus one published "Cat 01" (the
    // oldest) and one unpublished "Secret Dog".
    $base_changed = 2_000_000_000;
    $create_media = function (string $label, int $changed, bool $published) use ($file, $source_field_name): int {
      $media = Media::create([
        'bundle' => 'image',
        'name' => $label,
        'status' => $published ? 1 : 0,
        'changed' => $changed,
        $source_field_name => ['target_id' => $file->id(), 'alt' => 'Test image'],
      ]);
      $media->save();
      return (int) $media->id();
    };
    for ($i = 1; $i <= 26; $i++) {
      $label = \sprintf('Dog %02d', $i);
      $this->mediaIds[$label] = $create_media($label, $base_changed - $i, TRUE);
    }
    $this->mediaIds['Cat 01'] = $create_media('Cat 01', $base_changed - 27, TRUE);
    $this->mediaIds['Secret Dog'] = $create_media('Secret Dog', $base_changed - 28, FALSE);
  }

  /**
   * Performs a list request and returns the decoded response.
   */
  private function requestList(array $query = []): array {
    $response = $this->request(Request::create(\sprintf(self::URL, 'image'), 'GET', $query));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    return self::decodeResponse($response);
  }

  /**
   * Tests the response shape, paging, and exclusion of unpublished media.
   */
  public function testListShapeAndPaging(): void {
    $data = $this->requestList();
    self::assertSame(['items', 'pager'], \array_keys($data));
    // 27 published media exist; the unpublished "Secret Dog" is excluded.
    self::assertSame(['page' => 0, 'perPage' => 24, 'total' => 27], $data['pager']);
    self::assertCount(24, $data['items']);
    // Sorted by changed DESC: the first page is "Dog 01" … "Dog 24".
    self::assertSame(
      \array_map(static fn (int $i): string => \sprintf('Dog %02d', $i), \range(1, 24)),
      \array_column($data['items'], 'label'),
    );

    // Assert the exact shape of a single item.
    $first = $data['items'][0];
    self::assertSame(['id', 'uuid', 'label', 'thumbnailUrl', 'inputs_resolved'], \array_keys($first));
    self::assertSame($this->mediaIds['Dog 01'], $first['id']);
    self::assertSame('Dog 01', $first['label']);
    self::assertIsString($first['uuid']);
    self::assertIsString($first['thumbnailUrl']);
    self::assertStringContainsString('/files/styles/medium/', $first['thumbnailUrl']);
    self::assertStringContainsString('gracie-big.jpg', $first['thumbnailUrl']);
    self::assertIsArray($first['inputs_resolved']);
    self::assertSame(['src', 'alt', 'width', 'height'], \array_keys($first['inputs_resolved']));
    self::assertStringContainsString('gracie-big.jpg', $first['inputs_resolved']['src']);
    self::assertSame('Test image', $first['inputs_resolved']['alt']);
    self::assertSame(3000, $first['inputs_resolved']['width']);
    self::assertSame(2595, $first['inputs_resolved']['height']);

    // The second page contains the remaining 3 published media.
    $data = $this->requestList(['page' => 1]);
    self::assertSame(['page' => 1, 'perPage' => 24, 'total' => 27], $data['pager']);
    self::assertSame(['Dog 25', 'Dog 26', 'Cat 01'], \array_column($data['items'], 'label'));
  }

  /**
   * Tests the `search` query parameter.
   */
  public function testListSearch(): void {
    $data = $this->requestList(['search' => 'Cat']);
    self::assertSame(['page' => 0, 'perPage' => 24, 'total' => 1], $data['pager']);
    self::assertSame(['Cat 01'], \array_column($data['items'], 'label'));

    // The unpublished "Secret Dog" does not appear in search results either.
    $data = $this->requestList(['search' => 'Secret']);
    self::assertSame(['page' => 0, 'perPage' => 24, 'total' => 0], $data['pager']);
    self::assertSame([], $data['items']);

    $data = $this->requestList(['search' => 'Dog']);
    self::assertSame(['page' => 0, 'perPage' => 24, 'total' => 26], $data['pager']);
    self::assertCount(24, $data['items']);
  }

  /**
   * Tests the `ids` query parameter.
   */
  public function testListIds(): void {
    // Exactly the requested (published) entities are returned, without
    // paging; the unpublished "Secret Dog" is excluded even when explicitly
    // requested.
    $ids = \implode(',', [
      $this->mediaIds['Dog 26'],
      $this->mediaIds['Cat 01'],
      $this->mediaIds['Secret Dog'],
    ]);
    $data = $this->requestList(['ids' => $ids]);
    self::assertSame(['page' => 0, 'perPage' => 24, 'total' => 2], $data['pager']);
    self::assertSame(['Dog 26', 'Cat 01'], \array_column($data['items'], 'label'));
    self::assertSame([$this->mediaIds['Dog 26'], $this->mediaIds['Cat 01']], \array_column($data['items'], 'id'));

    // When `ids` is present, paging is ignored.
    $data = $this->requestList(['ids' => $ids, 'page' => 5]);
    self::assertSame(['page' => 0, 'perPage' => 24, 'total' => 2], $data['pager']);
    self::assertSame(['Dog 26', 'Cat 01'], \array_column($data['items'], 'label'));
  }

}
