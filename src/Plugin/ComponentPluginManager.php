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
use Drupal\Core\State\StateInterface;
use Drupal\Core\Theme\Component\ComponentMetadata;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Core\Theme\Component\SchemaCompatibilityChecker;
use Drupal\Core\Theme\ComponentNegotiator;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\Core\Theme\ExtensionType;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropShape\PropShape;
use Drupal\experience_builder\PropShape\StorablePropShape;

/**
 * Decorator that auto-creates/updates an Experience Builder Component entity per SDC.
 *
 * @see \Drupal\experience_builder\Entity\Component
 */
class ComponentPluginManager extends CoreComponentPluginManager implements CategorizingPluginManagerInterface {

  use CategorizingPluginManagerTrait;

  const REASONS_STATE_KEY = 'experience_builder:component:reasons';

  protected static bool $isRecursing = FALSE;

  protected array $reasons;

  /**
   * {@inheritdoc}
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   * @param \Drupal\Core\State\StateInterface $state
   */
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
    protected readonly StateInterface $state,
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
    $this->reasons = $this->state->get($this::REASONS_STATE_KEY, []);
    foreach ($definitions as $machine_name => $plugin_definition) {
      // Update all components, even those that do not meet the requirements.
      // (Because those components may already be in use!)
      if (array_key_exists(SingleDirectoryComponent::convertMachineNameToId($machine_name), $components)) {
        $component_plugin = $this->createInstance($machine_name);
        $component = SingleDirectoryComponent::updateConfigEntity($component_plugin);
        if (isset($component_plugin->metadata->status) && $component_plugin->metadata->status === 'obsolete') {
          $this->reasons[$component_plugin->getPluginId()] = 'Component has "obsolete" status';
          $component->disable();
        }
      }
      else {
        if (!self::componentMeetsRequirements($plugin_definition)) {
          continue;
        }
        $component_plugin = $this->createInstance($machine_name);
        $component = SingleDirectoryComponent::createConfigEntity($component_plugin);
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
    $this->updateReasons($definitions);
    self::$isRecursing = FALSE;

    return $definitions;
  }

  public function componentMeetsRequirements(array $plugin_definition): bool {
    // XB always requires schema, even for theme components.
    // @see \Drupal\Core\Theme\ComponentPluginManager::shouldEnforceSchemas()
    // @see \Drupal\Core\Theme\Component\ComponentMetadata::parseSchemaInfo()
    if (empty($plugin_definition['props'])) {
      $this->reasons[$plugin_definition['id']] = 'Component has no props schema';
      return FALSE;
    }
    if (isset($plugin_definition['status']) && $plugin_definition['status'] === 'obsolete') {
      $this->reasons[$plugin_definition['id']] = 'Component has "obsolete" status';
      return FALSE;
    }
    // Special case exception for 'all-props' SDC.
    // (This is used to develop support for more prop shapes.)
    if ($plugin_definition['id'] === 'sdc_test_all_props:all-props') {
      return TRUE;
    }

    if ($plugin_definition['category'] == 'Elements') {
      $this->reasons[$plugin_definition['id']] = 'Component uses the reserved "Elements" category';
      return FALSE;
    }

    if (isset($plugin_definition['props']['required'])) {
      foreach ($plugin_definition['props']['required'] as $prop) {
        // Every required prop must have >=1 example.
        if (empty($plugin_definition['props']['properties'][$prop]['examples'])) {
          $this->reasons[$plugin_definition['id']] = sprintf('Prop "%s" is required, but does not have example value', $prop);
          return FALSE;
        }
      }
    }
    if (isset($plugin_definition['props']['properties'])) {
      foreach ($plugin_definition['props']['properties'] as $prop_name => $prop) {
        if ($prop_name === 'attributes') {
          continue;
        }
        // Every prop must have a title.
        if (!isset($prop['title'])) {
          $this->reasons[$plugin_definition['id']] = sprintf('Prop "%s" must have title', $prop_name);
          return FALSE;
        }
        // Every prop must have a StorablePropShape.
        if (!$this->propHasStorablePropShape($prop_name, $plugin_definition)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  protected function propHasStorablePropShape(string $prop_name, array $plugin_definition): bool {
    $metadata = self::createComponentMetadataFromPluginDefinition($plugin_definition);
    $component_prop_expression = new ComponentPropExpression($plugin_definition['id'], $prop_name);
    $prop_shape = PropShape::getComponentPropsForMetadata($plugin_definition['id'], $metadata)[(string) $component_prop_expression];
    $storable_prop_shape = $prop_shape->getStorage();
    if ($storable_prop_shape instanceof StorablePropShape) {
      return TRUE;
    }
    $this->reasons[$plugin_definition['id']] = sprintf('Experience Builder does not know of a field type/widget to allow populating the <code>%s</code> prop, with the shape <code>%s</code>.', $prop_name, json_encode($prop_shape->schema, JSON_UNESCAPED_SLASHES));
    return FALSE;
  }

  protected static function createComponentMetadataFromPluginDefinition(array $plugin_definition): ComponentMetadata {
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
   * Checks reasons stored in State API an ensures no stale entries for non-existing SDC are kept.
   *
   * @todo Store reasons as value object that captures all the reasons component fails to meet requirements in https://www.drupal.org/project/experience_builder/issues/3473275
   *
   * @param array<string, \Drupal\Core\Plugin\Component> $definitions
   *
   * @return void
   */
  protected function updateReasons(array $definitions) : void {
    foreach (array_keys($this->reasons) as $plugin_id) {
      if (!array_key_exists($plugin_id, $definitions)) {
        unset($this->reasons[$plugin_id]);
      }
    }
    $this->state->set($this::REASONS_STATE_KEY, $this->reasons);
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
