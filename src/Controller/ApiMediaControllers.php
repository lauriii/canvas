<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\FileUploadLocationTrait;
use Drupal\file\Upload\FormUploadedFile;
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

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUploadHandlerInterface $fileUploadHandler,
    private readonly FileSystemInterface $fileSystem,
  ) {}

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
        'FileExtension' => ['extensions' => $file_extensions ?: 'png gif jpg jpeg webp avif'],
        'FileSizeLimit' => ['fileLimit' => $max_filesize ? Bytes::toNumber($max_filesize) : Environment::getUploadMaxSize()],
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
      'name' => $request->get('title') ?? $request->get('alt') ?? $file->getFilename(),
      $source_field_definition->getName() => [
        'target_id' => $file->id(),
        'title' => $request->get('title') ?? '',
        'alt' => $request->get('alt') ?? '',
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
