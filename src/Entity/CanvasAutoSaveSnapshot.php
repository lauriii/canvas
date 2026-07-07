<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\Entity\Storage\CanvasAutoSaveSnapshotStorageSchema;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\workspaces\Entity\Handler\IgnoredWorkspaceHandler;

/**
 * Staged payload snapshot of an entity whose draft cannot be a revision.
 *
 * Stores auto-save drafts for config entities and for content entities whose
 * draft the storage layer rejected as a workspace revision, as normalized
 * values encoded as JSON. One row per target entity type, ID and language,
 * updated in place; a successful revision persist for the same target removes
 * the row again.
 *
 * The entity type is ignored by Workspaces on purpose: snapshot rows must
 * save normally (no pending revisions, no workspace tracking) even while the
 * Canvas auto-save workspace is active.
 *
 * @see \Drupal\canvas\AutoSave\Workspace\AutoSaveSnapshotRepository
 * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceContentEntityPersist
 *
 * @ingroup entity_api
 */
#[ContentEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Canvas auto-save snapshot'),
  label_collection: new TranslatableMarkup('Canvas auto-save snapshots'),
  label_singular: new TranslatableMarkup('canvas auto-save snapshot'),
  label_plural: new TranslatableMarkup('canvas auto-save snapshots'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'uid' => 'uid',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => EntityAccessControlHandler::class,
    'storage_schema' => CanvasAutoSaveSnapshotStorageSchema::class,
    'workspace' => IgnoredWorkspaceHandler::class,
  ],
  base_table: 'canvas_auto_save_snapshot',
  translatable: FALSE,
  internal: TRUE,
  admin_permission: 'administer workspaces',
)]
final class CanvasAutoSaveSnapshot extends ContentEntityBase implements EntityOwnerInterface, EntityChangedInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public const string ENTITY_TYPE_ID = 'canvas_auto_save_snapshot';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['target_entity_type_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target entity type'))
      ->setSetting('max_length', EntityTypeInterface::ID_MAX_LENGTH)
      ->setRequired(TRUE);

    $fields['target_entity_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target entity ID'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);

    // LANGCODE_NOT_SPECIFIED (never NULL or '') for language-less targets
    // such as config entities: StringItem treats '' as empty and stores NULL,
    // which entity query conditions cannot match and SQL unique indexes do
    // not de-duplicate.
    // @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave::snapshotLangcode()
    $fields['target_langcode'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target language'))
      ->setSetting('max_length', 12)
      ->setRequired(TRUE)
      ->setDefaultValue(LanguageInterface::LANGCODE_NOT_SPECIFIED);

    $fields['payload'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Payload'))
      ->setDescription(new TranslatableMarkup('Normalized entity values as JSON.'));

    $fields['data_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Data hash'))
      ->setSetting('max_length', 128);

    $fields['client_instance_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Client instance ID'))
      ->setSetting('max_length', 128);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  public function getTargetEntityTypeId(): string {
    return $this->get('target_entity_type_id')->value;
  }

  public function getTargetEntityId(): string {
    return $this->get('target_entity_id')->value;
  }

  public function getPayload(): string {
    return $this->get('payload')->value ?? '';
  }

  public function getDataHash(): string {
    return $this->get('data_hash')->value ?? '';
  }

  public function getClientInstanceId(): ?string {
    $value = $this->get('client_instance_id')->value;
    return $value === NULL || $value === '' ? NULL : $value;
  }

}
