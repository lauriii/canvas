<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\DataType;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\experience_builder\Entity\VersionedConfigEntityInterface;
use Drupal\experience_builder\Plugin\DataType\Deriver\ConfigEntityVersionDeriver;
use Drupal\experience_builder\TypedData\ConfigEntityVersionDataDefinition;

/**
 * Defines the "config_entity_version" data type.
 *
 * Instances of this class wrap config entity objects that support versioning.
 *
 * In addition to the "config_entity_version" data type, this exposes a derived
 * "config_entity_version:$entity_type" data type.
 */
#[DataType(
  id: self::PLUGIN_ID, label: new TranslatableMarkup('Config Entity version'),
  description: new TranslatableMarkup('A configuration entity with versionable properties'),
  definition_class: ConfigEntityVersionDataDefinition::class,
  deriver: ConfigEntityVersionDeriver::class
)]
final class ConfigEntityVersionAdapter extends ConfigEntityAdapter implements \IteratorAggregate, ComplexDataInterface {

  public const string PLUGIN_ID = 'config_entity_version';

  /**
   * {@inheritdoc}
   */
  public function getValue(): VersionedConfigEntityInterface {
    /** @var \Drupal\experience_builder\Entity\VersionedConfigEntityInterface */
    return parent::getValue();
  }

  /**
   * {@inheritdoc}
   */
  public static function createFromEntity(EntityInterface $entity): static {
    $definition = ConfigEntityVersionDataDefinition::createFromDataType(\sprintf('%s:%s', self::PLUGIN_ID, $entity->getEntityTypeId()));
    $instance = new static($definition);
    $instance->setValue($entity);
    return $instance;
  }

}
