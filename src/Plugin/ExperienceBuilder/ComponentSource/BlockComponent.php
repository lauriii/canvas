<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Block\MainContentBlockPluginInterface;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\Attribute\ComponentSource;
use Drupal\experience_builder\ComponentSource\ComponentSourceBase;
use Drupal\experience_builder\Entity\Component as ComponentEntity;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly BlockManagerInterface $blockManager,
    private readonly RendererInterface $renderer,
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
      $container->get(RendererInterface::class),
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
  public function getComponentPluginDefinition(): array {
    return $this->blockManager->getDefinition($this->configuration['plugin_id']);
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentPlugin(): BlockPluginInterface {
    // @todo this should probably use DefaultSingleLazyPluginCollection
    $block = $this->blockManager->createInstance($this->configuration['plugin_id'], $this->configuration);
    assert($block instanceof BlockPluginInterface);
    return $block;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return $this->getComponentPlugin()->calculateDependencies() ?? [];
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
    $pluginDefinition = $this->getComponentPlugin()->getPluginDefinition() ?? [];
    assert(is_array($pluginDefinition));
    return new TranslatableMarkup('Block: %name', [
      '%name' => $pluginDefinition['admin_label'] ?? new TranslatableMarkup('Invalid/broken'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, string $componentUuid): array {
    $block = $this->getComponentPlugin();
    foreach ($inputs[self::EXPLICIT_INPUT_NAME] ?? [] as $key => $value) {
      $block->setConfigurationValue($key, $value);
    }

    // Allow global context to be injected by suspending the fiber.
    // @see \Drupal\experience_builder\Plugin\DisplayVariant\PageTemplateDisplayVariant::build()
    if ($block instanceof MainContentBlockPluginInterface || $block instanceof TitleBlockPluginInterface) {
      if (\Fiber::getCurrent() === NULL) {
        throw new \LogicException();
      }
      \Fiber::suspend($block);
    }

    // @todo access checking and everything in \Drupal\layout_builder\EventSubscriber\BlockComponentRenderArray::onBuildRender
    $build = $block->build();
    $build['#cache']['tags'][] = 'config:experience_builder.component.' . self::SOURCE_PLUGIN_ID . '.' . $this->configuration['plugin_id'];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item): array {
    // @todo What are "props" in terms of blocks? Define in https://www.drupal.org/project/experience_builder/issues/3484666. Rename ComponentPropsValues to ComponentExplicitInputs.
    $props = $item->get('props');
    $json = $props->getValue();
    \assert(\is_string($json));
    try {
      $settings = \array_diff_key(\json_decode($json, TRUE, flags: JSON_THROW_ON_ERROR)[$uuid] ?? [], \array_flip(['id']));
    }
    catch (\JsonException) {
      $settings = [];
    }
    return $settings;
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
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, string $component_instance_uuid = '', ?EntityInterface $entity = NULL, array $settings = []): array {
    return $this->getComponentPlugin()->blockForm($form, $form_state);
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
  public function getClientSideInfo(ComponentEntity $component, ?bool $cache_tags = TRUE): array {
    $build = $this->renderComponent([], $component->uuid());

    // @todo Determine what other values this must return, do we need a value object? Decide in https://www.drupal.org/project/experience_builder/issues/3484678
    return [
      'id' => $component->id(),
      'name' => (string) $component->label(),
      'category' => (string) $component->getCategory(),
      'source' => (string) $this->t('Block'),
      // @todo Allow components to pass build arrays back?
      'default_markup' => $this->renderer->render($build),
      // @todo CSS and JS
      'css' => '',
      'js_header' => '',
      'js_footer' => '',
    ];
  }

}
