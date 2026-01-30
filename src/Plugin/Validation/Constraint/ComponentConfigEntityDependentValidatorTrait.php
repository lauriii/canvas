<?php

declare(strict_types = 1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\canvas\Entity\Component;

/**
 * Some Drupal Canvas constraint validators need a Component config entity.
 *
 * @see \Drupal\ckeditor5\Plugin\Validation\Constraint\TextEditorObjectDependentValidatorTrait
 * @todo Remove this trait after https://www.drupal.org/project/drupal/issues/3427106 lands.
 *
 * @internal
 */
trait ComponentConfigEntityDependentValidatorTrait {

  /**
   * Creates a Component config entity from the execution context.
   *
   * @return \Drupal\canvas\Entity\Component
   *   A Component config entity object.
   */
  private function createComponentConfigEntityFromContext(): Component {
    $root = $this->context->getRoot();
    if ($root->getDataDefinition()->getDataType() === 'entity:component') {
      \assert($root instanceof ConfigEntityAdapter);
      $component = $root->getEntity();
      \assert($component instanceof Component);
      return $component;
    }
    \assert($root->getDataDefinition()->getDataType() === 'canvas.component.*' || $root->getDataDefinition()->getDataType() === 'config_entity_version:component');
    return Component::create($root->toArray());
  }

  /**
   * Gets the ComponentSource from the given Component, if possible.
   *
   * Some validators need to be able to use the ComponentSource of the
   * Component being validated. However, if the source plugin does not exist
   * (e.g. because it was deleted), or if the component is broken, then
   * getting the source will throw an exception. This method attempts to get
   * the source, but returns NULL if it is not possible.
   *
   * @param \Drupal\canvas\Entity\Component $component
   *   The Component config entity.
   *
   * @return \Drupal\canvas\ComponentSource\ComponentSourceInterface|null
   *   The ComponentSource, or NULL if it could not be obtained.
   */
  private static function getComponentSourceFromComponentIfPossible(Component $component): ?ComponentSourceInterface {
    try {
      $source = $component->getComponentSource();
    }
    catch (PluginNotFoundException) {
      // A validation error will be triggered for this by the `PluginExists`
      // constraint on the `source` key-value pair of the Component.
      return NULL;
    }
    // … and if the underlying component is not broken.
    if ($source->isBroken()) {
      // A validation error will be triggered for this by the `PluginExists`
      // constraint on the `component` key-value pair.
      // @todo Remove this early return in
      //   https://www.drupal.org/project/drupal/issues/2820364. It is only
      //   necessary because this validator should run AFTER other validators
      //   (probably last), which means that this validator cannot assume it
      //   receives valid values.
      return NULL;
    }
    return $source;
  }

}
