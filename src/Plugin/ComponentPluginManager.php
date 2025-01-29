<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin;

use Drupal\Component\Plugin\CategorizingPluginManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Schema\SchemaIncompleteException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\CategorizingPluginManagerTrait;
use Drupal\Core\Theme\Component\ComponentMetadata;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Core\Theme\Component\SchemaCompatibilityChecker;
use Drupal\Core\Theme\ComponentNegotiator;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\Core\Theme\ExtensionType;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\experience_builder\ComponentDoesNotMeetRequirementsException;
use Drupal\experience_builder\ComponentIncompatibilityReasonRepository;
use Drupal\experience_builder\ComponentMetadataRequirementsChecker;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;

/**
 * Decorator that auto-creates/updates an Experience Builder Component entity per SDC.
 *
 * @see \Drupal\experience_builder\Entity\Component
 */
class ComponentPluginManager extends CoreComponentPluginManager implements CategorizingPluginManagerInterface {

  use CategorizingPluginManagerTrait;

  protected static bool $isRecursing = FALSE;

  protected array $reasons;

  public function __construct(
    ModuleHandlerInterface $module_handler,
    ThemeHandlerInterface $themeHandler,
    CacheBackendInterface $cacheBackend,
    ConfigFactoryInterface $configFactory,
    ThemeManagerInterface $themeManager,
    ComponentNegotiator $componentNegotiator,
    FileSystemInterface $fileSystem,
    SchemaCompatibilityChecker $compatibilityChecker,
    ComponentValidator $componentValidator,
    string $appRoot,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ComponentIncompatibilityReasonRepository $reasonRepository,
  ) {
    parent::__construct($module_handler, $themeHandler, $cacheBackend, $configFactory, $themeManager, $componentNegotiator, $fileSystem, $compatibilityChecker, $componentValidator, $appRoot);
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

    // TRICKY: Component::save() calls SdcPropKeysConstraintValidator, which
    // will also call this plugin manager! Avoid recursively creating Component
    // config entities.
    if (self::$isRecursing) {
      return $definitions;
    }
    self::$isRecursing = TRUE;

    $components = $this->entityTypeManager->getStorage('component')->loadMultiple();
    $reasons = $this->reasonRepository->getReasons()[SingleDirectoryComponent::SOURCE_PLUGIN_ID] ?? [];
    $definition_ids = \array_map(static fn (string $plugin_id) => SingleDirectoryComponent::convertMachineNameToId($plugin_id), \array_keys($definitions));
    foreach ($definitions as $machine_name => $plugin_definition) {
      // Update all components, even those that do not meet the requirements.
      // (Because those components may already be in use!)
      $component_id = SingleDirectoryComponent::convertMachineNameToId($machine_name);
      if (array_key_exists($component_id, $components)) {
        $component_plugin = $this->createInstance($machine_name);
        $component = SingleDirectoryComponent::updateConfigEntity($component_plugin);
        if (isset($component_plugin->metadata->status) && $component_plugin->metadata->status === 'obsolete') {
          $reasons[$component_id] = 'Component has "obsolete" status';
          $component->disable();
        }
      }
      else {
        try {
          $this->componentMeetsRequirements($plugin_definition);
          $component_plugin = $this->createInstance($machine_name);
          $component = SingleDirectoryComponent::createConfigEntity($component_plugin);
        }
        catch (ComponentDoesNotMeetRequirementsException $e) {
          $reasons[$component_id] = $e->getMessage();
          continue;
        }
      }
      try {
        $component->save();
      }
      catch (SchemaIncompleteException $exception) {
        if (!str_starts_with($exception->getMessage(), 'Schema errors for experience_builder.component.sdc.sdc_test_all_props.all-props with the following errors:')) {
          throw $exception;
        }
      }
    }
    $this->reasonRepository->updateReasons(SingleDirectoryComponent::SOURCE_PLUGIN_ID, \array_intersect_key($reasons, \array_flip($definition_ids)));
    self::$isRecursing = FALSE;

    return $definitions;
  }

  public function componentMeetsRequirements(array $plugin_definition): void {
    // @todo Try to remove this method in https://www.drupal.org/project/experience_builder/issues/3502988
    if (isset($plugin_definition['status']) && $plugin_definition['status'] === 'obsolete') {
      throw new ComponentDoesNotMeetRequirementsException('Component has "obsolete" status');
    }
    // Special case exception for 'all-props' SDC.
    // (This is used to develop support for more prop shapes.)
    if ($plugin_definition['id'] === 'sdc_test_all_props:all-props') {
      return;
    }

    $required = $plugin_definition['props']['required'] ?? [];
    ComponentMetadataRequirementsChecker::check($plugin_definition['id'], self::createComponentMetadataFromPluginDefinition($plugin_definition), $required);
  }

  protected static function createComponentMetadataFromPluginDefinition(array $plugin_definition): ComponentMetadata {
    // @todo Try to remove this method in https://www.drupal.org/project/experience_builder/issues/3502988
    // Copied logic from ComponentPluginManager::shouldEnforceSchema() as it is set to private visibility.
    // @see \Drupal\Core\Theme\ComponentPluginManager::shouldEnforceSchemas()
    if (isset($plugin_definition['extension_type']) && $plugin_definition['extension_type'] !== ExtensionType::Theme) {
      $should_enforce_schemas = TRUE;
    }
    else {
      $should_enforce_schemas = \Drupal::service('theme_handler')
        ->getTheme($plugin_definition['provider'])
        ?->info['enforce_prop_schemas'] ?? FALSE;
    }

    $metadata = new ComponentMetadata(
      $plugin_definition,
      \Drupal::hasService('kernel') ? \Drupal::root() : DRUPAL_ROOT,
      (bool) ($should_enforce_schemas)
    );

    return $metadata;
  }

  /**
   * @todo remove when https://www.drupal.org/project/drupal/issues/3474533 lands
   *
   * @param array $definition
   * @param string $plugin_id
   */
  public function processDefinition(&$definition, $plugin_id): void {
    parent::processDefinition($definition, $plugin_id);
    $this->processDefinitionCategory($definition);
  }

  /**
   * @todo remove when https://www.drupal.org/project/drupal/issues/3474533 lands
   *
   * @param array $definition
   */
  protected function processDefinitionCategory(&$definition): void {
    $definition['category'] = $definition['group'] ?? $this->t('Other');
  }

}
