<?php

declare(strict_types=1);

namespace Drupal\canvas\ConfigTranslation;

use Drupal\config_translation\FormElement\ListElement;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Render\Element;

/**
 * Config translation form element for Canvas component tree.
 *
 * Tweaks compared to ListElement:
 * - Removes the distracting (and useless) <details> wrapper for each component
 *   instance's `inputs`
 * - Generates a label to provide relevant context to the user: position, number
 *   of translatable inputs, et cetera
 *
 * @internal
 */
final class CanvasComponentTreeItemListFormElement extends ListElement {

  /**
   * {@inheritdoc}
   */
  public function getTranslationBuild(LanguageInterface $source_language, LanguageInterface $translation_language, $source_config, $translation_config, array $parents, $base_key = NULL) {
    $build = parent::getTranslationBuild($source_language, $translation_language, $source_config, $translation_config, $parents, $base_key);
    $raw_component_tree = $this->element->getValue();
    foreach (Element::children($build) as $position => $sequence_key) {
      \assert(\array_key_exists($sequence_key, $raw_component_tree));
      $raw_component_instance = $raw_component_tree[$sequence_key];

      // Remove the wrapping <details> for the sequence item (a mapping)'s sole
      // translatable key-value pair: `inputs`.
      \assert(\array_key_exists('inputs', $build[$sequence_key]));
      \assert(\array_key_exists('#type', $build[$sequence_key]['inputs']) && $build[$sequence_key]['inputs']['#type'] === 'details');
      unset($build[$sequence_key]['inputs']['#type']);

      // And generate a dynamic title that provides the user with useful context
      // about the component instance being translated, instead of a static
      // label that is repeated for every component instance.
      // @todo Consider making the position information more precise; look at \Drupal\canvas\EventSubscriber\ComponentTreeConfigEntityTransformer::computeExportSequenceKeys() for inspiration.
      \assert(\array_key_exists('#title', $build[$sequence_key]) && $build[$sequence_key]['#title'] === 'Config-Defined Component Tree Item');
      $build[$sequence_key]['#title'] = \sprintf(<<<HTML
Component at position %s
<br>
<small><ul>
<li>Component: <code>%s</code></li>
<li>Component instance: <code>%s</code></li>
<li>%d translatable inputs</li>
</ul></small>
HTML,
        $position,
        $raw_component_instance['component_id'],
        $raw_component_instance['uuid'],
        count(Element::children($build[$sequence_key]['inputs'])),
      );
    }

    return $build;
  }

}
