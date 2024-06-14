<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\DataType;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Render\RenderableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * @todo Do we need multiple variations of this? See \Drupal\datetime\DateTimeComputed for an example where there's *settings*
 */
#[DataType(
  id: "component_tree_hydrated",
  label: new TranslatableMarkup("Hydrated component tree"),
  description: new TranslatableMarkup("Computed from tree structure + props values"),
)]
class ComponentTreeHydrated extends TypedData implements CacheableDependencyInterface, RenderableInterface {

  /**
   * {@inheritdoc}
   */
  public function getValue(): CacheableJsonResponse {
    $item = $this->getParent();
    assert($item instanceof ComponentTreeItem);
    $tree = $item->get('tree');
    assert($tree instanceof ComponentTreeStructure);

    $hydrated = [];
    foreach ($tree->getComponentInstanceUuids() as $uuid) {
      $sdc_component_id = $tree->getComponentId($uuid);
      $sdc_component_props = $item->resolveComponentProps($uuid);
      $hydrated[$uuid] = [
        'component' => $sdc_component_id,
        'props' => $sdc_component_props,
        'slots' => [
          // @todo support nesting!
        ],
      ];
    }

    return (new CacheableJsonResponse())
      ->addCacheableDependency($this->getCacheability())
      ->setData($hydrated);
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE): void {
    // There is nothing to set, so return early.
    // @todo This is an upstream core bug, because this is defined as both computed and read-only, yet it still gets called 🙃 Once the core bug is fixed, this should throw a ReadOnlyException.
    // throw new ReadOnlyException();
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable(): array {
    // ⚠️ We *could* convert to a render array directly. But that should not be
    // the source of truth. So we start from a Drupal Render API-agnostic point,
    // and map that into a render array. This guarantees none of this will ever
    // rely on Render API specifics.
    // Note: see commented out code below for the "direct render array"
    // equivalent.
    $renderable_component_tree = $this->getValue();

    $build = [];
    // @see \Drupal\Core\Entity\EntityViewBuilder::getBuildDefaults()
    $renderable_component_tree->getCacheableMetadata()->applyTo($build);

    $json = $renderable_component_tree->getContent();
    assert(is_string($json));
    $hydrated = json_decode($json, TRUE);

    $build = [];
    foreach ($hydrated as $uuid => $values) {
      $build[$uuid] = [
        '#type' => 'component',
      ];
      foreach ($values as $key => $value) {
        $build[$uuid]["#$key"] = $value;
      }
    }
    return $build;

    // phpcs:disable
    /*
    $item = $this->getParent();
    assert($item instanceof ComponentTreeItem);
    $tree = $item->get('tree');
    assert($tree instanceof ComponentTreeStructure);

    $build = [];
    // @see \Drupal\Core\Entity\EntityViewBuilder::getBuildDefaults()
    $this->getCacheability()->applyTo($build);
    foreach ($tree->getComponentInstanceUuids() as $uuid) {
      $sdc_component_id = $tree->getComponentId($uuid);
      $sdc_component_props = $item->resolveComponentProps($uuid);
      $build[$uuid] = [
        '#type' => 'component',
        '#component' => $sdc_component_id,
        '#props' => $sdc_component_props,
        '#slots' => [
          // @todo support nesting!
        ],
      ];
    }

    return $build;
    */
    // phpcs:enable
  }

  /**
   * Computes the cacheability of this computed property.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cacheability of the computed value.
   */
  private function getCacheability(): CacheableMetadata {
    // @todo Once bundle-level defaults for `tree` + `props` are supported, this should also include cacheability of whatever config that is stored in.
    // @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem::preSave()

    $root = $this->getRoot();
    assert($root instanceof EntityAdapter);
    return CacheableMetadata::createFromObject($root->getEntity());
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->getCacheability()->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return $this->getCacheability()->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return $this->getCacheability()->getCacheMaxAge();
  }

}
