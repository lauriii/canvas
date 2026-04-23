<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceInputsConfigSchemaGeneratorInterface;
use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @internal
 */
final readonly class BlockComponentInstanceInputsConfigSchemaGenerator implements ComponentInstanceInputsConfigSchemaGeneratorInterface, ContainerInjectionInterface {

  public function __construct(
    private TypedConfigManagerInterface $typedConfigManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(TypedConfigManagerInterface::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigSchemaMapping(ComponentSourceInterface $component_source): array {
    \assert($component_source instanceof BlockComponent);

    $plugin_id = $component_source->getSourceSpecificComponentId();
    $mapping = $this->typedConfigManager->getDefinition('block.settings.' . $plugin_id)['mapping'];
    \assert(\is_array($mapping));

    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent::validateComponentInput()
    unset($mapping['provider'], $mapping['id']);
    // @todo Consider uncommenting in https://www.drupal.org/project/canvas/issues/3485502.
    unset($mapping['context_mapping']);

    // Forward port 11.3's https://www.drupal.org/project/drupal/issues/3547808,
    // to enable this to work on both 11.3 and 11.2. However, only a subset can
    // be ported: the `Choice` and `NotBlank` constraints must be omitted to
    // retain 11.2 compatibility. This achieves the main purpose: avoiding
    // translations for `label_display` in Canvas for sites on 11.2.
    // @todo Remove when Canvas requires 11.3.
    $mapping['label_display']['type'] = 'string';

    return $mapping;
  }

  /**
   * {@inheritdoc}
   */
  public function refineForInstance(array $mapping, array $actual_inputs, string $component_id, string $component_version): array {
    // Block settings are plain values, not prop sources. This strategy also
    // does not add `form_element_class`, so there is nothing to refine.
    return $mapping;
  }

}
