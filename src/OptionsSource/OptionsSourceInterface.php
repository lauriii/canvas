<?php

declare(strict_types=1);

namespace Drupal\canvas\OptionsSource;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Provides the options of a component prop from site data.
 *
 * A prop uses a source with `x-canvas-options`. Its `source` key is the plugin
 * ID, the other keys are the plugin's configuration:
 * @code
 * category:
 *   type: string
 *   title: Category
 *   x-canvas-options:
 *     source: entity
 *     entity_type: taxonomy_term
 *     bundles: [tags]
 * @endcode
 *
 * Each source also defines the config schema type
 * `canvas.options_source.<plugin ID>` for its configuration, so code components
 * can use it.
 *
 * A source whose options have images also implements
 * OptionsSourceWithImagesInterface.
 *
 * @see \Drupal\canvas\Attribute\OptionsSource
 * @see \Drupal\canvas\OptionsSource\OptionsSourceWithImagesInterface
 * @see docs/user/src/content/docs/sdc-components/options-from-site-data.mdx
 */
interface OptionsSourceInterface extends PluginInspectionInterface {

  /**
   * The JSON Schema key that configures the options source of a prop.
   */
  public const string KEY = 'x-canvas-options';

  /**
   * The JSON Schema key that sets how the form offers the options of a prop.
   *
   * Its only value is `cards`: a single-value prop offers its options as cards,
   * each with an image and a label. It is a sibling of `x-canvas-options`,
   * whose keys other than `source` belong to the source.
   */
  public const string DISPLAY_KEY = 'x-canvas-options-display';

  /**
   * Lists the options for the current user.
   *
   * Runs, uncached, each time a component instance form is built. Return an
   * empty list rather than throw when the configured data no longer exists.
   *
   * @return array<string|int, string>
   *   The labels of the options, keyed by the values that props receive. Keys
   *   must be integers for `type: integer` props. Labels of nested options are
   *   paths, such as "Design › Typography", which the UI shows as a tree.
   */
  public function getOptions(): array;

  /**
   * Validates the configuration.
   *
   * @param 'string'|'integer' $type
   *   The JSON Schema type of the prop's values.
   *
   * @return list<string>
   *   Problems with the configuration, as sentences, empty if it is valid.
   */
  public function validateConfiguration(string $type): array;

  /**
   * Lists configurations to offer in the code editor.
   *
   * Code component developers pick one of these as is, so return one for each
   * useful configuration, or none if the source needs `component.yml`.
   *
   * @return list<array{label: string, configuration: array<string, mixed>}>
   *   Configurations of this source, each with a label such as "Tags".
   */
  public function getSuggestions(): array;

}
