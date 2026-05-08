<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\DataType;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\options\Plugin\Field\FieldType\ListItemBase;

#[DataType(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Label")
)]
/**
 * @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ListStringItemOverride
 * @internal
 */
final class ListStringItemLabel extends StringData implements CacheableDependencyInterface {

  use ComputedDataTypeWithCacheabilityTrait {
    getValue as private traitGetValue;
  }

  public const string PLUGIN_ID = 'computed_list_string_label';

  private string $computedValue;

  /**
   * {@inheritdoc}
   */
  public function getValue(): string {
    return $this->traitGetValue();
  }

  /**
   * {@inheritdoc}
   */
  private function computeValue(): string {
    \assert($this->isComputed === FALSE);

    $this->cacheability = new CacheableMetadata();

    $list_item = $this->getParent();
    \assert($list_item instanceof ListItemBase);
    $value = $list_item->getValue()['value'];

    $this->cacheability->addCacheableDependency($list_item->getFieldDefinition()->getFieldStorageDefinition());
    $this->cacheability->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);
    $options = $list_item->getPossibleOptions();
    return $options[$value];
  }

}
