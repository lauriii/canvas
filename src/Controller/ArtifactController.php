<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\BrandKit;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Bytes;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\FileExistsException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Lock\LockAcquiringException;
use Drupal\file\Upload\ContentDispositionFilenameParser;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\InputStreamFileWriterInterface;
use Drupal\file\Upload\InputStreamUploadedFile;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\Exception\CannotWriteFileException;
use Symfony\Component\HttpFoundation\File\Exception\NoFileException;
use Symfony\Component\HttpFoundation\File\Exception\UploadException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Controller for generic artifact upload API endpoints.
 *
 * @internal
 */
final class ArtifactController extends ApiControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly InputStreamFileWriterInterface $inputStreamFileWriter,
    private readonly FileUploadHandlerInterface $fileUploadHandler,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(InputStreamFileWriterInterface::class),
      $container->get(FileUploadHandlerInterface::class),
      $container->get(FileSystemInterface::class),
      $container->get(FileUrlGeneratorInterface::class),
    );
  }

  /**
   * Handles file upload for generic artifacts.
   *
   * Accepts a binary file stream and saves it as a managed file entity.
   */
  public function upload(Request $request): JsonResponse {
    $destination = BrandKit::ARTIFACTS_DIRECTORY;
    if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new HttpException(500, 'Destination file path is not writable');
    }

    try {
      $filename = ContentDispositionFilenameParser::parseFilename($request);

      $tempPath = $this->inputStreamFileWriter->writeStreamToFile();
      $uploadedFile = new InputStreamUploadedFile($filename, $filename, $tempPath, @filesize($tempPath));

      $result = $this->fileUploadHandler->handleFileUpload(
        $uploadedFile,
        validators: [
          'FileExtension' => ['extensions' => 'js css json map gif png jpg jpeg svg webp avif ico woff woff2 ttf eot otf'],
          'FileSizeLimit' => ['fileLimit' => Bytes::toNumber('10MB')],
        ],
        destination: $destination,
        fileExists: FileExists::Rename,
      );
    }
    catch (LockAcquiringException $e) {
      throw new HttpException(503, $e->getMessage(), NULL, ['Retry-After' => 1]);
    }
    catch (UploadException $e) {
      throw new HttpException(500, 'Input file data could not be read', $e);
    }
    catch (CannotWriteFileException $e) {
      throw new HttpException(500, 'Temporary file data could not be written', $e);
    }
    catch (NoFileException $e) {
      throw new HttpException(500, 'Temporary file could not be opened', $e);
    }
    catch (FileExistsException $e) {
      throw new HttpException(500, $e->getMessage(), $e);
    }
    catch (FileException) {
      throw new HttpException(500, 'Temporary file could not be moved to file location');
    }

    if ($result->hasViolations()) {
      $violations = $result->getViolations();
      \assert($violations instanceof ConstraintViolationList);
      $message = "Unprocessable Entity: file validation failed.\n";
      $message .= implode("\n", \array_map(static function (ConstraintViolationInterface $violation): string {
        return PlainTextOutput::renderFromHtml($violation->getMessage());
      }, (array) $violations->getIterator()));
      throw new UnprocessableEntityHttpException($message);
    }

    $file = $result->getFile();

    $file_uri = $file->getFileUri();
    \assert(\is_string($file_uri));

    return new JsonResponse(status: 201, data: [
      'uri' => $file_uri,
      'fid' => (int) $file->id(),
      'url' => $this->fileUrlGenerator->generateString($file_uri),
    ]);
  }

}
