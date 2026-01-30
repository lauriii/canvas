<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\DataType;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\Plugin\DataType\Uri;

#[DataType(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("URL of the referenced entity")
)]
class ComputedEntityCanonicalRelativeUrl extends Uri implements DependentPluginInterface, CacheableDependencyInterface {

  use ComputedDataTypeWithCacheabilityTrait {
    getValue as private traitGetValue;
  }

  public const string PLUGIN_ID = 'computed_entity_canonical_relative_url';

  private ?MaybeUrl $computedValue = NULL;

  /**
   * @see \Drupal\Core\Field\EntityReferenceFieldItemList::referencedEntities())
   */
  private function getReferencedEntity(): ?EntityInterface {
    \assert($this->getParent() !== NULL);
    $field_item = $this->getParent();
    if (!$field_item instanceof EntityReferenceItemInterface) {
      throw new \LogicException('This data type must be used as a computed field property on entity references.');
    }

    // @todo Core bug: EntityReferenceItemInterface should extend FieldItemInterface
    \assert($field_item instanceof FieldItemInterface);
    if ($field_item->isEmpty()) {
      return NULL;
    }

    $entity_reference_property = $field_item->get('entity');
    \assert($entity_reference_property instanceof EntityReference);
    $referenced_entity_adapter = $entity_reference_property->getTarget();
    \assert($referenced_entity_adapter instanceof EntityAdapter);

    return $referenced_entity_adapter->getEntity();
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(): ?MaybeUrl {
    return $this->traitGetValue();
  }

  /**
   * {@inheritdoc}
   */
  private function computeValue(): ?MaybeUrl {
    \assert($this->isComputed === FALSE);

    $referenced_entity = $this->getReferencedEntity();
    if ($referenced_entity === NULL) {
      $this->cacheability = new CacheableMetadata();
      return NULL;
    }

    try {
      $url = $referenced_entity->toUrl('canonical');
    }
    // Auto-create entity reference fields may reference unsaved entities, for
    // which no link can be generated.
    catch (EntityMalformedException) {
      $this->cacheability = CacheableMetadata::createFromObject($referenced_entity);
      return NULL;
    }

    // The URL this field property computes might disclose information about the
    // referenced entity: it may use a path alias, which may contain sensitive
    // information derived from the entity label.
    // Match what core's EntityReferenceLabelFormatter does when it renders both
    // the entity label and the URL, even though arguably only URL access should
    // be checked: this errs on the side of caution, and makes this behave
    // similar to how entity reference rendering has worked for years in Drupal.
    // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
    // @see \Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter::viewElements()
    $view_access = $referenced_entity->access('view', return_as_object: TRUE);
    $url_access = $url->access(return_as_object: TRUE);
    $access = $view_access->andIf($url_access);
    if (!$access->isAllowed()) {
      return new MaybeUrl(NULL, $access);
    }
    $generated_url = $url->toString(TRUE);
    return new MaybeUrl($generated_url, $access);
  }

  /**
   * {@inheritdoc}
   */
  public function getCastedValue(): ?string {
    // TRICKY: avoid casting NULL to '', because this computed property promises
    // a value (a URL) exists, but not every user has access to it. When they do
    // not have access, NULL must be returned.
    return $this->getValue()?->getUrl();
  }

  /**
   * {@inheritdoc}
   *
   * Conveys that this computed field property class depends on data not
   * contained in the host entity. Important for dependency tracking.
   *
   * Note that this does not repeat:
   * - the module dependencies for target entity types, that is handled by
   *   EntityReferenceItem::calculateStorageDependencies()
   * - the config dependencies for target bundles, that is handled by
   *   EntityReferenceItem::calculateDependencies()
   * - the content dependencies for default values, that is handled by
   *   EntityReferenceItem::calculateDependencies()
   */
  public function calculateDependencies(): array {
    $referenced_entity = $this->getReferencedEntity();
    return $referenced_entity === NULL
      ? []
      : ['content' => [$referenced_entity->getConfigDependencyName()]];
  }

}
