<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\canvas\MissingHostEntityException;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Prop source that resolves to the host entity itself.
 *
 * @see \Drupal\canvas\PropSource\HostEntityUrlPropSource
 *
 * @phpstan-import-type HostEntityPropSourceArray from PropSourceBase
 * @internal
 */
final class HostEntityPropSource extends PropSourceBase implements LinkablePropSourceInterface {

  /**
   * @return HostEntityPropSourceArray
   */
  public function toArray(): array {
    return ['sourceType' => $this->getSourceType()];
  }

  /**
   * {@inheritdoc}
   */
  public static function parse(array $prop_source): static {
    \assert(
      isset($prop_source['sourceType']) &&
      $prop_source['sourceType'] === PropSource::getTypePrefix(self::class)
    );
    return new self();
  }

  public function evaluate(?FieldableEntityInterface $host_entity, bool $is_required): EvaluationResult {
    if ($host_entity === NULL) {
      throw new MissingHostEntityException();
    }
    return new EvaluationResult($host_entity, CacheableMetadata::createFromObject($host_entity));
  }

  public function asChoice(): string {
    return PropSource::getTypePrefix($this);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    return [];
  }

  /**
   * Generates a label for this prop source.
   *
   * The label varies with the host entity type:
   * - bundled entity types include the bundle (e.g. "This Article content
   *   item")
   * - bundleless entity types use only the singular type label (e.g. "This
   *   user", "This page")
   */
  public function label(EntityDataDefinitionInterface $host_entity_data_definition): TranslatableMarkup {
    $entity_type_id = $host_entity_data_definition->getEntityTypeId();
    \assert(\is_string($entity_type_id));
    // The host entity context is always a single, concrete entity type +
    // bundle pair — the EntityDataDefinition encodes that singleton.
    $bundles = $host_entity_data_definition->getBundles() ?? [];
    \assert(\count($bundles) === 1, 'Host entity data definition must have exactly one bundle.');
    $bundle = reset($bundles);
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $entity_type = \Drupal::entityTypeManager()->getDefinition($entity_type_id);
    if ($entity_type->getBundleEntityType() !== NULL) {
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
      $bundle_label = (string) (\Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id)[$bundle]['label'] ?? '');
      if ($bundle_label !== '') {
        return new TranslatableMarkup('This @bundle @entity_type', [
          '@bundle' => $bundle_label,
          '@entity_type' => $entity_type->getSingularLabel(),
        ]);
      }
    }
    return new TranslatableMarkup('This @entity_type', [
      '@entity_type' => $entity_type->getSingularLabel(),
    ]);
  }

}
