<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Utility\Token;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\FormUploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * HTTP API for uploading files for a component's image or file prop.
 *
 * Serves the native (client-side rendered) `image_image` and `file_generic`
 * widgets, which reference plain File entities via file IDs. The client only
 * identifies the component prop being populated; the upload validators
 * (allowed extensions, maximum file size) and the upload location are derived
 * server-side from the Component config entity's stored prop field
 * definitions, so they cannot be manipulated by the client.
 *
 * @see docs/adr/0017-client-side-field-widgets.md
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiFileUploadController extends ApiControllerBase {

  /**
   * Default allowed extensions per field type, matching core's defaults.
   *
   * @see \Drupal\file\Plugin\Field\FieldType\FileItem::defaultFieldSettings()
   * @see \Drupal\image\Plugin\Field\FieldType\ImageItem::defaultFieldSettings()
   */
  private const array DEFAULT_EXTENSIONS = [
    'file' => 'txt',
    'image' => 'png gif jpg jpeg webp',
  ];

  /**
   * Default upload directory, matching core's default.
   *
   * @see \Drupal\file\Plugin\Field\FieldType\FileItem::defaultFieldSettings()
   */
  private const string DEFAULT_FILE_DIRECTORY = '[date:custom:Y]-[date:custom:m]';

  public function __construct(
    private readonly FileUploadHandlerInterface $fileUploadHandler,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ImageFactory $imageFactory,
    private readonly Token $token,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function __invoke(Request $request): JsonResponse {
    $prop_field_definition = self::resolvePropFieldDefinition($request);
    $field_type = $prop_field_definition['field_type'] ?? NULL;
    if (!\in_array($field_type, ['image', 'file'], TRUE)) {
      throw new BadRequestHttpException(\sprintf(
        "The `%s` prop is not stored as an `image` or `file` field, so file uploads are not supported for it.",
        $request->query->getString('prop'),
      ));
    }
    $storage_settings = $prop_field_definition['field_storage_settings'] ?? [];
    $instance_settings = $prop_field_definition['field_instance_settings'] ?? [];

    // Derive the upload location from the stored settings, with the same
    // fallbacks core's field types use for unset settings.
    // @see \Drupal\file\Plugin\Field\FieldType\FileItem::doGetUploadLocation()
    $file_directory = \trim((string) ($instance_settings['file_directory'] ?? self::DEFAULT_FILE_DIRECTORY), '/');
    // The directory setting may contain tokens; as the token replacement
    // might contain HTML, convert it to plain text.
    $file_directory = PlainTextOutput::renderFromHtml($this->token->replace($file_directory));
    $uri_scheme = $storage_settings['uri_scheme'] ?? $this->configFactory->get('system.file')->get('default_scheme');
    $upload_location = $uri_scheme . '://' . $file_directory;

    // Check the destination file path is writable.
    if (!$this->fileSystem->prepareDirectory($upload_location, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new HttpException(500, 'Destination file path is not writable');
    }

    // Read validators from the stored settings, with core's defaults as
    // fallbacks.
    $file_extensions = $instance_settings['file_extensions'] ?? NULL;
    $extensions = \is_string($file_extensions) && $file_extensions !== ''
      ? $file_extensions
      : self::DEFAULT_EXTENSIONS[$field_type];
    $max_filesize = $instance_settings['max_filesize'] ?? NULL;
    $validators = [
      'FileNameLength' => [],
      'FileExtension' => ['extensions' => $extensions],
      'FileSizeLimit' => ['fileLimit' => $max_filesize ? Bytes::toNumber($max_filesize) : Environment::getUploadMaxSize()],
    ];
    if ($field_type === 'image') {
      // Ensure that the file contents, not only the extension, is an image.
      $validators['FileIsImage'] = [];
    }

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
      validators: $validators,
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

    // The upload handler saves the File entity as temporary; make it
    // permanent, because the file ID is about to be stored in a component
    // instance's inputs.
    // @see \Drupal\file\Upload\FileUploadHandler::handleFileUpload()
    $file->setPermanent();
    $file->save();

    $width = NULL;
    $height = NULL;
    if ($field_type === 'image') {
      $image = $this->imageFactory->get($file->getFileUri());
      if ($image->isValid()) {
        $width = $image->getWidth();
        $height = $image->getHeight();
      }
    }

    return new JsonResponse([
      'fid' => (int) $file->id(),
      'uuid' => $file->uuid(),
      'url' => $this->fileUrlGenerator->generateString((string) $file->getFileUri()),
      'filename' => (string) $file->getFilename(),
      'filesize' => (int) $file->getSize(),
      'width' => $width,
      'height' => $height,
    ], Response::HTTP_CREATED);
  }

  /**
   * Resolves the requested prop's stored field definition.
   *
   * @return array<string, mixed>
   *   The prop's field definition as stored in the Component config entity's
   *   `settings.prop_field_definitions`: `field_type`, `field_widget`,
   *   `field_storage_settings`, `field_instance_settings`, etc.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the component, the requested version, or the prop does not
   *   exist.
   */
  private static function resolvePropFieldDefinition(Request $request): array {
    $component_id = $request->query->getString('component');
    $version = $request->query->getString('version');
    $prop = $request->query->getString('prop');

    $component = Component::load($component_id);
    if (!$component instanceof ComponentInterface) {
      throw new NotFoundHttpException(\sprintf("The component `%s` does not exist.", $component_id));
    }
    try {
      $component->loadVersion($version);
    }
    catch (\OutOfRangeException $e) {
      throw new NotFoundHttpException($e->getMessage(), $e);
    }

    $prop_field_definitions = $component->getSettings()['prop_field_definitions'] ?? [];
    if (!\array_key_exists($prop, $prop_field_definitions)) {
      throw new NotFoundHttpException(\sprintf("The `%s` component does not have a `%s` prop.", $component_id, $prop));
    }
    return $prop_field_definitions[$prop];
  }

}
