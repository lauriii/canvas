<?php

declare(strict_types=1);

namespace Drupal\canvas\Extension;

use Drupal\Core\Plugin\PluginBase;

class CanvasExtension extends PluginBase implements CanvasExtensionInterface {

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    // The title from YAML file discovery may be a TranslatableMarkup object.
    \assert(is_array($this->pluginDefinition) && \array_key_exists('id', $this->pluginDefinition));
    return (string) $this->pluginDefinition['id'];
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // The title from YAML file discovery may be a TranslatableMarkup object.
    \assert(is_array($this->pluginDefinition) && \array_key_exists('name', $this->pluginDefinition));
    return (string) $this->pluginDefinition['name'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    // The title from YAML file discovery may be a TranslatableMarkup object.
    \assert(is_array($this->pluginDefinition) && \array_key_exists('description', $this->pluginDefinition));
    return (string) $this->pluginDefinition['description'];
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): string {
    \assert(is_array($this->pluginDefinition) && \array_key_exists('icon', $this->pluginDefinition));
    return (string) $this->pluginDefinition['icon'];
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl(): string {
    \assert(is_array($this->pluginDefinition) && \array_key_exists('url', $this->pluginDefinition));
    return (string) $this->pluginDefinition['url'];
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): CanvasExtensionTypeEnum {
    \assert(is_array($this->pluginDefinition) && \array_key_exists('type', $this->pluginDefinition));
    return CanvasExtensionTypeEnum::from((string) $this->pluginDefinition['type']);
  }

  /**
   * {@inheritdoc}
   */
  public function getApiVersion(): string {
    \assert(is_array($this->pluginDefinition) && \array_key_exists('api_version', $this->pluginDefinition));
    return (string) $this->pluginDefinition['api_version'];
  }

  public function getPermissions(): array {
    \assert(is_array($this->pluginDefinition) && \array_key_exists('permissions', $this->pluginDefinition));
    \assert(is_array($this->pluginDefinition['permissions']));
    return $this->pluginDefinition['permissions'];
  }

}
