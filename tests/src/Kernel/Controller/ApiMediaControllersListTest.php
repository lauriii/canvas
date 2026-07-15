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
    // Provides a key-value-driven hook that denies view access to specific
    // media entities, making per-entity access stricter than query access.
    'canvas_test_access',
  ];

  private const string URL = '/canvas/api/v0/media/%s';

  /**
   * The media entity IDs, keyed by label.
   *
   * @var array<string, int>
   */
  private array $mediaIds = [];

  /**
   * The name of the image media type's source field.
   */
  private string $sourceFieldName;

  /**
   * The ID of the single image file backing all media entities.
   */
  private int $fileId;

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
    $this->sourceFieldName = $source_field_definition->getName();
    $this->fileId = (int) $file->id();

    // 26 published "Dog NN" media, with descending `changed` timestamps so
    // "Dog 01" is the most recently changed, plus one published "Cat 01" (the
    // oldest) and one unpublished "Secret Dog".
    $base_changed = 2_000_000_000;
    for ($i = 1; $i <= 26; $i++) {
      $label = \sprintf('Dog %02d', $i);
      $this->mediaIds[$label] = $this->createMediaEntity($label, $base_changed - $i, TRUE);
    }
    $this->mediaIds['Cat 01'] = $this->createMediaEntity('Cat 01', $base_changed - 27, TRUE);
    $this->mediaIds['Secret Dog'] = $this->createMediaEntity('Secret Dog', $base_changed - 28, FALSE);
  }

  /**
   * Creates an image media entity and returns its ID.
   */
  private function createMediaEntity(string $label, int $changed, bool $published): int {
    $media = Media::create([
      'bundle' => 'image',
      'name' => $label,
      'status' => $published ? 1 : 0,
      'changed' => $changed,
      $this->sourceFieldName => ['target_id' => $this->fileId, 'alt' => 'Test image'],
    ]);
    $media->save();
    return (int) $media->id();
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

  /**
   * Tests that entities denied by per-entity access do not consume page slots.
   *
   * Entity query access and per-entity access can diverge (e.g. a contrib
   * access hook): denied entities must not consume page slots, while the
   * pager total — computed from the count query — may overcount them.
   */
  public function testListPerEntityAccessDivergence(): void {
    // A published media entity that query access allows but per-entity access
    // denies; it is the most recently changed, so it is the first query row
    // of page 0.
    $forbidden_id = $this->createMediaEntity('Forbidden Dog', 2_000_000_000, TRUE);
    $this->container->get('keyvalue')->get('canvas_test_access')->set('deny_view_media_ids', [$forbidden_id]);

    // Page 0 is still filled with 24 accessible items: the denied entity does
    // not consume a page slot. The total (28) comes from the count query, so
    // it includes the denied entity.
    $data = $this->requestList();
    self::assertSame(['page' => 0, 'perPage' => 24, 'total' => 28], $data['pager']);
    self::assertSame(
      \array_map(static fn (int $i): string => \sprintf('Dog %02d', $i), \range(1, 24)),
      \array_column($data['items'], 'label'),
    );

    // The next page contains the remaining accessible entities. Page offsets
    // are computed in query-row space, so "Dog 24" — pulled forward onto page
    // 0 to fill the slot the denied entity would have consumed — appears
    // again: an accepted consequence of keeping the page scan bounded.
    $data = $this->requestList(['page' => 1]);
    self::assertSame(['page' => 1, 'perPage' => 24, 'total' => 28], $data['pager']);
    self::assertSame(['Dog 24', 'Dog 25', 'Dog 26', 'Cat 01'], \array_column($data['items'], 'label'));
  }

}
