<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\Workspace\WorkspaceReview;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workspaces\WorkspaceInterface;

/**
 * Adds Canvas's review and scheduling base fields to the workspace entity.
 *
 * @see \Drupal\canvas\Workspace\WorkspaceReview
 * @see \Drupal\canvas\Workspace\WorkspaceScheduledPublish
 */
final class CanvasWorkspaceHooks {

  /**
   * Implements hook_entity_base_field_info().
   *
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   */
  #[Hook('entity_base_field_info')]
  public static function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if ($entity_type->id() !== 'workspace') {
      return [];
    }
    $fields = [];
    $fields['canvas_workspace_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Review state'))
      ->setDescription(new TranslatableMarkup('The Canvas review state of the workspace.'))
      ->setSetting('allowed_values', [
        WorkspaceReview::STATUS_DRAFT => new TranslatableMarkup('Draft'),
        WorkspaceReview::STATUS_IN_REVIEW => new TranslatableMarkup('In review'),
        WorkspaceReview::STATUS_APPROVED => new TranslatableMarkup('Approved'),
      ])
      ->setDefaultValue(WorkspaceReview::STATUS_DRAFT)
      ->setRequired(TRUE);

    $fields['canvas_require_review'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Require review before publishing'))
      ->setDescription(new TranslatableMarkup('Whether the workspace must be approved before it can be published.'))
      ->setDefaultValueCallback(static::class . '::defaultRequireReview')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['canvas_scheduled_publish_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Scheduled publish time'))
      ->setDescription(new TranslatableMarkup('When set, cron publishes the workspace at this time.'));

    $fields['canvas_scheduled_publish_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Scheduled by'))
      ->setDescription(new TranslatableMarkup('The user who scheduled the publish; cron publishes on their behalf.'))
      ->setSetting('target_type', 'user');

    $fields['canvas_scheduled_publish_error'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Last scheduled publish error'))
      ->setDescription(new TranslatableMarkup('The failure that cancelled the most recent scheduled publish.'));

    return $fields;
  }

  /**
   * Default value callback for `canvas_require_review`.
   *
   * The Main workspace is the scratch space and publishes without review;
   * named workspaces require review by default, matching the designed flow
   * where "Send for review" is the primary action.
   *
   * @return array<int, array<string, bool>>
   */
  // Referenced by name in the canvas_require_review field definition.
  // @phpstan-ignore shipmonk.deadMethod
  public static function defaultRequireReview(WorkspaceInterface $workspace): array {
    return [['value' => $workspace->id() !== AutoSaveWorkspace::ID]];
  }

}
