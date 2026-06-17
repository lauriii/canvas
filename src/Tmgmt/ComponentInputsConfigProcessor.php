<?php

namespace Drupal\canvas\Tmgmt;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\tmgmt_config\DefaultConfigProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extracts translatables from Canvas component instances' inputs in config.
 *
 * Note that the config schema generated for each component instance's `inputs`
 * is limited to the level of granularity that each component source's
 * `inputs_config_schema_generator` implementation chose.
 *
 * @see \Drupal\canvas\ComponentSource\ComponentInstanceInputsConfigSchemaGeneratorInterface
 * @see \Drupal\canvas\Config\Schema\ComponentInputsMapping
 *
 * For example, for the `block` ComponentSource plugin, a full config schema is
 * provided, potentially for deeply nested values. By contrast, for the `js`
 * ComponentSource plugin, each input has `type: ignore`, but may still be
 * marked translatable, and the details are delegated to a custom
 * `form_element_class`. This means it's that custom class that decides what
 * values should be generated and stored; this could mean it ends up storing
 * more than only a single translatable string: it could store an array of
 * translatable strings, or some key-value pairs of which only some contain
 * translatable strings.
 * This is how Drupal core's `config_translation` system
 * (and its UI) are designed to work.
 *
 * However, TMGMT requires the actual translatable strings to be extracted. This
 * is completely different. It limits the usefulness of a custom
 * `form_element_class`: it forces a custom `tmgmt_config_processor` to be
 * defined to extract the translatable strings that otherwise would have been
 * handled as needed by the `form_element_class`.
 *
 * @see \Drupal\tmgmt_config\Plugin\tmgmt\Source\ConfigSource::getConfigProcessor()
 *
 * The surprising final piece to this is that the `form_element_class` *is* used
 * by TMGMT to actually save the config: its `::setConfig()` method is called.
 *
 * @internal
 */
final class ComponentInputsConfigProcessor extends DefaultConfigProcessor implements ContainerInjectionInterface {

  public function __construct(
    private readonly ComponentInputsTranslatablesExtractor $componentInputsTranslatablesExtractor,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ComponentInputsTranslatablesExtractor::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function extractTranslatables($schema, $config_data, $base_key = '') {
    return $this->componentInputsTranslatablesExtractor->extractTranslatables($schema, $config_data, $base_key);
  }

}
