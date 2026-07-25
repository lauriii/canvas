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
 * Defines the comment entity class.
 *
 * A comment is one message in a `canvas_comment_thread`. The thread carries
 * the anchor and the resolved state; this entity carries only the message.
 *
 * This entity type is unrelated to the one provided by Drupal core's `comment`
 * module, and Canvas does not depend on that module.
 *
 * @see \Drupal\canvas\Entity\CommentThread
 */
#[ContentEntityType(
    id: self::ENTITY_TYPE_ID,
    label: new TranslatableMarkup("Comment"),
    label_collection: new TranslatableMarkup("Comments"),
    label_singular: new TranslatableMarkup("comment"),
    label_plural: new TranslatableMarkup("comments"),
    label_count: ["@count comment", "@count comments"],
    handlers: [
      "storage" => SqlContentEntityStorage::class,
      "access" => CommentAccessControlHandler::class,
    ],
    base_table: "canvas_comment",
    entity_keys: [
      "id" => "id",
      "uuid" => "uuid",
      "owner" => "uid",
    ],
  )
]
final class Comment extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;
  public const string ENTITY_TYPE_ID = 'canvas_comment';

  /**
   * The maximum length of a comment body, in characters.
   */
  public const int BODY_MAX_LENGTH = 65536;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $fields */
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += self::ownerBaseFieldDefinitions($entity_type);
    // @todo Delete a thread's comments when the thread is deleted, and delete threads whose surface no longer exists.
    $fields['thread'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Thread'))
      ->setDescription(t('The comment thread this comment belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', CommentThread::ENTITY_TYPE_ID);
    $fields['body'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Body'))
      ->setDescription(t('The message.'))
      ->setRequired(TRUE)
      ->addPropertyConstraints('value', [
        'NotBlank' => [],
        'Length' => ['max' => self::BODY_MAX_LENGTH],
      ]);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time this comment was created.'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time this comment was last changed.'));
    return $fields;
  }

  /**
   * Gets the message.
   */
  public function getBody(): string {
    return (string) $this->get('body')->value;
  }

  /**
   * Gets the time this comment was created, as a UNIX timestamp.
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * Gets the time this comment last changed, as a UNIX timestamp.
   */
  public function getChangedTime(): int {
    return (int) $this->get('changed')->value;
  }

}
