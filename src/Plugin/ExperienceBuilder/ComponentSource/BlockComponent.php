<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Block\MainContentBlockPluginInterface;
use Drupal\Core\Block\MessagesBlockPluginInterface;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\ContextDefinitionInterface;
use Drupal\Core\Plugin\PluginDependencyTrait;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\Plugin\DataType\BooleanData;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\Validation\Plugin\Validation\Constraint\FullyValidatableConstraint;
use Drupal\experience_builder\Attribute\ComponentSource;
use Drupal\experience_builder\ComponentDoesNotMeetRequirementsException;
use Drupal\experience_builder\ComponentSource\ComponentSourceBase;
use Drupal\experience_builder\Entity\Component as ComponentEntity;
use Drupal\experience_builder\MissingComponentInputsException;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\system\Plugin\Block\SystemBreadcrumbBlock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Defines a component source based on block plugins.
 *
 * @todo Context mappings.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Blocks'),
  // While XB does not support context mappings yet, Block plugins also can
  // contain logic and perform e.g. database queries that fetch data to present.
  supportsImplicitInputs: TRUE,
)]
final class BlockComponent extends ComponentSourceBase implements ContainerFactoryPluginInterface {

  use PluginDependencyTrait;
  use ConstraintPropertyPathTranslatorTrait;

  public const SOURCE_PLUGIN_ID = 'block';
  public const EXPLICIT_INPUT_NAME = 'settings';

  /**
   * Constructs a new BlockComponent.
   *
   * @param array $configuration
   *   Configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param array $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Block\BlockManagerInterface $blockManager
   *   Block plugin manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly BlockManagerInterface $blockManager,
    private readonly AccountInterface $currentUser,
    private readonly TypedConfigManagerInterface $typedConfigManager,
  ) {
    assert(array_key_exists('local_source_id', $configuration));
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(BlockManagerInterface::class),
      $container->get(AccountInterface::class),
      $container->get(TypedConfigManagerInterface::class),
    );
  }

  /**
   * Generate a component ID given a block plugin ID.
   *
   * @param string $pluginId
   *   Block plugin ID.
   *
   * @return string
   *   Generated component ID.
   */
  public static function componentIdFromBlockPluginId(string $pluginId): string {
    return 'block.' . \str_replace(':', '.', $pluginId);
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    return $this->blockManager->getDefinition($this->configuration['local_source_id'])['class'];
  }

  /**
   * {@inheritdoc}
   */
  protected function getBlockPlugin(): BlockPluginInterface {
    // @todo this should probably use DefaultSingleLazyPluginCollection
    $block = $this->blockManager->createInstance($this->configuration['local_source_id'], $this->configuration);
    assert($block instanceof BlockPluginInterface);
    return $block;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return $this->getPluginDependencies($this->getBlockPlugin());
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    $pluginDefinition = $this->getBlockPlugin()->getPluginDefinition() ?? [];
    assert(is_array($pluginDefinition));
    return new TranslatableMarkup('Block: %name', [
      '%name' => $pluginDefinition['admin_label'] ?? new TranslatableMarkup('Invalid/broken'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, string $componentUuid, bool $isPreview = FALSE): array {
    $block = $this->getBlockPlugin();

    // @todo Refine to reflect the edited entity route in https://www.drupal.org/i/3509500
    if ($isPreview && $block instanceof SystemBreadcrumbBlock) {
      $block = new SystemBreadcrumbBlock(
        $block->getConfiguration(),
        $block->getPluginId(),
        $block->getPluginDefinition(),
        new class() implements BreadcrumbBuilderInterface {
          use StringTranslationTrait;

          public function applies(RouteMatchInterface $route_match) {
             return TRUE;
          }

          /**
           * In the preview, the breadcrumbs always points to the frontpage.
           */
          public function build(RouteMatchInterface $route_match) {
            $breadcrumb = new Breadcrumb();
            $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
            return $breadcrumb;
          }

        },
        // @phpstan-ignore-next-line
        new RouteMatch('<front>', \Drupal::service(RouteProviderInterface::class)->getRouteByName('<front>')),
      );
    }

    foreach ($inputs[self::EXPLICIT_INPUT_NAME] ?? [] as $key => $value) {
      $block->setConfigurationValue($key, $value);
    }

    // Allow global context to be injected by suspending the fiber.
    // @see \Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant::build()
    if ($block instanceof TitleBlockPluginInterface || $block instanceof MessagesBlockPluginInterface) {
      if (\Fiber::getCurrent() === NULL) {
        throw new \LogicException(sprintf('The %s block plugin does not support previews.', $block->getPluginId()));
      }
      \Fiber::suspend($block);
    }

    // @todo preview fallback handling (in case of no access or emptiness) in https://drupal.org/i/3497990
    // @see \Drupal\layout_builder\EventSubscriber\BlockComponentRenderArray::onBuildRender()
    $build = [
      '#access' => $block->access($this->currentUser, TRUE),
    ];
    $cacheable_metadata = CacheableMetadata::createFromObject($block);
    $cacheable_metadata->applyTo($build);

    assert($build['#access'] instanceof AccessResultInterface);
    if (!$build['#access']->isAllowed()) {
      return $build;
    }

    $build['content'] = $block->build();
    if (Element::isEmpty($build['content'])) {
      return $build;
    }

    // @todo This render array might be refactored in https://www.drupal.org/node/2931040
    // @see \Drupal\block\BlockViewBuilder::buildPreRenderableBlock
    $build += [
      '#theme' => 'block',
      '#configuration' => $block->getConfiguration(),
      '#plugin_id' => $block->getPluginId(),
      '#base_plugin_id' => $block->getBaseId(),
      '#derivative_plugin_id' => $block->getDerivativeId(),
      '#id' => $componentUuid,
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    $block_plugin = $this->getBlockPlugin();
    $plugin_id = $block_plugin->getPluginId();
    $config_schema_type_definition = $this->typedConfigManager->getDefinition('block.settings.' . $plugin_id);
    return self::removeConfigSchemaLabels($config_schema_type_definition);
  }

  private static function removeConfigSchemaLabels(array $config_schema): array {
    $normalized = [];
    foreach ($config_schema as $key => $value) {
      if ($key === 'label') {
        continue;
      }
      if (is_array($value)) {
        $value = self::removeConfigSchemaLabels($value);
      }
      $normalized[$key] = $value;
    }
    return $normalized;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresExplicitInput(): bool {
    return !empty($this->getDefaultExplicitInput());
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExplicitInput(): array {
    return $this->getBlockPlugin()->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item): array {

    try {
      return $item->getInputs() ?? [];
    }
    catch (MissingComponentInputsException) {
      // There is no input for this component. That should only be the case for
      // block plugins without any settings.
      assert(!$this->requiresExplicitInput());
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input): array {
    return [self::EXPLICIT_INPUT_NAME => $explicit_input];
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    // @see SimpleComponent type-script definition.
    // @see ComponentModel type-script definition.
    return ['resolved' => $explicit_input];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
    ?ComponentEntity $component = NULL,
    string $component_instance_uuid = '',
    array $client_model = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    $blockPlugin = $this->getBlockPlugin();
    if ($client_model) {
      $blockPlugin->setConfiguration($client_model);
    }
    $form += $blockPlugin->blockForm($form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    // @todo Implementation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    // @todo Implementation.
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(ComponentEntity $component): array {
    // These 2 block plugin interfaces cannot be previewed (regardless of which
    // implementation) because they depend on the global context.
    // @see `type: experience_builder.page_region.*`'s `component_trees.tree.presence`
    $block_plugin = $this->getBlockPlugin();
    if ($block_plugin instanceof TitleBlockPluginInterface || $block_plugin instanceof MessagesBlockPluginInterface) {
      return ['build' => []];
    }

    return ['build' => $this->renderComponent([], $component->uuid(), TRUE)];
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, ComponentEntity $component, array $client_model, ?ConstraintViolationListInterface $violations = NULL): array {
    // @todo Remove this in https://www.drupal.org/project/experience_builder/issues/3500994#comment-15951774 — the client should send the right data.
    $defaults = $component->get('settings')['default_settings'];
    $input = $this->fixBooleansUsingConfigSchema($client_model['resolved'] ?? []);
    // We don't need to store these as they can be recalculated based on the
    // plugin ID.
    $input += $defaults;
    unset($input['provider'], $input['id']);
    return $input;
  }

  /**
   * @todo Remove this in https://www.drupal.org/project/experience_builder/issues/3500795 when we start passing types for block config options.
   */
  private function fixBooleansUsingConfigSchema(array $resolved_client_model): array {
    $block_plugin = $this->getBlockPlugin();
    $plugin_id = $block_plugin->getPluginId();
    $typed_data = $this->typedConfigManager->createFromNameAndData('block.settings.' . $plugin_id, $resolved_client_model);
    \assert($typed_data instanceof ComplexDataInterface);
    $boolean = \array_filter($typed_data->getProperties(), fn(TypedDataInterface $property) => $property instanceof BooleanData);
    foreach ($boolean as $property) {
      $property_name = $property->getName();
      \assert($property_name !== NULL);
      if (\array_key_exists($property_name, $resolved_client_model)) {
        if (is_bool($resolved_client_model[$property_name]) || !\in_array($resolved_client_model[$property_name], ['true', 'false'], TRUE)) {
          // Already a boolean or something that shouldn't be converted to one.
          continue;
        }
        $resolved_client_model[$property_name] = $resolved_client_model[$property_name] === 'true';
      }
    }
    return $resolved_client_model;
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    if (!$this->requiresExplicitInput()) {
      return new ConstraintViolationList();
    }
    $block_plugin = $this->getBlockPlugin();
    $plugin_id = $block_plugin->getPluginId();
    $definition = $block_plugin->getPluginDefinition();
    \assert(\is_array($definition));
    // We don't store these, but they're needed for validation.
    $inputValues += [
      'id' => $plugin_id,
      'provider' => $definition['provider'] ?? 'system',
    ];
    $typed_data = $this->typedConfigManager->createFromNameAndData('block.settings.' . $plugin_id, $inputValues);
    return $this->translateConstraintPropertyPathsAndRoot(['' => \sprintf('inputs.%s.', $component_instance_uuid)], $typed_data->validate());
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    $block = $this->getBlockPlugin();
    // The main content is rendered in a fixed position.
    // @see \Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant::build()
    if ($block instanceof MainContentBlockPluginInterface) {
      return;
    }
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
    assert(is_array($plugin_definition));
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

}
