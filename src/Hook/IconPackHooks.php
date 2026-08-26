<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Entity\IconLibrary;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\Theme\Icon\IconExtractorInterface;
use Drupal\Core\Theme\Icon\IconExtractorPluginManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exposes Canvas icon library config entities as core Icon API icon packs.
 *
 * @see \Drupal\canvas\Entity\IconLibrary
 */
final class IconPackHooks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'plugin.manager.icon_extractor')]
    private readonly IconExtractorPluginManager $iconExtractorManager,
    #[Autowire(service: 'extension.list.module')]
    private readonly ModuleExtensionList $moduleExtensionList,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
    #[Autowire(param: 'app.root')]
    private readonly string $appRoot,
  ) {}

  /**
   * Implements hook_icon_pack_alter().
   */
  #[Hook('icon_pack_alter')]
  public function iconPackAlter(array &$definitions): void {
    $libraries = $this->entityTypeManager
      ->getStorage(IconLibrary::ENTITY_TYPE_ID)
      ->loadMultiple();
    if ($libraries === []) {
      return;
    }

    $module_path = $this->moduleExtensionList->getPath('canvas');
    $public_base_path = PublicStream::basePath();

    foreach ($libraries as $id => $library) {
      \assert($library instanceof IconLibrary);
      $id = (string) $id;

      // Never overwrite an icon pack provided by an installed extension.
      // Config entity validation prevents such collisions at save time, but an
      // extension providing the same pack id may be installed afterwards.
      // @see \Drupal\canvas\Entity\IconLibrary::validateIconPackIdCollision()
      if (isset($definitions[$id])) {
        $this->logger->warning('The %id icon library was not registered as an icon pack: an icon pack with that id is already provided by the %provider extension.', [
          '%id' => $id,
          '%provider' => (string) ($definitions[$id]['provider'] ?? 'unknown'),
        ]);
        continue;
      }

      // This alter hook runs after IconPackManager::processDefinition(), so
      // the definition must be complete, including the discovered icons.
      // @see \Drupal\Core\Theme\Icon\Plugin\IconPackManager::processDefinition()
      $definition = [
        'id' => $id,
        'label' => (string) $library->label(),
        'description' => (string) ($library->get('description') ?? ''),
        'provider' => 'canvas',
        'enabled' => TRUE,
        'extractor' => 'svg',
        'template' => $library->getTemplate(),
        'config' => [
          // A leading slash makes the source path relative to the app root.
          // @see \Drupal\Core\Theme\Icon\IconFinder::getFilesFromPath()
          'sources' => ['/' . $public_base_path . '/canvas/icons/' . $id . '/{icon_id}.svg'],
        ],
        'relative_path' => $module_path,
        'absolute_path' => $this->appRoot . '/' . $module_path,
      ];
      $extractor = $this->iconExtractorManager->createInstance('svg', $definition);
      \assert($extractor instanceof IconExtractorInterface);
      // Discovery globs the assets directory, which may hold files the entity
      // does not reference (a partially failed upload, or an asset removed
      // from the entity without its file being cleaned up yet). Only the
      // entity's own asset list is the source of truth for the pack.
      $asset_icon_ids = \array_map(
        static fn (array $asset): string => \basename((string) $asset['name'], '.svg'),
        $library->getAssets(),
      );
      $definition['icons'] = \array_filter(
        $extractor->discoverIcons(),
        static fn (string $icon_full_id): bool => \in_array(
          \substr($icon_full_id, \strlen($id) + 1),
          $asset_icon_ids,
          TRUE,
        ),
        \ARRAY_FILTER_USE_KEY,
      );
      $definitions[$id] = $definition;
    }
  }

}
