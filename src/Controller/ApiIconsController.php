<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\IconLibrary;
use Drupal\canvas\Icon\IconResolver;
use Drupal\canvas\Icon\SvgSanitizer;
use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Theme\Icon\IconCollector;
use Drupal\Core\Theme\Icon\IconDefinition;
use Drupal\Core\Theme\Icon\Plugin\IconPackManagerInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\Upload\ContentDispositionFilenameParser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * HTTP API for listing the icon packs installed on the site.
 *
 * Serves both the icon picker widget and the Brand Kit "Icon libraries"
 * section. Search and filtering happen client-side over this one cacheable
 * payload, because pack contents only change on deploy or CLI push.
 *
 * The site-wide `icons.allowed_packs` allow-list in `canvas.settings` filters
 * which packs the default listing offers to content authors. Sync clients
 * request `scope=all` (gated by the brand kit administration permission) to
 * see the complete catalog regardless of that policy.
 *
 * Also accepts SVG uploads into Canvas-managed icon libraries.
 *
 * ponytail: whole-catalog payload; per-pack lazy loading plus server-side
 * search is the upgrade path if installed packs grow beyond a few thousand
 * icons.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiIconsController {

  /**
   * The maximum allowed size of an uploaded SVG file, in bytes.
   */
  private const int MAX_FILE_SIZE = 1048576;

  public function __construct(
    private readonly IconPackManagerInterface $iconPackManager,
    private readonly IconResolver $iconResolver,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly IconCollector $iconCollector,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountInterface $currentUser,
  ) {}

  public function list(Request $request): CacheableJsonResponse {
    $scope = $request->query->get('scope');
    if ($scope !== NULL && $scope !== 'all') {
      throw new BadRequestHttpException(\sprintf('Unknown scope "%s". Omit the scope query parameter, or pass scope=all to list every installed pack.', $scope));
    }
    // `scope=all` exists so sync clients (the Canvas CLI icon pull) always see
    // the complete catalog and round-trips are unaffected by authoring policy.
    if ($scope === 'all' && !$this->currentUser->hasPermission(IconLibrary::ADMIN_PERMISSION)) {
      throw new AccessDeniedHttpException(\sprintf('The "%s" permission is required to use scope=all.', IconLibrary::ADMIN_PERMISSION));
    }
    // An empty (or absent) allow-list offers every installed pack.
    $allowed = $scope === 'all'
      ? []
      : ($this->configFactory->get('canvas.settings')->get('icons.allowed_packs') ?? []);

    $packs = [];
    foreach ($this->iconPackManager->getDefinitions() ?? [] as $pack_id => $definition) {
      if (empty($definition['icons'])) {
        continue;
      }
      if ($allowed !== [] && !\in_array($pack_id, $allowed, TRUE)) {
        continue;
      }
      $icons = [];
      foreach (\array_keys($definition['icons']) as $icon_full_id) {
        $icon_full_id = (string) $icon_full_id;
        $icon_data = IconDefinition::getIconDataFromId($icon_full_id);
        if ($icon_data === NULL) {
          continue;
        }
        $entry = [
          'id' => $icon_full_id,
          'name' => $icon_data['icon_id'],
          'label' => IconDefinition::humanize($icon_data['icon_id']),
        ];
        // Either inline `svg` markup or an asset `url`; omitted (and logged)
        // when the icon cannot be resolved through its pack's extractor.
        $resolved = $this->iconResolver->resolve($icon_full_id);
        if ($resolved !== NULL) {
          $entry += \array_diff_key($resolved, ['id' => TRUE]);
        }
        $icons[] = $entry;
      }
      $packs[$pack_id] = [
        'id' => $pack_id,
        'label' => (string) ($definition['label'] ?? $pack_id),
        'description' => (string) ($definition['description'] ?? ''),
        'iconCount' => \count($icons),
        'icons' => $icons,
      ];
    }

    // Cast so an empty pack list encodes as `{}` rather than `[]`, matching
    // the documented `type: object` response schema.
    $response = new CacheableJsonResponse(['packs' => (object) $packs]);
    $response->addCacheableDependency((new CacheableMetadata())
      // Icon packs are provided by installed extensions and by Canvas-managed
      // icon library config entities.
      ->setCacheTags([
        'icon_pack_plugin',
        'icon_pack_collector',
        'config:core.extension',
        'config:icon_library_list',
        // The allow-list filtering the default scope lives in this config.
        'config:canvas.settings',
      ])
      ->setCacheContexts(['user.permissions', 'url.query_args:scope']));
    return $response;
  }

  /**
   * Handles SVG file upload for an icon library.
   *
   * Accepts a binary SVG stream, validates it against the SVG trust boundary,
   * and saves it as a managed file in the icon library's assets directory.
   * Re-uploads of the same filename replace the existing file, so CLI pushes
   * are idempotent.
   *
   * @see \Drupal\canvas\Icon\SvgSanitizer
   */
  public function upload(Request $request, IconLibrary $icon_library): JsonResponse {
    $filename = ContentDispositionFilenameParser::parseFilename($request);
    if (!\preg_match('/^[a-zA-Z0-9._-]+\.svg$/', $filename)) {
      return new JsonResponse(status: 422, data: [
        'errors' => ['The filename must consist of letters, numbers, dots, dashes, and underscores, and use the .svg extension.'],
      ]);
    }

    $content = (string) $request->getContent();
    if (\strlen($content) > self::MAX_FILE_SIZE) {
      return new JsonResponse(status: 422, data: [
        'errors' => ['The file must not exceed 1 MB.'],
      ]);
    }

    $reasons = SvgSanitizer::validate($content);
    if ($reasons !== []) {
      return new JsonResponse(status: 422, data: ['errors' => $reasons]);
    }

    $destination = $icon_library->getAssetsDirectory();
    if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new HttpException(500, 'Destination file path is not writable');
    }

    // The content hash lets the CLI skip re-uploading unchanged files on
    // incremental pushes; it is also stored on the library's asset entries.
    $hash = \hash('sha256', $content);

    // An identical file already at the destination needs no write and no
    // cache invalidation; respond as if it was just uploaded.
    $uri = $destination . $filename;
    $existing_files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
    $existing_file = \reset($existing_files);
    if ($existing_file instanceof FileInterface && \is_string($existing_contents = @\file_get_contents($uri)) && \hash('sha256', $existing_contents) === $hash) {
      $existing_uri = $existing_file->getFileUri();
      \assert(\is_string($existing_uri));
      return new JsonResponse(status: 201, data: [
        'uri' => $existing_uri,
        'fid' => (int) $existing_file->id(),
        'url' => $this->fileUrlGenerator->generateString($existing_uri),
        'hash' => $hash,
      ]);
    }

    // FileExists::Replace keeps the URI stable, making CLI re-pushes
    // idempotent. The written file is a permanent managed file; file usage is
    // tracked once the icon library's `assets` list references it.
    // @see \Drupal\canvas\Entity\IconLibrary::postSave()
    $file = $this->fileRepository->writeData($content, $destination . $filename, FileExists::Replace);

    // Ensure re-discovery sees the new file: clearing the pack definitions
    // also invalidates the `icon_pack_plugin` and `icon_pack_collector` cache
    // tags, and the collector reset covers this request's static cache.
    \assert($this->iconPackManager instanceof CachedDiscoveryInterface);
    $this->iconPackManager->clearCachedDefinitions();
    $this->iconCollector->reset();

    $file_uri = $file->getFileUri();
    \assert(\is_string($file_uri));
    return new JsonResponse(status: 201, data: [
      'uri' => $file_uri,
      'fid' => (int) $file->id(),
      'url' => $this->fileUrlGenerator->generateString($file_uri),
      'hash' => $hash,
    ]);
  }

}
