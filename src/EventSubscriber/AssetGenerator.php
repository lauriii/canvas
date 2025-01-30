<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\XbAssetInterface;
use Drupal\experience_builder\AssetManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Regenerates file assets on config import.
 */
final class AssetGenerator implements EventSubscriberInterface {

  private const ENTITY_MAP = [
    JavaScriptComponent::ENTITY_TYPE_ID => JavaScriptComponent::class,
    AssetLibrary::ENTITY_TYPE_ID => AssetLibrary::class,
  ];

  public function __construct(
    private readonly AssetManager $assetManager,
  ) {}

  public static function getSubscribedEvents(): array {
    $events[ConfigEvents::SAVE][] = ['onConfigSave'];
    $events[ConfigEvents::IMPORT][] = ['onConfigImport'];
    return $events;
  }

  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($this->isAssetConfig($config->getName())) {
      $this->generateFiles($config->getName());
    }
  }

  public function onConfigImport(ConfigImporterEvent $event): void {
    $changes = $event->getChangelist();
    $configs = \array_merge($changes['create'], $changes['update'], $changes['rename']);

    foreach ($configs as $config_name) {
      if ($this->isAssetConfig($config_name)) {
        $this->generateFiles($config_name);
      }
    }
  }

  private function generateFiles(string $config_name): void {
    [, $type, $id] = explode('.', $config_name, 3);
    $class = self::ENTITY_MAP[$type];
    $asset = $class::load($id);
    assert($asset instanceof XbAssetInterface);
    $this->assetManager->generateFiles($asset);
  }

  private function isAssetConfig(string $config_name): bool {
    return \str_starts_with($config_name, 'experience_builder.')
      && \array_key_exists(explode('.', $config_name, 3)[1], self::ENTITY_MAP);
  }

}
