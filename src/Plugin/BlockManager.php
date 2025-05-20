<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin;

use Drupal\Core\Block\BlockManager as CoreBlockManager;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Block\MainContentBlockPluginInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\experience_builder\ComponentDoesNotMeetRequirementsException;
use Drupal\experience_builder\ComponentIncompatibilityReasonRepository;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent;
use Psr\Log\LoggerInterface;

/**
 * Decorator that auto-creates/updates an Experience Builder Component entity per Block plugin.
 *
 * @see \Drupal\experience_builder\Entity\Component
 * @see docs/components.md#3.2
 */
final class BlockManager extends CoreBlockManager {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    LoggerInterface $logger,
    protected readonly TypedConfigManagerInterface $configTyped,
    private readonly ComponentIncompatibilityReasonRepository $reasonRepository,
  ) {
    parent::__construct($namespaces, $cache_backend, $module_handler, $logger);
  }

  /**
   * {@inheritdoc}
   */
  protected function setCachedDefinitions($definitions): array {
    parent::setCachedDefinitions($definitions);

    // Do not auto-create/update XB configuration when syncing config/deploying.
    // @todo Introduce a "XB development mode" similar to Twig's: https://www.drupal.org/node/3359728
    // @phpstan-ignore-next-line
    if (\Drupal::isConfigSyncing()) {
      return $definitions;
    }

    foreach ($definitions as $id => $definition) {
      if ($id === 'broken') {
        continue;
      }

      // @todo is this a not going to become performance bottle neck on BlockPlugin heavy sites?
      $block = $this->createInstance($id);
      assert($block instanceof BlockPluginInterface);
      // The main content is rendered in a fixed position.
      // @see \Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant::build()
      if ($block instanceof MainContentBlockPluginInterface) {
        continue;
      }
      $component_id = BlockComponent::componentIdFromBlockPluginId($id);
      $component = Component::load($component_id);
      if (!$component instanceof Component) {
        $component = Component::create([
          'id' => $component_id,
          'provider' => $definition['provider'],
          'source' => BlockComponent::SOURCE_PLUGIN_ID,
          'status' => TRUE,
        ]);
      }

      $component->set('label', (string) $definition['admin_label'])
        ->set('category', (string) $definition['category'])
        ->set('provider', $definition['provider'])
        ->set('settings', [
          'local_source_id' => $id,
          // We are using strict config schema validation, so we need to provide valid default settings for each block.
          'default_settings' => [
            // The generic block plugin settings: all block plugins have at least this.
            // @see `type: block_settings`
            'id' => $id,
            'label' => (string) $definition['admin_label'],
            'label_display' => FALSE,
            'provider' => $definition['provider'],
          // The block plugin-specific settings.
          // @see `type: block.settings.[%parent.plugin_id]`
          ] + $block->defaultConfiguration(),
        ]);

      try {
        $component->getComponentSource()->checkRequirements();
        $component->save();
      }
      catch (ComponentDoesNotMeetRequirementsException $e) {
        $this->reasonRepository->storeReasons(BlockComponent::SOURCE_PLUGIN_ID, $component_id, $e->getMessages());

        // Existing component trees may depend on this Component config entity.
        // Avoid breaking those dependencies (which for some config entities
        // would result in their deletion), but disallow creating more instances
        // of this Component, by disabling it.
        // (Existing instances of this component may fail to render, but robust
        // error handling must graciously handle that.)
        // @see \Drupal\experience_builder\Element\RenderSafeComponentContainer
        if (!$component->isNew()) {
          $component->disable()->save();
        }
      }
    }

    return $definitions;
  }

}
