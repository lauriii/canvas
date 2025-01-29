<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\JavaScriptComponentAssetManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Regenerates JavaScript component file assets on config import.
 */
final class JavaScriptComponentAssetGenerator implements EventSubscriberInterface {

  private const CONFIG_PREFIX = 'experience_builder.js_component.';

  public function __construct(
    private readonly JavaScriptComponentAssetManager $assetManager,
  ) {}

  public static function getSubscribedEvents(): array {
    $events[ConfigEvents::SAVE][] = ['onConfigSave'];
    $events[ConfigEvents::IMPORT][] = ['onConfigImport'];
    return $events;
  }

  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($this->isAssetConfig($config->getName())) {
      $this->generateFiles($config->getRawData()['machineName']);
    }
  }

  public function onConfigImport(ConfigImporterEvent $event): void {
    $changes = $event->getChangelist();
    $configs = \array_merge($changes['create'], $changes['update'], $changes['rename']);

    foreach ($configs as $config_name) {
      if ($this->isAssetConfig($config_name)) {
        $this->generateFiles(substr($config_name, strlen(self::CONFIG_PREFIX)));
      }
    }
  }

  private function generateFiles(string $config_name): void {
    $component = JavaScriptComponent::load($config_name);
    assert($component instanceof JavaScriptComponent);
    $this->assetManager->generateFiles($component);
  }

  private function isAssetConfig(string $config_name): bool {
    return \str_starts_with($config_name, self::CONFIG_PREFIX);
  }

}
