<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItemInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Component as SdcPlugin;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\experience_builder\ComponentSource\ComponentSourceBase;
use Drupal\experience_builder\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\experience_builder\Entity\Component as ComponentEntity;
use Drupal\experience_builder\InvalidRequestBodyValue;
use Drupal\experience_builder\MissingHostEntityException;
use Drupal\experience_builder\Plugin\DataType\ComponentInputs;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\experience_builder\PropShape\PropShape;
use Drupal\experience_builder\PropSource\PropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\experience_builder\ShapeMatcher\FieldForComponentSuggester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Explicit input UX generated from SDC metadata, using field types and widgets.
 *
 * XB ComponentSource plugins that do not have their own (native) explicit
 * input UX only need to map their explicit information to SDC metadata and can
 * then get an automatically generated field widget explicit UX, whose values
 * are stored in dangling field instances, by mapping schema to field types.
 *
 * @see \Drupal\Core\Theme\Component\ComponentMetadata
 * @see \Drupal\experience_builder\ShapeMatcher\SdcPropToFieldTypePropMatcher
 *
 * Component Source plugins included in the Experience Builder module using it:
 * - "SDC"
 * - "code components"
 *
 * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent
 * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent
 *
 * @internal
 */
abstract class GeneratedFieldExplicitInputUxComponentSourceBase extends ComponentSourceBase implements ComponentSourceWithSlotsInterface, ContainerFactoryPluginInterface {

  public const EXPLICIT_INPUT_NAME = 'props';

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly ComponentValidator $componentValidator,
    private readonly FieldTypePluginManagerInterface $fieldTypePluginManager,
    private readonly WidgetPluginManager $fieldWidgetPluginManager,
    private readonly FieldForComponentSuggester $fieldForComponentSuggester,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // @phpstan-ignore-next-line
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(ComponentValidator::class),
      $container->get(FieldTypePluginManagerInterface::class),
      $container->get('plugin.manager.field.widget'),
      $container->get(FieldForComponentSuggester::class),
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * The SDC metadata that everything else in this trait builds upon.
   *
   * @todo Refactor to only need ComponentMetadata, but that requires refactoring XB's shape matching infrastructure
   *   as well as core's component validator.
   * @see \Drupal\Core\Theme\Component\ComponentMetadata
   * @see \Drupal\experience_builder\PropShape\PropShape::getComponentProps()
   */
  abstract protected function getSdcPlugin(): SdcPlugin;

  /**
   * {@inheritdoc}
   */
  public function getDependencies(array $settings): array {
    $dependencies = $this->calculateDependencies();

    assert(isset($settings['prop_field_definitions']));
    assert(is_array($settings['prop_field_definitions']));
    foreach ($settings['prop_field_definitions'] as ['field_type' => $field_type, 'field_widget' => $field_widget]) {
      // TRICKY: `field_type` (and `field_widget`) may not be set if no field
      // types match this SDC prop shape.
      if ($field_type === NULL) {
        continue;
      }
      $field_type_definition = $this->fieldTypePluginManager->getDefinition($field_type);
      $dependencies['module'][] = $field_type_definition['provider'];
      $field_widget_definition = $this->fieldWidgetPluginManager->getDefinition($field_widget);
      $dependencies['module'][] = $field_widget_definition['provider'];
    }

    return $dependencies;
  }

  /**
   * Build the default prop source for a prop.
   *
   * @param string $prop_name
   *   The prop name.
   *
   * @return \Drupal\experience_builder\PropSource\StaticPropSource
   *   The prop source object.
   */
  private function getDefaultStaticPropSource(string $prop_name): StaticPropSource {
    assert(isset($this->configuration['prop_field_definitions']));
    assert(is_array($this->configuration['prop_field_definitions']));
    $component_schema = $this->getSdcPlugin()->metadata->schema ?? [];
    if (!array_key_exists($prop_name, $component_schema['properties'] ?? [])) {
      throw new \OutOfRangeException(sprintf("'%s' is not a prop on the component '%s'.", $prop_name, $this->getComponentDescription()));
    }

    $sdc_prop_source = [
      'sourceType' => 'static:field_item:' . $this->configuration['prop_field_definitions'][$prop_name]['field_type'],
      'value' => $this->configuration['prop_field_definitions'][$prop_name]['default_value'],
      'expression' => $this->configuration['prop_field_definitions'][$prop_name]['expression'],
    ];
    if (array_key_exists('field_storage_settings', $this->configuration['prop_field_definitions'][$prop_name])) {
      $sdc_prop_source['sourceTypeSettings']['storage'] = $this->configuration['prop_field_definitions'][$prop_name]['field_storage_settings'];
    }
    if (array_key_exists('field_instance_settings', $this->configuration['prop_field_definitions'][$prop_name])) {
      $sdc_prop_source['sourceTypeSettings']['instance'] = $this->configuration['prop_field_definitions'][$prop_name]['field_instance_settings'];
    }

    return StaticPropSource::parse($sdc_prop_source);
  }

  public function getSlotDefinitions(): array {
    return $this->getSdcPlugin()->metadata->slots;
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item): array {
    if (!$this->requiresExplicitInput()) {
      return [];
    }
    $entity = $item->getRoot() === $item ? NULL : $item->getEntity();
    $inputs = $item->get('inputs');
    assert($inputs instanceof ComponentInputs);
    $values = $inputs->getValues($uuid);
    return array_map(
    // @phpstan-ignore-next-line
      fn(array $prop_source): mixed => PropSource::parse($prop_source)
        ->evaluate($entity),
      $values,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input): array {
    $hydrated[self::EXPLICIT_INPUT_NAME] = $explicit_input;

    if ($slots = $this->getSlotDefinitions()) {
      // Use the first example defined in SDC metadata, if it exists. Otherwise,
      // fall back to `"#plain_text => ''`, which is accepted by SDC's rendering
      // logic but still results in an empty slot.
      // @see https://www.drupal.org/node/3391702
      // @see \Drupal\Core\Render\Element\ComponentElement::generateComponentTemplate()
      $hydrated['slots'] = array_map(fn($slot) => $slot['examples'][0] ?? '', $slots);
    }

    return $hydrated;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresExplicitInput(): bool {
    return !empty($this->getSdcPlugin()->metadata->schema['properties']);
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();
    foreach ($inputValues as $component_prop_name => $raw_prop_source) {
      if (str_starts_with($raw_prop_source['sourceType'], 'static:')) {
        try {
          StaticPropSource::isMinimalRepresentation($raw_prop_source);
        }
        catch (\LogicException $e) {
          $violations->add(new ConstraintViolation(
            sprintf("For component `%s`, prop `%s`, an invalid field property value was detected: %s.",
              $component_instance_uuid,
              $component_prop_name,
              $e->getMessage()),
            NULL,
            [],
            $entity,
            "inputs.$component_instance_uuid.$component_prop_name",
            $raw_prop_source,
          ));
        }
      }
    }
    try {
      $resolvedInputValues = array_map(
      // @phpstan-ignore-next-line
        fn(array $prop_source): mixed => PropSource::parse($prop_source)
          ->evaluate($entity),
        $inputValues,
      );
    }
    catch (MissingHostEntityException $e) {
      // DynamicPropSources cannot be validated in isolation, only in the
      // context of a host content entity.
      if ($entity === NULL) {
        // This case can only be hit when using a DynamicPropSource
        // inappropriately, which is validated elsewhere.
        // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeMeetsRequirementsConstraintValidator
        return $violations;
      }
      // Some component inputs (SDC props) may not be resolvable yet because\
      // required fields do not yet have values specified.
      // @see https://www.drupal.org/project/drupal/issues/2820364
      // @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem::postSave()
      elseif ($entity->isNew()) {
        // Silence this exception until the required field is populated.
        return $violations;
      }
      else {
        // The required field must be populated now (this branch can only be
        // hit when the entity already exists and hence all required fields
        // must have values already), so do not silence the exception.
        throw $e;
      }
    }

    try {
      $this->componentValidator->validateProps($resolvedInputValues, $this->getSdcPlugin());
    }
    catch (ComponentNotFoundException) {
      // The violation for a missing component will be added in the validation
      // of the tree structure.
      // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
    }
    catch (InvalidComponentException $e) {
      // Deconstruct the multi-part exception message constructed by SDC.
      // @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
      $errors = explode("\n", $e->getMessage());
      foreach ($errors as $error) {
        // An example error:
        // @code
        // [style] Does not have a value in the enumeration ["primary","secondary"]
        // @endcode
        // In that string, `[style]` is the bracket-enclosed SDC prop name
        // for which an error occurred. This string must be parsed.
        $sdc_prop_name_closing_bracket_pos = strpos($error, ']', 1);
        assert($sdc_prop_name_closing_bracket_pos !== FALSE);
        // This extracts `style` and the subsequent error message from the
        // example string above.
        $prop_name = substr($error, 1, $sdc_prop_name_closing_bracket_pos - 1);
        $prop_error_message = substr($error, $sdc_prop_name_closing_bracket_pos + 2);

        $violations->add(
          new ConstraintViolation(
            $prop_error_message,
            NULL,
            [],
            $entity,
            "inputs.$component_instance_uuid.$prop_name",
            $resolvedInputValues[$prop_name] ?? NULL,
          )
        );
      }
    }
    return $violations;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
    string $component_instance_uuid = '',
    array $client_model = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    assert($entity instanceof FieldableEntityInterface);
    $component_schema = $this->getSdcPlugin()->metadata->schema ?? [];

    // Allow form alterations specific to XB component inputs forms (currently
    // only "static prop sources").
    $form_state->set('is_xb_static_prop_source', TRUE);

    // Prevent form submission while specifying values for component inputs,
    // because changes are saved via Redux instead of a traditional submit.
    // @see ui/src/components/form/inputBehaviors.tsx
    // @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/form#method
    $form['#method'] = 'dialog';

    $form['#parents'] = ['xb_component_props', $component_instance_uuid];
    foreach ($client_model as $sdc_prop_name => $prop_source_array) {
      $source = PropSource::parse($prop_source_array);
      if ($source instanceof StaticPropSource) {
        // 1. If the given static prop source matches the *current* field type
        // configuration, use the configured widget.
        // 2. Worst case: fall back to the default widget for this field type.
        // @todo Implement 2. in https://www.drupal.org/project/experience_builder/issues/3463996
        $field_widget_plugin_id = NULL;
        if ($source->getSourceType() === 'static:field_item:' . $settings['prop_field_definitions'][$sdc_prop_name]['field_type']) {
          $field_widget_plugin_id = $settings['prop_field_definitions'][$sdc_prop_name]['field_widget'];
        }
        assert(isset($component_schema['properties'][$sdc_prop_name]['title']));
        $label = $component_schema['properties'][$sdc_prop_name]['title'];
        $is_required = isset($component_schema['required']) && in_array($sdc_prop_name, $component_schema['required'], TRUE);
        $form[$sdc_prop_name] = $source->formTemporaryRemoveThisExclamationExclamationExclamation($field_widget_plugin_id, $sdc_prop_name, $label, $is_required, $entity, $form, $form_state);
      }
      // @todo Design is undefined for the DynamicPropSource UX. Related: https://www.drupal.org/project/experience_builder/issues/3459234
      // @todo Design is undefined for the AdaptedPropSource UX.
    }

    // @todo Remove in https://www.drupal.org/project/experience_builder/issues/3500152
    $form['#attributes']['data-form-id'] = 'component_inputs_form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    // @todo Implementation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    // @todo Implementation.
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(ComponentEntity $component): array {
    $component_plugin = $this->getSdcPlugin();
    assert($component_plugin instanceof SdcPlugin);
    $keyed_choices = [];
    // @todo Refactor away this instanceof check in https://www.drupal.org/i/3503038
    $suggestions = $this instanceof SingleDirectoryComponent
      ? $this->fieldForComponentSuggester->suggest($component_plugin->getPluginId(), EntityDataDefinition::create('node', 'article'))
      : [];
    $dynamic_prop_source_candidates = [];
    $default_props_for_default_markup = [];
    foreach (PropShape::getComponentProps($component_plugin) as $component_prop_expression => $prop_shape) {
      $storable_prop_shape = $prop_shape->getStorage();
      // @todo Remove this once every SDC prop shape can be stored.
      // @todo Create a status report that lists which SDC props are not storable.
      if (!$storable_prop_shape) {
        continue;
      }
      $static_prop_source = $storable_prop_shape->toStaticPropSource();
      $component_prop = ComponentPropExpression::fromString($component_prop_expression);
      if (isset($suggestions[$component_prop_expression])) {
        $dynamic_prop_source_candidates[$component_prop->propName] = array_map(
          fn(FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $expr) => (string) $expr,
          $suggestions[$component_prop_expression]['instances']
        );
      }
      $keyed_choices[$component_prop->propName] = [
        'expression' => (string) $storable_prop_shape->fieldTypeProp,
        'sourceType' => $static_prop_source->getSourceType(),
        'required' => in_array($component_prop->propName, $component_plugin->metadata->schema['required'] ?? [], TRUE),
      ];
      $prop_info = ($component_plugin->metadata->schema['properties'] ?? [])[$component_prop->propName];
      // Defaults are guaranteed to exist for required props, may exist for
      // optional props. When an optional prop has no default value, the value
      // stored as the default in the Component config entity is NULL.
      // @see \Drupal\experience_builder\ComponentMetadataRequirementsChecker
      $is_image = isset($prop_info['$ref']) && $prop_info['$ref'] === 'json-schema-definitions://experience_builder.module/image';
      // @todo Add support for default images in SDCs: /components/image/image.component.yml. (And entity references in general.)
      // @see \Drupal\experience_builder\Entity\Component::getDefaultsForComponentPlugin
      $is_datetime = isset($prop_info['format']) && $prop_info['format'] === 'date-time';
      // @todo DateTimeItem stores information in a format that clashes with JSON schema's, and it has no automatic conversion. Figure out a better solution for both this and \Drupal\experience_builder\PropExpressions\StructuredData\Evaluator::evaluate().
      $default_value = NULL;
      if (($is_image || $is_datetime)) {
        if (isset($prop_info['examples']) && is_array($prop_info['examples']) && !empty($prop_info['examples'])) {
          $default_value = $prop_info['examples'][0];
        }
      }
      else {
        $default_value = $this->getDefaultStaticPropSource($component_prop->propName)
          ->evaluate(NULL);
      }
      if ($default_value !== NULL) {
        $keyed_choices[$component_prop->propName]['default_values'] = $default_value;
        $default_props_for_default_markup[$component_prop->propName] = $default_value;
      }
      if ($storable_prop_shape->fieldStorageSettings !== NULL) {
        $keyed_choices[$component_prop->propName]['sourceTypeSettings']['storage'] = $storable_prop_shape->fieldStorageSettings;
      }
      if ($storable_prop_shape->fieldInstanceSettings !== NULL) {
        $keyed_choices[$component_prop->propName]['sourceTypeSettings']['instance'] = $storable_prop_shape->fieldInstanceSettings;
      }
      $keyed_choices[$component_prop->propName]['jsonSchema'] = $prop_shape->resolvedSchema;
    }

    return [
      'source' => (string) $this->getSourceLabel(),
      'build' => $this->renderComponent([self::EXPLICIT_INPUT_NAME => $default_props_for_default_markup], $component->uuid()),
      // Additional data only needed for SDCs.
      // @todo UI does not use any other metadata - should `slots` move to top level?
      'metadata' => ['slots' => $this->getSlotDefinitions()],
      'field_data' => $keyed_choices,
      'dynamic_prop_source_candidates' => $dynamic_prop_source_candidates,
    ];
  }

  /**
   * Returns the source label for this component.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The source label.
   */
  abstract protected function getSourceLabel(): TranslatableMarkup;

  /**
   * Build the prop settings for an SDC component.
   *
   * @param \Drupal\Core\Plugin\Component $component_plugin
   *   The SDC component.
   *
   * @return array
   *   The prop settings.
   */
  public static function getPropsForComponentPlugin(SdcPlugin $component_plugin): array {
    $props = [];
    foreach (PropShape::getComponentProps($component_plugin) as $cpe_string => $prop_shape) {
      $cpe = ComponentPropExpression::fromString($cpe_string);

      assert(is_array($component_plugin->metadata->schema));
      // @see https://json-schema.org/understanding-json-schema/reference/object#required
      // @see https://json-schema.org/learn/getting-started-step-by-step#required
      $is_required = in_array($cpe->propName, $component_plugin->metadata->schema['required'] ?? [], TRUE);

      $skip_prop = FALSE;
      $storable_prop_shape = $prop_shape->getStorage();
      if (is_null($storable_prop_shape)) {
        continue;
      }

      if ($storable_prop_shape->fieldTypeProp instanceof FieldTypeObjectPropsExpression) {
        // @todo Add support for default images: /components/image/image.component.yml.
        if ($storable_prop_shape->fieldTypeProp->fieldType === 'entity_reference') {
          $skip_prop = TRUE;
        }
        else {
          foreach ($storable_prop_shape->fieldTypeProp->objectPropsToFieldTypeProps as $field_type_prop) {
            if ($field_type_prop instanceof ReferenceFieldTypePropExpression) {
              $skip_prop = TRUE;
            }
          }
        }
      }
      $static_prop_source = $storable_prop_shape->toStaticPropSource();

      // @see `type: experience_builder.component.*`
      assert(array_key_exists('properties', $component_plugin->metadata->schema));
      $props[$cpe->propName] = [
        'field_type' => $storable_prop_shape->fieldTypeProp->fieldType,
        'field_widget' => $storable_prop_shape->fieldWidget,
        'expression' => (string) $storable_prop_shape->fieldTypeProp,
        // TRICKY: need to transform to the array structure that depends on the
        // field type.
        // @see `type: field.storage_settings.*`
        'default_value' => $skip_prop ? [] : $static_prop_source->withValue(
          $is_required
            // Example guaranteed to exist if a required prop.
            ? $component_plugin->metadata->schema['properties'][$cpe->propName]['examples'][0]
            // Example may exist if an optional prop.
            : (
          array_key_exists('examples', $component_plugin->metadata->schema['properties'][$cpe->propName]) && array_key_exists(0, $component_plugin->metadata->schema['properties'][$cpe->propName]['examples'])
            ? $component_plugin->metadata->schema['properties'][$cpe->propName]['examples'][0]
            : NULL
          )
        )->fieldItem->getValue(),
        'field_storage_settings' => $storable_prop_shape->fieldStorageSettings ?? [],
        'field_instance_settings' => $storable_prop_shape->fieldInstanceSettings ?? [],
      ];
    }

    return $props;
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, ComponentEntity $component, array $client_model, ConstraintViolationListInterface $violations): array {
    $props = [];

    foreach ($client_model as $prop => $prop_value) {
      $static_source = $this->getDefaultStaticPropSource($prop);
      $updated_static_source = $static_source->withValue($prop_value);
      if ($static_source->fieldItem instanceof EntityReferenceItemInterface) {
        $target_type = $static_source->fieldItem->getFieldDefinition()->getSetting('target_type');
        try {
          $target_id = $this->findTargetForProps($prop_value, $target_type);
        }
        catch (InvalidRequestBodyValue $invalid) {
          $violations->add(new ConstraintViolation(
            $invalid->getMessage(),
            NULL,
            [],
            $client_model,
            $invalid->propertyPath
              ? "model.$component_instance_uuid.$prop.{$invalid->propertyPath}"
              : "model.$component_instance_uuid.$prop",
            $prop_value,
          ));
          continue;
        }
        $updated_static_source = $updated_static_source->withValue(
          array_diff_key($updated_static_source->getValue(), \array_flip(['src', 'target_id']))
          + ['target_id' => $target_id]
        );
      }
      $props[$prop] = $updated_static_source->toArray();
    }

    return $props;
  }

  /**
   * @todo Remove this function in favor of the client sending the target id in
   *   https://drupal.org/i/3473336.
   */
  private function findTargetForProps(array $prop_value, string $target_type): int {
    if ($target_type !== 'media' && $target_type !== 'file') {
      // Once the 'target_id' is saved the target type won't be needed.
      throw new InvalidRequestBodyValue("Unsupported target type '$target_type'.");
    }
    $src = $prop_value['src'];

    // Only consider public files until we save 'target_id' in the client model.
    $base_path = '/' . PublicStream::basePath() . '/';
    $relative_path = substr($src, strlen($base_path));
    $drupal_uris = ['public://' . $relative_path];

    // This might be an image style from the adapted image input, in which
    // case the image will be in the format `files/styles/{style}/{url}`.
    if (str_contains($src, 'files/styles/thumbnail') && preg_match('@/files/styles/thumbnail/public/(.*).webp@', $src, $matches)) {
      $drupal_uris[] = 'public://' . $matches[1];
    }
    if (preg_match('@/sites/.*/files/(.*)$@', $src, $matches)) {
      // This could also be running in a sub-directory, for example in CI.
      // Let's just match on sites/default/files or
      // sites/simpletest/{testid}/files.
      $drupal_uris[] = 'public://' . $matches[1];
    }

    // Load the file entity using the 'uri'. 'filename' will not always work
    // because the file name can be changed in the uri.
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $drupal_uris]);
    $file = reset($files);
    if (!$file) {
      throw new InvalidRequestBodyValue("File '$src' not found.", 'src');
    }
    $file_id = $file->id();
    if ($target_type === 'file') {
      return (int) $file_id;
    }

    // TRICKY: this is tightly coupled to `media_library_storage_prop_shape_alter()`!
    $query = $this->entityTypeManager->getStorage('media')->getQuery()->condition('field_media_image.target_id', $file_id)->accessCheck();
    $media_ids = $query->execute();
    assert(is_array($media_ids));
    if (empty($media_ids)) {
      throw new InvalidRequestBodyValue("No media entity found that uses file '$src'.", 'src');
    }
    return (int) array_pop($media_ids);
  }

}
