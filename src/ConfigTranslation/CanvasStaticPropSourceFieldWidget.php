<?php

declare(strict_types=1);

namespace Drupal\canvas\ConfigTranslation;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\config_translation\FormElement\FormElementBase;
use Drupal\Core\Config\Config;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\language\Config\LanguageConfigOverride;

/**
 * Config translation form element for Canvas static prop source values.
 *
 * Reuses Canvas field widget infrastructure to render the translation UI for
 * any static prop source, regardless of field type — both single-property
 * (e.g. StringItem → scalar) and multi-property (e.g. TextLongItem → array).
 *
 * @internal
 *
 * @see \Drupal\canvas\Config\Schema\ComponentInputsMapping
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator
 */
final class CanvasStaticPropSourceFieldWidget extends FormElementBase {

  /**
   * Work around core bug: `::toArray()` is not described on any interface.
   *
   * Since this is the only way to access this information, narrow the type to
   * the concrete implementation.
   *
   * @var \Drupal\Core\TypedData\DataDefinition
   * @phpstan-ignore-next-line property.phpDocType
   */
  protected $definition;

  /**
   * {@inheritdoc}
   */
  public function __construct(TypedDataInterface $element) {
    parent::__construct($element);
    \assert($this->definition instanceof DataDefinition);
  }

  /**
   * {@inheritdoc}
   */
  public function getTranslationBuild(LanguageInterface $source_language, LanguageInterface $translation_language, $source_config, $translation_config, array $parents, $base_key = NULL) {
    $build = parent::getTranslationBuild($source_language, $translation_language, $source_config, $translation_config, $parents, $base_key);
    \assert(\array_key_exists('widget', $build['source']));
    \assert(\array_key_exists('widget', $build['translation']));

    // Field widgets are nested one level deeper; the parent implementation does
    // not account for that. Solution: transplant #parents.
    $build['source']['widget']['#parents'] = $build['source']['#parents'];
    unset($build['source']['#parents']);
    $build['translation']['widget']['#parents'] = $build['translation']['#parents'];
    unset($build['translation']['#parents']);

    return $build;
  }

  public function getSourceElement(LanguageInterface $source_language, $source_config): array {
    $widget_form = $this->buildWidgetForm($source_config);
    if ($widget_form === NULL) {
      return parent::getSourceElement($source_language, $source_config);
    }

    $widget_form['#disabled'] = TRUE;
    return $widget_form;
  }

  public function getTranslationElement(LanguageInterface $translation_language, $source_config, $translation_config): array {
    $widget_form = $this->buildWidgetForm($translation_config);
    if ($widget_form === NULL) {
      return parent::getTranslationElement($translation_language, $source_config, $translation_config);
    }

    return $widget_form + parent::getTranslationElement($translation_language, $source_config, $translation_config);
  }

  public function setConfig(Config $base_config, LanguageConfigOverride $config_translation, $config_values, $base_key = NULL): void {
    \assert(\is_string($base_key));

    // Field widgets always generate explicit delta values; even if it's single
    // cardinality.
    \assert(\array_is_list($config_values), 'Values not keyed by deltas; this does not seem to be a form submission? Every field widget keys values by field item deltas.');

    // Optimized ("collapsed") value.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::collapse()
    $default_static_prop_source = self::getDefaultStaticPropSource($this->definition);
    \assert(!\is_null($default_static_prop_source));
    $optimized_value = $default_static_prop_source
      // Pass the raw values expected by the field type.
      ->withValue($config_values)
      // Get the optimized value to store.
      ->getValue();

    // Basic sanity check: the result must be either:
    // - a list of optimized values (cardinality >1)
    // - anything except a list (cardinality === 1)
    \assert(($default_static_prop_source->getCardinality() === 1 && (!\is_array($optimized_value) || !\array_is_list($optimized_value))) || ($default_static_prop_source->getCardinality() !== 1 && \array_is_list($optimized_value)));

    // Recursively check equality; only store actual overrides.
    if (!\is_array($optimized_value)) {
      if ($base_config->get($base_key) !== $optimized_value) {
        $config_translation->set($base_key, $optimized_value);
      }
      else {
        $config_translation->clear($base_key);
      }
      return;
    }

    // If the optimized value is an array, store only subkeys. This is at most
    // for 2 levels deep:
    // - Single-cardinality + multi-property field types (`link` and `text`)
    // - Multiple-cardinality + single-property field types (keys are deltas)
    // - Multiple-cardinality + multi-property field types => 2 levels
    // @todo update for 2 levels as soon as `type: array` support is added
    foreach ($optimized_value as $k => $v) {
      $subkey = "$base_key.$k";
      if ($base_config->get($subkey) !== $v) {
        $config_translation->set($base_key, $optimized_value);
      }
      else {
        $config_translation->clear($subkey);
      }
    }
  }

  /**
   * @return array{component_id: string, component_version: string, prop_name: string}
   */
  private static function getTranslationContext(DataDefinition $definition): array {
    \assert(\array_key_exists('_canvas_config_translation_form_element_context', $definition->toArray()));
    $canvas_config_translation_form_element_context = $definition->toArray()['_canvas_config_translation_form_element_context'];
    \assert(\array_keys($canvas_config_translation_form_element_context) === [
      'component_id',
      'component_version',
      'prop_name',
    ]);
    return $canvas_config_translation_form_element_context;
  }

  private static function getDefaultStaticPropSource(DataDefinition $definition): ?StaticPropSource {
    [
      'component_id' => $component_id,
      'component_version' => $component_version,
      'prop_name' => $prop_name,
    ] = self::getTranslationContext($definition);

    if (!\is_string($component_id) || !\is_string($component_version) || !\is_string($prop_name)) {
      return NULL;
    }

    $component = Component::load($component_id);
    if ($component === NULL) {
      return NULL;
    }

    try {
      $component_source = $component->loadVersion($component_version)->getComponentSource();
    }
    catch (\OutOfRangeException) {
      return NULL;
    }

    if (!$component_source instanceof JsonSchemaPropsComponentSourceBase) {
      return NULL;
    }

    try {
      return $component_source->getDefaultStaticPropSource($prop_name, FALSE);
    }
    catch (\OutOfRangeException) {
      return NULL;
    }
  }

  private function buildWidgetForm(mixed $config_value): ?array {
    $static_prop_source = self::getDefaultStaticPropSource($this->definition)
      ?->withValue($config_value);
    if ($static_prop_source === NULL) {
      return NULL;
    }

    [
      'component_id' => $component_id,
      'component_version' => $component_version,
      'prop_name' => $prop_name,
    ] = self::getTranslationContext($this->definition);

    $form_stub = ['#parents' => []];
    $form_state = new FormState();
    $form_state->addBuildInfo('callback_object', NULL);
    $widget = $static_prop_source->getWidget(
      component_config_entity_id: $component_id,
      component_config_entity_version: $component_version,
      prop_name: $prop_name,
      sdc_prop_label: (string) ($this->definition->getLabel() ?? $prop_name),
      field_widget_plugin_id: NULL,
    );

    // Some field widgets need an entity object. Provide such a "parent" entity.
    // @see \Drupal\Core\Field\FieldItemListInterface::getEntity()
    // @see \Drupal\canvas\PropSource\StaticPropSource::formTemporaryRemoveThisExclamationExclamationExclamation()
    // @see \Drupal\ckeditor5\Hook\Ckeditor5Hooks::fieldWidgetSingleElementFormAlter()
    $config_name = $this->element->getRoot()->getName();
    \assert(\is_string($config_name));
    [$module, $config_entity_type_id, $id] = explode('.', $config_name, 3);
    if ($module !== 'canvas') {
      throw new \LogicException();
    }
    $config_entity = match ($config_entity_type_id) {
      ContentTemplate::ENTITY_TYPE_ID => ContentTemplate::load($id),
      PageRegion::ENTITY_TYPE_ID => PageRegion::load($id),
      default => throw new \LogicException(),
    };
    $entity_object_for_field_widget = match (TRUE) {
      $config_entity instanceof ContentTemplate => $config_entity->createEmptyTargetEntity(),
      default => NULL,
    };

    return $static_prop_source->formTemporaryRemoveThisExclamationExclamationExclamation(
      widget: $widget,
      sdc_prop_name: $prop_name,
      is_required: FALSE,
      host_entity: $entity_object_for_field_widget,
      form: $form_stub,
      form_state: $form_state,
    );
  }

}
