<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Block\MainContentBlockPluginInterface;
use Drupal\Core\Block\MessagesBlockPluginInterface;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\Attribute\ComponentSource;
use Drupal\experience_builder\ComponentSource\ComponentSourceBase;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\Component as ComponentEntity;
use Drupal\experience_builder\MissingComponentInputsException;
use Drupal\experience_builder\Plugin\DataType\ComponentInputs;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Validation\ConstraintPropertyPathTranslatorTrait;
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
  label: new TranslatableMarkup('Blocks')
)]
final class BlockComponent extends ComponentSourceBase implements ContainerFactoryPluginInterface {

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
    return $this->blockManager->getDefinition($this->configuration['plugin_id'])['class'];
  }

  /**
   * {@inheritdoc}
   */
  protected function getBlockPlugin(): BlockPluginInterface {
    // @todo this should probably use DefaultSingleLazyPluginCollection
    $block = $this->blockManager->createInstance($this->configuration['plugin_id'], $this->configuration);
    assert($block instanceof BlockPluginInterface);
    return $block;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return $this->getBlockPlugin()->calculateDependencies() ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getDependencies(array $settings): array {
    return $this->calculateDependencies();
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
  public function renderComponent(array $inputs, string $componentUuid): array {
    $block = $this->getBlockPlugin();
    foreach ($inputs[self::EXPLICIT_INPUT_NAME] ?? [] as $key => $value) {
      $block->setConfigurationValue($key, $value);
    }

    // Allow global context to be injected by suspending the fiber.
    // @see \Drupal\experience_builder\Plugin\DisplayVariant\PageTemplateDisplayVariant::build()
    if ($block instanceof MainContentBlockPluginInterface || $block instanceof TitleBlockPluginInterface) {
      if (\Fiber::getCurrent() === NULL) {
        throw new \LogicException(sprintf('The %s block plugin does not support previews.', $block->getPluginId()));
      }
      \Fiber::suspend($block);
    }

    // @todo preview fallback handling (in case of no access or emptiness) in https://drupal.org/i/3497990
    // @see \Drupal\layout_builder\EventSubscriber\BlockComponentRenderArray::onBuildRender()
    $access = $block->access($this->currentUser, TRUE);
    assert($access instanceof AccessResultInterface);
    if (!$access->isAllowed()) {
      return ['#access' => $access];
    }

    $content = $block->build();
    if (Element::isEmpty($content)) {
      $content['#access'] = $access;
      return $content;
    }

    // @todo This render array might be refactored in https://www.drupal.org/node/2931040
    // @see \Drupal\block\BlockViewBuilder::buildPreRenderableBlock
    return [
      '#access' => $access,
      '#theme' => 'block',
      '#configuration' => $block->getConfiguration(),
      '#plugin_id' => $block->getPluginId(),
      '#base_plugin_id' => $block->getBaseId(),
      '#derivative_plugin_id' => $block->getDerivativeId(),
      '#id' => $componentUuid,
      'content' => $content,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function requiresExplicitInput(): bool {
    return !empty($this->getBlockPlugin()->defaultConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item): array {
    $inputs = $item->get('inputs');
    assert($inputs instanceof ComponentInputs);
    try {
      return $inputs->getValues($uuid);
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
    return $explicit_input;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
    string $component_instance_uuid = '',
    array $client_model = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    $blockPlugin = $this->getBlockPlugin();
    if ($client_model) {
      $blockPlugin->setConfiguration($client_model);
    }
    $form = $blockPlugin->blockForm($form, $form_state);
    // @todo Remove in https://www.drupal.org/project/experience_builder/issues/3500152
    $form['#attributes']['data-form-id'] = 'block_form';
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
    // These 3 block plugin interfaces cannot be previewed (regardless of which
    // implementation) because they depend on the global context.
    // @see `type: experience_builder.page_template.*`'s `component_trees.tree.presence`
    $block_plugin = $this->getBlockPlugin();
    if ($block_plugin instanceof MainContentBlockPluginInterface
        || $block_plugin instanceof TitleBlockPluginInterface
        || $block_plugin instanceof MessagesBlockPluginInterface
    ) {
      return ['build' => []];
    }

    return ['build' => $this->renderComponent([], $component->uuid())];
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ConstraintViolationListInterface $violations): array {
    // @todo Remove this in https://www.drupal.org/project/experience_builder/issues/3500994#comment-15951774 — the client should send the right data.
    $defaults = $component->get('settings')['default_settings'];
    if (\version_compare(\Drupal::VERSION, '11.0', '<')) {
      // In Drupal 10, block setting schemas are conflated with the block
      // config entity and the block content plugin and hence include keys that
      // are irrelevant to valid block settings. Let's make sure they don't end
      // up in stored input.
      // @see https://drupal.org/i/2274175
      $defaults = \array_diff_key($defaults, \array_flip([
        'info',
        'status',
        'view_mode',
        'context_mapping',
      ]));
    }
    // We don't need to store these as they can be recalculated based on the
    // plugin ID.
    $input = $client_model + $defaults;
    unset($input['provider'], $input['id']);
    return $input;
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
    if (\version_compare(\Drupal::VERSION, '11.0', '<')) {
      // In Drupal 10, block setting schemas are conflated with the block
      // config entity and the block content plugin and hence include keys that
      // are irrelevant to valid block settings.
      // @see https://drupal.org/i/2274175
      $inputValues += [
        'info' => '',
        'status' => TRUE,
        'view_mode' => 'default',
        'context_mapping' => [],
      ];
    }
    $typed_data = $this->typedConfigManager->createFromNameAndData('block.settings.' . $plugin_id, $inputValues);
    return $this->translateConstraintPropertyPathsAndRoot(['' => \sprintf('inputs.%s.', $component_instance_uuid)], $typed_data->validate());
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    // @todo Move logic from experience_builder_block_alter here in https://www.drupal.org/project/experience_builder/issues/3491032
  }

}
