<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldTypeOverride;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\experience_builder\Plugin\DataType\UriTemplate;
use Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\experience_builder\Plugin\Validation\Constraint\UriTemplateWithVariablesConstraint;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\TypedData\ImageDerivativeWithParametrizedWidth;

/**
 * @todo Fix upstream.
 */
class ImageItemOverride extends ImageItem {

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings(): array {
    // @todo Remove once https://drupal.org/i/3513317 is fixed.
    return ['display_default' => TRUE] + parent::defaultStorageSettings();
  }

  public static function defaultFieldSettings() {
    // Add default support for AVIF.
    return ['file_extensions' => 'png gif jpg jpeg webp avif'] +
      parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['alt']->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE);
    $properties['title']->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE);
    // A computed URI template to populate `<img srcset>` using a parametrized
    // width.
    // @see https://developer.mozilla.org/en-US/docs/Web/API/HTMLImageElement/srcset#value
    // @see https://tools.ietf.org/html/rfc6570
    // @see \Drupal\experience_builder\TypedData\ImageDerivativeWithParametrizedWidth::getAllowedWidths()
    $properties['srcset_candidate_uri_template'] = DataDefinition::create(UriTemplate::PLUGIN_ID)
      ->setLabel(new TranslatableMarkup('srcset template'))
      ->setDescription(new TranslatableMarkup('Image candidate string URL template.'))
      ->setComputed(TRUE)
      ->setReadOnly(TRUE)
      ->addConstraint(UriTemplateWithVariablesConstraint::PLUGIN_ID, [
        'requiredVariables' => ['width'],
      ])
      ->setClass(ImageDerivativeWithParametrizedWidth::class);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function calculateDependencies(FieldDefinitionInterface $field_definition) {
    return NestedArray::mergeDeep(
      parent::calculateDependencies($field_definition),
      // @see \Drupal\experience_builder\TypedData\ImageDerivativeWithParametrizedWidth
      // @see config/install/image.style.xb_parametrized_width.yml
      [
        'config' => [
          'image.style.xb_parametrized_width',
        ],
      ],
    );
  }

}
