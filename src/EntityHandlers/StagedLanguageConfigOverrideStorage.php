<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\StagedLanguageConfigOverride;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class StagedLanguageConfigOverrideStorage extends ConfigEntityStorage {

  use StagedConfigEntityStorageTrait;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->autoSaveManager = $container->get(AutoSaveManager::class);
    return $instance;
  }

  protected function createStub(string $id): EntityInterface {
    [$langcode, $config_name] = \explode('.', $id, 2);
    return $this->create([
      'id' => "$langcode.$config_name",
      'langcode' => $langcode,
      'config_name' => $config_name,
    ]);
  }

  protected function publish(EntityInterface $entity): void {
    \assert($entity instanceof StagedLanguageConfigOverride);
    \assert($this->languageManager instanceof ConfigurableLanguageManagerInterface);
    $override = $this->languageManager->getLanguageConfigOverride($entity->language()->getId(), $entity->getName());
    \assert($override instanceof LanguageConfigOverride);
    if ($entity->isEmpty()) {
      $override->delete();
    }
    else {
      $data = $entity->getData();
      \assert(\is_array($data));
      foreach ($data as $key => $value) {
        $override->set($key, $value);
      }
      $override->save();
    }
  }

}
