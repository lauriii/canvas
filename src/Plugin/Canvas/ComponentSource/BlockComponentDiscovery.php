<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Block\MainContentBlockPluginInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Plugin\Context\ContextDefinitionInterface;
use Drupal\Core\Validation\Plugin\Validation\Constraint\FullyValidatableConstraint;
use Drupal\views\Entity\View;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent
 * @internal
 */
final class BlockComponentDiscovery implements ComponentCandidatesDiscoveryInterface {

  /**
   * Block plugin IDs provided by core which should be enabled by default.
   *
   * @var string[]
   */
  const array BLOCKS_TO_KEEP_ENABLED = [
    'system_powered_by_block',
    'system_branding_block',
    'system_breadcrumb_block',
    'system_messages_block',
    'system_menu_block:main',
    'system_menu_block:footer',
  ];

  const array EXPLICITLY_IGNORED_BLOCKS = [
    // Exclude the one block plugin that cannot ever possibly make sense to
    // create instances of, because it's not really a block plugin.
    // @see \Drupal\Core\Block\Plugin\Block\Broken
    // @see \Drupal\Core\Block\BlockManager::getSortedDefinitions()
    'broken',
    // The node syndicate block does not qualify anyway, and it has been
    // deprecated: avoid flooding Canvas's tests with this news.
    // @see https://www.drupal.org/node/3519248
    'node_syndicate_block',
  ];

  public function __construct(
    private readonly BlockManagerInterface $blockManager,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly TypedConfigManagerInterface $typedConfigManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(BlockManagerInterface::class),
      $container->get(ModuleExtensionList::class),
      $container->get(TypedConfigManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function discover(): array {
    $definitions = array_diff_key(
      $this->blockManager->getDefinitions(),
      array_flip(self::EXPLICITLY_IGNORED_BLOCKS),
    );
    return array_filter(
      $definitions,
      // The main content is rendered in a fixed position.
      // @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::build()
      fn (array $definition) => !is_a($definition['class'], MainContentBlockPluginInterface::class, TRUE),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(string $source_specific_id): void {
    \assert(in_array($source_specific_id, self::EXPLICITLY_IGNORED_BLOCKS, TRUE) || array_key_exists($source_specific_id, $this->discover()), $source_specific_id);

    // @todo is this not going to become a performance bottleneck on BlockPlugin heavy sites?
    $block = $this->blockManager->createInstance($source_specific_id);
    \assert($block instanceof BlockPluginInterface);
    $settings = $block->defaultConfiguration();
    $data_definition = $this->typedConfigManager->createFromNameAndData('block.settings.' . $block->getPluginId(), $settings);
    // We currently support only block plugins with no settings, or if they do
    // have settings, they must be fully validatable.
    $fullyValidatable = FALSE;
    foreach ($data_definition->getConstraints() as $constraint) {
      if ($constraint instanceof FullyValidatableConstraint) {
        $fullyValidatable = TRUE;
        break;
      }
    }

    $reasons = [];
    if (!empty($settings) && !$fullyValidatable) {
      $reasons[] = 'Block plugin settings must opt into strict validation. Use the FullyValidatable constraint. See https://www.drupal.org/node/3404425';
    }

    $plugin_definition = $block->getPluginDefinition();
    \assert(is_array($plugin_definition));
    $required_contexts = array_filter(
      $plugin_definition['context_definitions'],
      fn (ContextDefinitionInterface $definition): bool => $definition->isRequired(),
    );
    if ($required_contexts) {
      $reasons[] = 'Block plugins that require context values are not supported.';
    }

    if ($reasons) {
      throw new ComponentDoesNotMeetRequirementsException($reasons);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function computeComponentSettings(string $source_specific_id): array {
    \assert(array_key_exists($source_specific_id, $this->discover()), $source_specific_id);
    $definition = $this->discover()[$source_specific_id];
    $block = $this->blockManager->createInstance($source_specific_id);
    \assert($block instanceof BlockPluginInterface);
    // @see `type: canvas.component_source_settings.block`
    return [
      // We are using strict config schema validation, so we need to provide
      // valid default settings for each block.
      'default_settings' => [
        // The generic block plugin settings: all block plugins have at
        // least this.
        // @see `type: block_settings`
        // @see `type: block.settings.*`
        // @todo Simplify when core simplifies `type: block_settings` in
        //   https://www.drupal.org/i/3426278
        'id' => $source_specific_id,
        'label' => (string) $definition['admin_label'],
        // @todo Change this to FALSE once https://drupal.org/i/2544708 is
        //   fixed.
        'label_display' => '0',
        'provider' => $definition['provider'],
      ] + $block->defaultConfiguration(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentProvider(string $source_specific_id): ?string {
    \assert(array_key_exists($source_specific_id, $this->discover()), $source_specific_id);
    return $this->blockManager->getDefinition($source_specific_id)['provider'];
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentStatus(string $source_specific_id): bool {
    \assert(array_key_exists($source_specific_id, $this->discover()), $source_specific_id);
    $all_installed_core_extensions = array_keys(array_filter(
      $this->moduleExtensionList->getAllInstalledInfo(),
      fn (array $info): bool => ($info['package'] ?? NULL) === 'Core',
    ));

    // By default, disable blocks provided by core, unless specifically named.
    $status = TRUE;
    $definition = $this->blockManager->getDefinition($source_specific_id);
    if (in_array($definition['provider'], ['core', ...$all_installed_core_extensions], TRUE) && !in_array($source_specific_id, self::BLOCKS_TO_KEEP_ENABLED, TRUE)) {
      $status = FALSE;
      // Special case for view blocks that are tagged with "default" are
      // disabled as they are likely created by core.
      if ($definition['provider'] === 'views') {
        $config_dependencies = $definition['config_dependencies']['config'] ?? [];
        foreach ($config_dependencies as $dependency) {
          if (str_starts_with($dependency, 'views.view.')) {
            $config_id = substr($dependency, strlen('views.view.'));
            $view = View::load($config_id);
            \assert(!is_null($view));
            $status = !in_array('default', array_map('trim', explode(',', $view->get('tag'))), TRUE);
          }
        }
      }
    }

    return $status;
  }

  /**
   * {@inheritdoc}
   */
  public function computeCurrentComponentMetadata(string $source_specific_id): array {
    \assert(array_key_exists($source_specific_id, $this->discover()), $source_specific_id);
    $definition = $this->blockManager->getDefinition($source_specific_id);
    return [
      'label' => (string) $definition['admin_label'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getComponentConfigEntityId(string $source_specific_component_id): string {
    return \sprintf('%s.%s',
      BlockComponent::SOURCE_PLUGIN_ID,
      str_replace(PluginBase::DERIVATIVE_SEPARATOR, '.', $source_specific_component_id),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSourceSpecificComponentId(string $component_id): string {
    $prefix = BlockComponent::SOURCE_PLUGIN_ID . '.';
    \assert(str_starts_with($component_id, $prefix));
    return str_replace('.', PluginBase::DERIVATIVE_SEPARATOR, substr($component_id, strlen($prefix)));
  }

}
