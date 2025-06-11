<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\experience_builder\Plugin\DataType\ConfigEntityVersionAdapter;
use Drupal\experience_builder\Plugin\VersionedConfigurationSubsetSingleLazyPluginCollection;

/**
 * @property ?\Drupal\experience_builder\Plugin\DataType\ConfigEntityVersionAdapter $typedData
 */
abstract class VersionedConfigEntityBase extends ConfigEntityBase implements VersionedConfigEntityInterface {

  // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName, Drupal.Commenting.VariableComment.Missing
  protected string $active_version;

  // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName, Drupal.Commenting.VariableComment.Missing
  protected array $versioned_properties = [];

  protected string $loadedVersion;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $values, $entity_type) {
    parent::__construct($values, $entity_type);
    assert($this->active_version !== NULL);
    $this->loadedVersion = $this->active_version;
  }

  public function getActiveVersion(): string {
    assert($this->active_version !== NULL);
    return $this->active_version;
  }

  public function getLoadedVersion(): string {
    assert($this->active_version !== NULL);
    return $this->loadedVersion ?? $this->active_version;
  }

  public function isLoadedVersionActiveVersion(): bool {
    return $this->getLoadedVersion() === $this->active_version;
  }

  public function loadVersion(string $version): static {
    if ($version !== $this->loadedVersion) {
      assert($this->versioned_properties !== NULL);
      if ($version !== $this->active_version && !array_key_exists($version, $this->versioned_properties)) {
        throw new \OutOfRangeException(sprintf('The requested version `%s` is not available. Available versions: %s.',
          (string) $version,
          implode(', ', array_map(
            fn (string $v): string => sprintf('`%s`', (string) $v),
            array_keys($this->versioned_properties),
          )),
        ));
      }
      $this->loadedVersion = $version;
    }
    return $this;
  }

  public function createVersion(string $version): static {
    // Don't actually create a new version if the current version is the active
    // version. This improves DX: as long as the version (a deterministic hash)
    // remains the same, calling this won't have any effect (idempotency).
    if ($version === $this->active_version) {
      return $this;
    }
    // Reverse chronological order: new versions appear at the top.
    assert($this->versioned_properties !== NULL);
    $this->versioned_properties = [
      // At the top: the new version, with empty settings by default.
      self::ACTIVE_VERSION => [],
      // Just below the top: the currently active version.
      $this->active_version => $this->versioned_properties[self::ACTIVE_VERSION],
      // Below that: all older versions.
      ...array_diff_key($this->versioned_properties, array_flip([self::ACTIVE_VERSION])),
    ];
    // The new version is automatically the new active and loaded version, to
    // allow for versioned properties to be set on the new version.
    // @see ::set()
    $this->loadedVersion = $this->active_version = $version;
    return $this;
  }

  public function deleteVersion(string $version): static {
    if ($version === $this->active_version) {
      throw new \LogicException('Cannot delete the active version.');
    }
    if (!array_key_exists($version, $this->versioned_properties)) {
      throw new \OutOfRangeException();
    }
    unset($this->versioned_properties[$version]);
    return $this;
  }

  public function deleteVersionIfExists(string $version): static {
    if ($version === $this->active_version) {
      throw new \LogicException('Cannot delete the active version.');
    }
    if (array_key_exists($version, $this->versioned_properties)) {
      unset($this->versioned_properties[$version]);
    }
    return $this;
  }

  public function resetToActiveVersion(): static {
    assert($this->active_version !== NULL);
    $this->loadedVersion = $this->active_version;
    return $this;
  }

  public function getVersions(): array {
    return [
      $this->active_version,
      ...array_diff(array_keys($this->versioned_properties), [self::ACTIVE_VERSION]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    if (!$this->isLoadedVersionActiveVersion()) {
      throw new EntityStorageException("'{$this->entityTypeId}' entity is a versioned config entity, and its loaded version is not the active version.");
    }
    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   */
  public function get($property_name): mixed {
    $version_key = $this->loadedVersion === $this->active_version
      ? self::ACTIVE_VERSION
      : $this->loadedVersion;
    return match (property_exists($this, $property_name)) {
      // Not a versioned property: default logic.
      TRUE => parent::get($property_name),
      // Versioned property: alternative logic needed.
      FALSE => $this->versioned_properties[$version_key][$property_name] ?? NULL,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function set($property_name, $value) {
    if (!$this->isLoadedVersionActiveVersion() && !$this->isSyncing()) {
      throw new \LogicException('Can only set values on the active version');
    }

    // Not a versioned property: default logic.
    if (property_exists($this, $property_name)) {
      return parent::set($property_name, $value);
    }

    // Versioned property: alternative logic needed.
    if ($this instanceof EntityWithPluginCollectionInterface && !$this->isSyncing()) {
      $plugin_collections = $this->getPluginCollections();
      $plugin_instance = $plugin_collections[$property_name] ?? NULL;
      if ($plugin_instance) {
        // If external code updates the settings, pass it along to the plugin.
        $plugin_instance->setConfiguration($value);
        // Not all plugin configuration key-value pairs may be needed in the
        // versioned property.
        if ($plugin_instance instanceof VersionedConfigurationSubsetSingleLazyPluginCollection) {
          assert(is_array($value));
          $value = array_diff_key($value, array_flip($plugin_instance->omittedKeys));
        }
      }
    }
    $this->versioned_properties[VersionedConfigEntityBase::ACTIVE_VERSION][$property_name] = $value;

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getTypedData(): ConfigEntityVersionAdapter {
    if (!isset($this->typedData)) {
      $this->typedData = ConfigEntityVersionAdapter::createFromEntity($this);
    }
    return $this->typedData;
  }

}
