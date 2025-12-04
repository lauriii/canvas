<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldTypeOverride;

use Drupal\canvas\Plugin\DataType\ComputedEntityCanonicalRelativeUrl;
use Drupal\canvas\Plugin\Validation\Constraint\UriConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\UriSchemeConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\UriTargetMediaTypeConstraint;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;

/**
 * @internal
 *
 * Adds properties to entity reference field implementations.
 *
 * It adds:
 * - a `target_uuid` field property, which allows components in default content
 *   to use that, and have it translated to the corresponding `target_id`.
 * - a `url` field property, which allows generating (canonical) links to
 *   referenced entities (crucial for Canvas' ContentTemplates)
 */
trait CoreFeatureEntityReferenceItemAddPropertiesTrait {

  /**
   * Returns TRUE for references to an entity type with canonical URLs.
   *
   * @return bool
   */
  private static function shouldCreateUrlProperty(DataReferenceDefinitionInterface $entity_reference_definition): bool {
    $target_entity_type_id = $entity_reference_definition->getTargetDefinition()
      ->getEntityTypeId();
    return \Drupal::entityTypeManager()
      ->getDefinition($target_entity_type_id)
      ->hasLinkTemplate('canonical');
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $definitions = parent::propertyDefinitions($field_definition);

    $definitions['target_uuid'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target UUID'))
      ->setRequired(FALSE)
      ->addConstraint('Uuid');

    // A computed URL to make linking to referenced entities simple.
    // (Entity reference fields really are a kind of special "link" field type;
    // this computed field property then makes that easy to access, just like
    // the `url` property added by LinkItemOverride.)
    // @see \Drupal\link\Plugin\Field\FieldType\LinkItem::propertyDefinitions()
    // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\LinkItemOverride
    if (self::shouldCreateUrlProperty($definitions['entity'])) {
      $definitions['url'] = DataDefinition::create('uri')
        // ⚠️ This label is visible in the UI.
        ->setLabel(new TranslatableMarkup('URL'))
        ->setDescription(new TranslatableMarkup('Canonical URL for @entity-type-label', [
          '@entity-type-label' => $definitions['entity']->getTargetDefinition()
            ->getLabel(),
        ]))
        ->setComputed(TRUE)
        ->setReadOnly(TRUE)
        ->setRequired($definitions['target_id']->isRequired())
        // All canonical entity URLs point to a web page (MIME type: text/html);
        // this metadata allows precise prop shape matching.
        ->addConstraint(UriTargetMediaTypeConstraint::PLUGIN_ID, ['mimeType' => 'text/html'])
        // The ComputedEntityCanonicalRelativeUrl data type generates a browser-
        // accessible URL (root-relative, absolute using HTTP, absolute using
        // HTTPs or relative).
        ->addConstraint(UriConstraint::PLUGIN_ID, ['allowReferences' => TRUE])
        ->addConstraint(UriSchemeConstraint::PLUGIN_ID, ['http', 'https'])
        ->setClass(ComputedEntityCanonicalRelativeUrl::class);
    }

    return $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function onChange($property_name, $notify = TRUE): void {
    if ($property_name === 'target_uuid' && empty($this->target_id)) {
      $this->set('target_id', $this->get('target_uuid')->getValue());
    }
    parent::onChange($property_name, $notify);
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE): void {
    if (\is_array($values) && isset($values['target_uuid'])) {
      $values['target_id'] = $this->getTargetId($values['target_uuid']);
    }
    parent::setValue($values, $notify);
  }

  private function getTargetId(string $uuid): int|string|null {
    $target_type = $this->getFieldDefinition()
      ->getFieldStorageDefinition()
      ->getSetting('target_type');

    return \Drupal::service(EntityRepositoryInterface::class)
      ->loadEntityByUuid($target_type, $uuid)
      ?->id();
  }

}
