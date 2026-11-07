<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\OptionsSource;

use Drupal\canvas\Attribute\OptionsSource;
use Drupal\canvas\OptionsSource\OptionsSourceInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Offers the entities of a type, optionally of some bundles, by ID.
 *
 * Uses the entity type's default entity reference selection handler, so the
 * options respect access, and taxonomy terms are listed as a tree.
 *
 * @code
 * x-canvas-options:
 *   source: entity
 *   entity_type: taxonomy_term
 *   bundles: [tags]
 * @endcode
 */
#[OptionsSource(
  id: 'entity',
  label: new TranslatableMarkup('Entities'),
)]
final class EntityOptionsSource extends PluginBase implements OptionsSourceInterface, ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly SelectionPluginManagerInterface $selectionManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(EntityTypeManagerInterface::class),
      $container->get(EntityTypeBundleInfoInterface::class),
      $container->get(SelectionPluginManagerInterface::class),
    );
  }

  public function getOptions(): array {
    $entity_type_id = $this->configuration['entity_type'];
    $label_key = $this->entityTypeManager->getDefinition($entity_type_id)->getKey('label');
    $handler = $this->selectionManager->getInstance([
      'target_type' => $entity_type_id,
      'target_bundles' => $this->configuration['bundles'] ?? NULL,
      'sort' => ['field' => $label_key === FALSE ? '_none' : $label_key, 'direction' => 'ASC'],
      // Only the user selection handler uses this: nobody picks "Anonymous".
      'include_anonymous' => FALSE,
    ]);
    \assert($handler instanceof SelectionInterface);
    $options = [];
    foreach ($handler->getReferenceableEntities() as $bundle_options) {
      foreach ($bundle_options as $id => $label) {
        // Selection handlers escape labels for use in HTML.
        $options[$id] = Html::decodeEntities((string) $label);
      }
    }
    return $options;
  }

  public function validateConfiguration(): array {
    $unknown = \array_diff(\array_keys($this->configuration), ['entity_type', 'bundles']);
    if ($unknown) {
      return [\sprintf('Unknown keys for the "entity" source: %s. Use `entity_type` and optionally `bundles`.', implode(', ', $unknown))];
    }
    $entity_type_id = $this->configuration['entity_type'] ?? NULL;
    if (!\is_string($entity_type_id) || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return [\sprintf('`entity_type` must be an existing entity type, but is %s.', json_encode($entity_type_id))];
    }
    if (!\array_key_exists('bundles', $this->configuration)) {
      return [];
    }
    $bundles = $this->configuration['bundles'];
    if (!\is_array($bundles) || !array_is_list($bundles) || $bundles === []) {
      return ['`bundles` must be a list of bundles, such as `[tags]`.'];
    }
    $missing = \array_diff($bundles, \array_keys($this->entityTypeBundleInfo->getBundleInfo($entity_type_id)));
    return $missing
      ? [\sprintf('`bundles` lists %s, which "%s" does not have.', implode(', ', \array_map(fn ($bundle) => json_encode($bundle), $missing)), $entity_type_id)]
      : [];
  }

  public function getSuggestions(): array {
    $suggestions = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      // Suggest what content authors pick from: entities in site-configured
      // bundles (vocabularies, content types, media types…) and users.
      // ponytail: other entity types, such as menus, work in
      // `component.yml`; suggest them when asked.
      if ($entity_type_id === 'user') {
        $suggestions[] = [
          'label' => (string) $entity_type->getLabel(),
          'configuration' => ['entity_type' => $entity_type_id],
        ];
        continue;
      }
      if (!$entity_type instanceof ContentEntityTypeInterface || $entity_type->getBundleEntityType() === NULL || $entity_type->isInternal()) {
        continue;
      }
      foreach ($this->entityTypeBundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
        $suggestions[] = [
          'label' => \sprintf('%s: %s', $entity_type->getLabel(), $info['label']),
          'configuration' => ['entity_type' => $entity_type_id, 'bundles' => [$bundle]],
        ];
      }
    }
    usort($suggestions, fn (array $a, array $b) => strnatcasecmp($a['label'], $b['label']));
    return $suggestions;
  }

}
