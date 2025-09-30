<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldTypeOverride;

use Drupal\canvas\Plugin\Validation\Constraint\UriConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\UriSchemeConstraint;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\canvas\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\canvas\TypedData\LinkUrl;
use Drupal\link\Plugin\Field\FieldType\LinkItem;

/**
 * @todo Fix upstream.
 */
class LinkItemOverride extends LinkItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['title']->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE);
    $properties['uri']
      ->addConstraint(UriConstraint::PLUGIN_ID, ['allowReferences' => TRUE]);
    $properties['url'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Resolved URL'))
      ->setDescription(new TranslatableMarkup('The resolved URL for this link, that can be navigated to by a web browser.'))
      ->setComputed(TRUE)
      // The `url` property is computed using the `uri` property, which is
      // required. Hence this value is guaranteed to exist.
      ->setRequired(TRUE)
      // The LinkUrl data type generates a browser-accessible URL (either root-
      // relative, absolute using HTTP, or absolute using HTTPS).
      ->addConstraint(UriConstraint::PLUGIN_ID, ['allowReferences' => TRUE])
      ->addConstraint(UriSchemeConstraint::PLUGIN_ID, ['http', 'https'])
      ->setClass(LinkUrl::class);
    return $properties;
  }

}
