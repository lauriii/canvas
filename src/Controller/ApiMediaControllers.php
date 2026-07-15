<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\FileUploadLocationTrait;
use Drupal\file\Upload\FormUploadedFile;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * HTTP API for interacting with Media library.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiMediaControllers extends ApiControllerBase {

  use FileUploadLocationTrait;

  /**
   * The number of media items per page in ::list().
   */
  private const int PER_PAGE = 24;

  /**
   * The image style used for browse thumbnails in ::list().
   *
   * Hardcoded v1 decision: the `medium` style ships with the `image` module
   * (a Canvas dependency), so it exists on install; it is also what core's
   * Media Library uses for its grid thumbnails.
   */
  private const string THUMBNAIL_IMAGE_STYLE = 'medium';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUploadHandlerInterface $fileUploadHandler,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Lists media entities of a media type, for the native media browse widget.
   *
   * Query parameters:
   * - search: filters on the media label (CONTAINS).
   * - page: 0-based page number, 24 items per page.
   * - ids: comma-separated media entity IDs; when present, exactly those
   *   entities of this bundle are returned, without paging.
   */
  public function list(MediaTypeInterface $media_type, Request $request): JsonResponse {
    $media_storage = $this->entityTypeManager->getStorage('media');
    $query = $media_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('bundle', $media_type->id())
      ->condition('status', 1);
    $search = $request->query->getString('search');
    if ($search !== '') {
      $query->condition('name', $search, 'CONTAINS');
    }
    $page = \max(0, $request->query->getInt('page'));
    $ids = \array_filter(\array_map(\trim(...), \explode(',', $request->query->getString('ids'))), static fn (string $id): bool => $id !== '');
    if ($ids !== []) {
      $query->condition('mid', $ids, 'IN');
      $page = 0;
    }
    $count_query = clone $query;
    $total = (int) $count_query->count()->execute();
    $query->sort('changed', 'DESC');
    if ($ids === []) {
      $query->range($page * self::PER_PAGE, self::PER_PAGE);
    }

    $is_image_source = $media_type->getSource()->getPluginId() === 'image';
    $items = [];
    foreach ($media_storage->loadMultiple($query->execute()) as $media) {
      \assert($media instanceof MediaInterface);
      // The entity query already applied access, but double-check on the
      // loaded entities: entity query access and entity access can diverge.
      if (!$media->access('view')) {
        continue;
      }
      $items[] = [
        'id' => (int) $media->id(),
        'uuid' => $media->uuid(),
        'label' => (string) $media->label(),
        'thumbnailUrl' => $this->buildThumbnailUrl($media),
        'inputs_resolved' => $is_image_source ? $this->getInputsResolved($media) : NULL,
      ];
    }

    // Deliberately not cacheable: the list is user-specific (entity access)
    // and changes with every media change.
    return new JsonResponse([
      'items' => $items,
      'pager' => [
        'page' => $page,
        'perPage' => self::PER_PAGE,
        'total' => $total,
      ],
    ]);
  }

  /**
   * Builds the browse thumbnail URL for a media entity.
   *
   * Uses the media thumbnail (which every media source provides, falling back
   * to a generic icon) rendered through the hardcoded thumbnail image style.
   *
   * @return string|null
   *   A root-relative thumbnail URL, or NULL if the media has no thumbnail.
   */
  private function buildThumbnailUrl(MediaInterface $media): ?string {
    $thumbnail_file = $media->get('thumbnail')->entity;
    if (!$thumbnail_file instanceof FileInterface) {
      return NULL;
    }
    $uri = $thumbnail_file->getFileUri();
    \assert(\is_string($uri));
    $image_style = $this->entityTypeManager->getStorage('image_style')->load(self::THUMBNAIL_IMAGE_STYLE);
    if ($image_style instanceof ImageStyleInterface && $image_style->supportsUri($uri)) {
      return $this->fileUrlGenerator->transformRelative($image_style->buildUrl($uri));
    }
    return $this->fileUrlGenerator->generateString($uri);
  }

  public function upload(MediaTypeInterface $media_type, Request $request): JsonResponse {
    \assert($request->getContentTypeFormat() === 'form');
    $media_type_id = $media_type->id();
    if ($media_type->getSource()->getPluginId() !== 'image') {
      return new JsonResponse(
        [
          'errors' => [
            [
              'detail' => \sprintf("The media type '%s' is not an image media type.", $media_type_id),
              'source' => [
                'pointer' => $media_type_id,
              ],
            ],
          ],
        ],
        Response::HTTP_BAD_REQUEST
      );
    }
    $source_field_definition = $media_type->getSource()->getSourceFieldDefinition($media_type);
    if ($source_field_definition === NULL) {
      return new JsonResponse(
        [
          'errors' => [
            [
              'detail' => \sprintf("The media type '%s' has no source field.", $media_type_id),
              'source' => [
                'pointer' => $media_type_id,
              ],
            ],
          ],
        ],
        Response::HTTP_BAD_REQUEST
      );
    }

    $upload_location = $this->getUploadLocation($source_field_definition);

    // Check the destination file path is writable.
    if (!$this->fileSystem->prepareDirectory($upload_location, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new HttpException(500, 'Destination file path is not writable');
    }

    // Read validators from the source field settings, with sensible fallbacks.
    $file_extensions = $source_field_definition->getSetting('file_extensions');
    $extensions = $file_extensions !== '' && $file_extensions !== NULL
      ? $file_extensions
      : 'png gif jpg jpeg webp avif';
    $max_filesize = $source_field_definition->getSetting('max_filesize');

    $file = $request->files->get('file');
    if (!$file instanceof UploadedFile) {
      return new JsonResponse(
        [
          'errors' => [
            [
              'detail' => 'No file was uploaded. The "file" field is required.',
              'source' => [
                'pointer' => 'file',
              ],
            ],
          ],
        ],
        Response::HTTP_BAD_REQUEST
      );
    }
    $uploaded_file = new FormUploadedFile($file);
    $file_upload_result = $this->fileUploadHandler->handleFileUpload(
      $uploaded_file,
      validators: [
        'FileNameLength' => [],

        'FileExtension' => ['extensions' => $extensions],
        'FileSizeLimit' => ['fileLimit' => $max_filesize ? Bytes::toNumber($max_filesize) : Environment::getUploadMaxSize()],
        // Ensure that the file contents, not only the extension, is an image.
        'FileIsImage' => [],
      ],
      destination: $upload_location,
      fileExists: FileExists::Rename,
    );
    if ($file_upload_result->hasViolations()) {
      $violations = $file_upload_result->getViolations();
      \assert($violations instanceof ConstraintViolationList);
      if ($validation_errors_response = self::createJsonResponseFromViolationSets($violations)) {
        return $validation_errors_response;
      }
    }
    $file = $file_upload_result->getFile();

    $media_storage = $this->entityTypeManager->getStorage('media');
    // @todo Should this be flexible based on the media type fields?
    $media = $media_storage->create([
      'bundle' => $media_type_id,
      'name' => $request->request->get('title') ?? $request->request->get('alt') ?? $file->getFilename(),
      $source_field_definition->getName() => [
        'target_id' => $file->id(),
        'title' => $request->request->get('title') ?? '',
        'alt' => $request->request->get('alt') ?? '',
      ],
    ]);

    // Note: this intentionally does not catch content entity type storage
    // handler exceptions: the generic Canvas API exception subscriber handles
    // them.
    // @see \Drupal\canvas\EventSubscriber\ApiExceptionSubscriber
    $violations = $media->getTypedData()->validate();
    if ($violations->count() > 0) {
      if ($validation_errors_response = self::createJsonResponseFromViolationSets($violations)) {
        return $validation_errors_response;
      }
    }
    $media->save();
    \assert($media instanceof MediaInterface);

    return new JsonResponse([
      'id' => (int) $media->id(),
      'uuid' => $media->uuid(),
      'inputs_resolved' => $this->getInputsResolved($media),
    ], Response::HTTP_CREATED);
  }

  /**
   * Resolves the media source field into Canvas component input values.
   *
   * For image fields, this returns {src, alt, width, height} matching the
   * json-schema-definitions://canvas.module/image shape.
   *
   * @return array<string, mixed>
   *   The resolved input values.
   */
  private function getInputsResolved(MediaInterface $media): array {
    $media_type_id = $media->getEntityType()->getBundleEntityType();
    \assert(\is_string($media_type_id));
    $media_type = $this->entityTypeManager->getStorage($media_type_id)->load($media->bundle());
    \assert($media_type instanceof MediaTypeInterface);
    $source_field_definition = $media->getSource()->getSourceFieldDefinition($media_type);
    \assert($source_field_definition instanceof FieldDefinitionInterface);
    $field_item = $media->get($source_field_definition->getName())->first();
    \assert($field_item !== NULL);
    \assert($source_field_definition->getType() === 'image');
    return [
      'src' => $field_item->get('src_with_alternate_widths')->getString(),
      'alt' => (string) $field_item->get('alt')->getValue(),
      'width' => (int) $field_item->get('width')->getValue(),
      'height' => (int) $field_item->get('height')->getValue(),
    ];
  }

}
