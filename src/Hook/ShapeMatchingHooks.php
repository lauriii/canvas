<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\editor\EditorInterface;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\DateRangeItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\FileItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\FileUriItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\FloatItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\ImageItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\LinkItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\ListIntegerItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\StringItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\StringLongItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\TextItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\TextLongItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\TextWithSummaryItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\UriItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\UuidItemOverride;
use Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\experience_builder\PropShape\CandidateStorablePropShape;
use Drupal\filter\FilterFormatInterface;
use Drupal\media\Entity\MediaType;
use Symfony\Component\Validator\Constraints\Hostname;
use Symfony\Component\Validator\Constraints\Ip;
use Symfony\Component\Validator\Constraints\NotEqualTo;

/**
 * @file
 * Hook implementations that make shape matching work.
 *
 * @see https://www.drupal.org/project/issues/experience_builder?component=Shape+matching
 * @see docs/shape-matching-into-field-types.md, section 3.1.2.a
 */
class ShapeMatchingHooks {

  /**
   * Implements hook_validation_constraint_alter().
   */
  #[Hook('validation_constraint_alter')]
  public function validationConstraintAlter(array &$definitions): void {
    // Add the Symfony validation constraints that Drupal core does not add in
    // \Drupal\Core\Validation\ConstraintManager::registerDefinitions() for
    // unknown reasons. Do it defensively, to not break when this changes.
    if (!isset($definitions['Hostname'])) {
      // @see \Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat::HOSTNAME
      // @see \Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat::IDN_HOSTNAME
      $definitions['Hostname'] = [
        'label' => 'Hostname',
        'class' => Hostname::class,
        'type' => ['string'],
        'provider' => 'core',
        'id' => 'Hostname',
      ];
      // @see \Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat::IPV4
      // @see \Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat::IPV6
      $definitions['Ip'] = [
        'label' => 'IP address',
        'class' => Ip::class,
        'type' => ['string'],
        'provider' => 'core',
        'id' => 'Ip',
      ];
      // @see `type: experience_builder.page_region.*`
      $definitions['NotEqualTo'] = [
        'label' => 'Not equal to',
        'class' => NotEqualTo::class,
        'type' => ['string'],
        'provider' => 'core',
        'id' => 'NotEqualTo',
      ];
    }
  }

  /**
   * Implements hook_field_info_alter().
   */
  #[Hook('field_info_alter')]
  public function fieldInfoAlter(array &$info): void {
    if (!empty($info['daterange'])) {
      $info['daterange']['class'] = DateRangeItemOverride::class;
    }
    if (!empty($info['file'])) {
      $info['file']['class'] = FileItemOverride::class;
    }
    if (!empty($info['file_uri'])) {
      $info['file_uri']['class'] = FileUriItemOverride::class;
    }
    if (!empty($info['float'])) {
      $info['float']['class'] = FloatItemOverride::class;
    }
    if (!empty($info['image'])) {
      $info['image']['class'] = ImageItemOverride::class;
    }
    if (!empty($info['link'])) {
      $info['link']['class'] = LinkItemOverride::class;
    }
    if (!empty($info['list_integer'])) {
      $info['list_integer']['class'] = ListIntegerItemOverride::class;
    }
    if (!empty($info['string'])) {
      $info['string']['class'] = StringItemOverride::class;
    }
    if (!empty($info['string_long'])) {
      $info['string_long']['class'] = StringLongItemOverride::class;
    }
    if (!empty($info['text'])) {
      $info['text']['class'] = TextItemOverride::class;
    }
    if (!empty($info['uuid'])) {
      $info['uuid']['class'] = UuidItemOverride::class;
    }
    if (!empty($info['uri'])) {
      $info['uri']['class'] = UriItemOverride::class;
    }
    if (!empty($info['text_long'])) {
      $info['text_long']['class'] = TextLongItemOverride::class;
    }
    if (!empty($info['text_with_summary'])) {
      $info['text_with_summary']['class'] = TextWithSummaryItemOverride::class;
    }
  }

  /**
   * Implements hook_entity_base_field_info_alter().
   */
  #[Hook('entity_base_field_info_alter')]
  public function entityBaseFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type): void {
    // The File entity type's `filename` and `filemime` base fields use the
    // `string` field type but are NOT prose (which is the default semantic for
    // that field type).
    // @see \Drupal\experience_builder\Plugin\Field\FieldType\StringItemOverride
    if ($entity_type->id() === 'file') {
      // Override the default string semantics of the "string" field type.
      // @see \Drupal\experience_builder\Plugin\Field\FieldTypeOverride\StringItemOverride::propertyDefinitions()
      $fields['filename']->addPropertyConstraints('value', ['StringSemantics' => StringSemanticsConstraint::STRUCTURED]);
      $fields['filemime']->addPropertyConstraints('value', ['StringSemantics' => StringSemanticsConstraint::STRUCTURED]);
      $fields['uri']->setRequired(\TRUE);
    }
    if ($entity_type->id() === 'taxonomy_term') {
      $fields['name']->addPropertyConstraints('value', ['StringSemantics' => StringSemanticsConstraint::PROSE]);
    }
  }

  /**
   * Implements hook_filter_format_access().
   *
   * Prevents any operations on Experience Builder's text formats.
   */
  #[Hook('filter_format_access')]
  public function filterFormatAccess(FilterFormatInterface $format, string $operation, AccountInterface $account): AccessResult {
    $protected_formats = [
      'xb_html_inline',
      'xb_html_block',
    ];
    if (in_array($format->id(), $protected_formats, TRUE)) {
      return match($operation) {
        // It is guaranteed that these text formats/editors are available only for
        // XB's component inputs form.
        // @see \Drupal\filter\Element\TextFormat::processFormats()
        // @see \Drupal\experience_builder\Hook\ReduxIntegratedFieldWidgetsHooks::processTextFormat()
        'use' => AccessResult::allowed()->addCacheableDependency($format),
        default => AccessResult::forbidden('Experience Builder text formats cannot be modified.')
          ->addCacheableDependency($format)
      };
    }

    // No opinion on other formats.
    return AccessResult::neutral();
  }

  /**
   * Implements hook_editor_access().
   *
   * Prevents any operations on Experience Builder's editors.
   */
  #[Hook('editor_access')]
  public function editorAccess(EditorInterface $editor, string $operation, AccountInterface $account): AccessResult {
    $protected_editors = [
      'xb_html_inline',
      'xb_html_block',
    ];
    if (in_array($editor->id(), $protected_editors, TRUE)) {
      return AccessResult::forbidden('Experience Builder editors cannot be modified.')
        ->addCacheableDependency($editor);
    }

    // No opinion on other editors.
    return AccessResult::neutral();
  }

  /**
   * Implements hook_entity_operation_alter().
   *
   * Removes all operations for Experience Builder's text formats and editors from list builders.
   */
  #[Hook('entity_operation_alter')]
  public function entityOperationAlter(array &$operations, EntityInterface $entity): void {
    // Handle FilterFormat entities.
    if ($entity instanceof FilterFormatInterface) {
      $protected_formats = [
        'xb_html_inline',
        'xb_html_block',
      ];

      if (in_array($entity->id(), $protected_formats, TRUE)) {
        // Remove all operations for these text formats.
        $operations = [];
      }
    }

    // Handle Editor entities.
    if ($entity instanceof EditorInterface) {
      $protected_editors = [
        'xb_html_inline',
        'xb_html_block',
      ];

      if (in_array($entity->id(), $protected_editors, TRUE)) {
        // Remove all operations for these editors.
        $operations = [];
      }
    }
  }

  /**
   * Implements hook_storage_prop_shape_alter().
   *
   * (On behalf of the Media Library module.)
   *
   * Overrides the default: the "image" field type + widget. Note that this used
   * to run only for sites that install the Media Library module, but to achieve
   * the intended authoring experience, XB depends on the Media Library module
   * since https://www.drupal.org/i/3474226.
   *
   * @see \Drupal\experience_builder\JsonSchemaInterpreter\SdcPropJsonSchemaType::computeStorablePropShape()
   * @todo Move to Media Library module, eventually.
   */
  #[Hook('storage_prop_shape_alter', module: 'media_library')]
  public function mediaLibraryStoragePropShapeAlter(CandidateStorablePropShape $storable_prop_shape): void {
    if ($storable_prop_shape->shape->schema == [
      'type' => 'object',
      '$ref' => 'json-schema-definitions://experience_builder.module/image',
    ]) {
      // @todo Allow configuring which media type, for now this assumes the
      //   Standard 'image' media type.
      // @see core/profiles/standard/config/optional/media.type.image.yml
      $image_media_type = MediaType::load('image');
      if (!$image_media_type) {
        return;
      }
      $source_field_definition = $image_media_type->getSource()
        ->getSourceFieldDefinition($image_media_type);
      \assert($source_field_definition !== \NULL);
      $source_field_name = $source_field_definition->getName();
      $storable_prop_shape->fieldTypeProp = new FieldTypeObjectPropsExpression('entity_reference', [
        'src' => new ReferenceFieldTypePropExpression(new FieldTypePropExpression('entity_reference', 'entity'), new ReferenceFieldPropExpression(new FieldPropExpression(EntityDataDefinition::create('media', 'image'), $source_field_name, \NULL, 'entity'), new FieldPropExpression(EntityDataDefinition::create('file'), 'uri', \NULL, 'url'))),
        'alt' => new ReferenceFieldTypePropExpression(new FieldTypePropExpression('entity_reference', 'entity'), new FieldPropExpression(EntityDataDefinition::create('media', 'image'), $source_field_name, \NULL, 'alt')),
        'width' => new ReferenceFieldTypePropExpression(new FieldTypePropExpression('entity_reference', 'entity'), new FieldPropExpression(EntityDataDefinition::create('media', 'image'), $source_field_name, \NULL, 'width')),
        'height' => new ReferenceFieldTypePropExpression(new FieldTypePropExpression('entity_reference', 'entity'), new FieldPropExpression(EntityDataDefinition::create('media', 'image'), $source_field_name, \NULL, 'height')),
      ]);
      $storable_prop_shape->fieldStorageSettings = ['target_type' => 'media'];
      $storable_prop_shape->fieldInstanceSettings = [
        'handler' => 'default:media',
        'handler_settings' => ['target_bundles' => ['image' => 'image']],
      ];
      $storable_prop_shape->fieldWidget = 'media_library_widget';
    }
  }

  /**
   * Implements hook_storage_prop_shape_alter().
   *
   * (On behalf of the Date Range module.)
   *
   * @see \Drupal\experience_builder\JsonSchemaInterpreter\SdcPropJsonSchemaType::computeStorablePropShape()
   * @see \Drupal\datetime_range\Plugin\Field\FieldType\DateRangeItem
   */
  #[Hook('storage_prop_shape_alter', module: 'datetime_range')]
  public function datetimeRangeStoragePropShapeAlter(CandidateStorablePropShape $storable_prop_shape): void {
    if ($storable_prop_shape->shape->schema == [
      'type' => 'object',
      '$ref' => 'json-schema-definitions://sdc_test_all_props.module/date-range',
    ]) {
      $storable_prop_shape->fieldTypeProp = new FieldTypeObjectPropsExpression('daterange', [
        'from' => new FieldTypePropExpression('daterange', 'end_value'),
        'to' => new FieldTypePropExpression('daterange', 'value'),
      ]);
      $storable_prop_shape->fieldStorageSettings = ['datetime_type' => DateTimeItem::DATETIME_TYPE_DATE];
      // @todo Make this actually work in component instance forms in https://www.drupal.org/project/experience_builder/issues/3523379
      $storable_prop_shape->fieldWidget = 'daterange_default';
    }
  }

}
