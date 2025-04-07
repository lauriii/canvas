<?php

declare(strict_types=1);

/**
 * @file
 * Hook implementations that make shape matching work.
 *
 * @see https://www.drupal.org/project/issues/experience_builder?component=Shape+matching
 * @see docs/data-model.md, section 3.1.2.a
 */

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\editor\EditorInterface;
use Drupal\filter\FilterFormatInterface;
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
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\UuidItemOverride;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\UriItemOverride;
use Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Symfony\Component\Validator\Constraints\Hostname;
use Symfony\Component\Validator\Constraints\Ip;
use Symfony\Component\Validator\Constraints\NotEqualTo;

/**
 * Implements hook_validation_constraint_alter().
 */
function experience_builder_validation_constraint_alter(array &$definitions): void {
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
function experience_builder_field_info_alter(array &$info): void {
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
  if (!empty($info['text_long'])) {
    $info['text_long']['class'] = TextLongItemOverride::class;
  }
  if (!empty($info['text_with_summary'])) {
    $info['text_with_summary']['class'] = TextWithSummaryItemOverride::class;
  }
  if (!empty($info['uuid'])) {
    $info['uuid']['class'] = UuidItemOverride::class;
  }
  if (!empty($info['uri'])) {
    $info['uri']['class'] = UriItemOverride::class;
  }
}

/**
 * Implements hook_entity_base_field_info_alter().
 */
function experience_builder_entity_base_field_info_alter(array &$fields, EntityTypeInterface $entity_type): void {
  // The File entity type's `filename` and `filemime` base fields use the
  // `string` field type but are NOT prose (which is the default semantic for
  // that field type).
  // @see \Drupal\experience_builder\Plugin\Field\FieldType\StringItemOverride
  if ($entity_type->id() === 'file') {
    // Override the default string semantics of the "string" field type.
    // @see \Drupal\experience_builder\Plugin\Field\FieldTypeOverride\StringItemOverride::propertyDefinitions()
    $fields['filename']->addPropertyConstraints('value', ['StringSemantics' => StringSemanticsConstraint::STRUCTURED]);
    $fields['filemime']->addPropertyConstraints('value', ['StringSemantics' => StringSemanticsConstraint::STRUCTURED]);
    $fields['uri']->setRequired(TRUE);
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
function experience_builder_filter_format_access(FilterFormatInterface $format, string $operation, AccountInterface $account): AccessResult {
  $protected_formats = [
    'xb_html_inline',
    'xb_html_block',
  ];
  if (in_array($format->id(), $protected_formats, TRUE)) {
    return AccessResult::forbidden('Experience Builder text formats cannot be modified.')
      ->addCacheableDependency($format);
  }

  // No opinion on other formats.
  return AccessResult::neutral();
}

/**
 * Implements hook_editor_access().
 *
 * Prevents any operations on Experience Builder's editors.
 */
function experience_builder_editor_access(EditorInterface $editor, string $operation, AccountInterface $account): AccessResult {
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
function experience_builder_entity_operation_alter(array &$operations, EntityInterface $entity): void {
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
