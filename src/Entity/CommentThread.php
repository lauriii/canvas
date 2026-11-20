<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the comment thread entity class.
 *
 * A comment thread anchors a conversation to a Canvas surface, or to one
 * component instance on it, and carries its resolved state. The messages are
 * `canvas_comment` entities.
 *
 * The anchor is the `(surface_type, surface_id, component_uuid)` triple, not
 * an entity reference, because most Canvas surfaces are config entities.
 *
 * The entity type is internal and has no access handler: the Canvas comment
 * API is the only way in, and it checks the comment permissions and view
 * access to the surface.
 *
 * @see \Drupal\canvas\Controller\ApiCommentController
 *
 * @todo Replace the flat `surface_type`, `surface_id` and `component_uuid` base fields with a dedicated `comment_anchor` field type when slot, prop and text-range anchoring are added.
 */
#[ContentEntityType(
    id: self::ENTITY_TYPE_ID,
    label: new TranslatableMarkup("Comment thread"),
    label_collection: new TranslatableMarkup("Comment threads"),
    label_singular: new TranslatableMarkup("comment thread"),
    label_plural: new TranslatableMarkup("comment threads"),
    label_count: ["@count comment thread", "@count comment threads"],
    handlers: [
      "storage" => SqlContentEntityStorage::class,
      "storage_schema" => CommentThreadStorageSchema::class,
    ],
    base_table: "canvas_comment_thread",
    entity_keys: [
      "id" => "id",
      "uuid" => "uuid",
      "owner" => "uid",
    ],
    internal: TRUE,
  )
]
final class CommentThread extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;
  public const string ENTITY_TYPE_ID = 'canvas_comment_thread';
  public const string VIEW_PERMISSION = 'view canvas comments';
  public const string CREATE_PERMISSION = 'create canvas comments';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $fields */
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += self::ownerBaseFieldDefinitions($entity_type);
    $fields['surface_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Surface type'))
      ->setDescription(t('The entity type ID of the commented surface.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);
    $fields['surface_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Surface ID'))
      ->setDescription(t('The entity ID of the commented surface.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);
    $fields['component_uuid'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Component instance UUID'))
      ->setDescription(t('The UUID of the commented component instance. Empty for a surface-level thread.'))
      ->setSetting('max_length', 128);
    // The offsets are fractions of the component's box, so the point is the
    // same at every viewport width. Both are empty for a thread with no point.
    $fields['offset_x'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Horizontal offset'))
      ->setDescription(t('Where in the component the comment was left, from 0 at its left edge to 1 at its right.'));
    $fields['offset_y'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Vertical offset'))
      ->setDescription(t('Where in the component the comment was left, from 0 at its top edge to 1 at its bottom.'));
    $fields['resolved'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Resolved'))
      ->setDescription(t('Whether this thread has been resolved.'))
      ->setDefaultValue(FALSE);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time this thread was created.'));
    return $fields;
  }

  /**
   * Gets the entity type ID of the surface this thread is anchored to.
   */
  public function getSurfaceType(): string {
    return (string) $this->get('surface_type')->value;
  }

  /**
   * Gets the entity ID of the surface this thread is anchored to.
   */
  public function getSurfaceId(): string {
    return (string) $this->get('surface_id')->value;
  }

  /**
   * Gets the UUID of the anchored component instance, if any.
   */
  public function getComponentUuid(): ?string {
    $component_uuid = $this->get('component_uuid')->value;
    if (!\is_string($component_uuid) || $component_uuid === '') {
      return NULL;
    }
    return $component_uuid;
  }

  /**
   * Where in the component the comment was left, if that was recorded.
   *
   * @return float[]|null
   *   The `x` and `y` fractions, or NULL for a thread with no recorded point.
   */
  public function getOffset(): ?array {
    $x = $this->get('offset_x')->value;
    $y = $this->get('offset_y')->value;
    if ($x === NULL || $y === NULL) {
      return NULL;
    }
    return ['x' => (float) $x, 'y' => (float) $y];
  }

  /**
   * Whether this thread is resolved.
   */
  public function isResolved(): bool {
    return (bool) $this->get('resolved')->value;
  }

  /**
   * Gets the time this thread was created, as a UNIX timestamp.
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

}
