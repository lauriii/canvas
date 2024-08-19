<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Render\Element;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\FieldForComponentSuggester;
use Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\experience_builder\PropShape;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\experience_builder\SdcPropJsonSchemaType;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\ConfigFormBaseTrait;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Validator\ConstraintViolation;

class ComponentEditForm extends EntityForm implements ContainerInjectionInterface {
  use ConfigFormBaseTrait;

  public function __construct(
    protected readonly ComponentPluginManager $pluginManager,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly WidgetPluginManager $widgetPluginManager,
    protected readonly FieldForComponentSuggester $fieldForComponentSuggester,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.sdc'),
      $container->get('typed_data_manager'),
      $container->get('plugin.manager.field.widget'),
      $container->get(FieldForComponentSuggester::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['component.component'];
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    assert($this->entity instanceof Component);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $this->entity->label(),
      '#description' => $this->t("Example: 'Image component'."),
      '#required' => TRUE,
    ];

    if ($this->entity->isNew()) {
      $form['component'] = [
        '#type' => 'select',
        '#title' => $this->t('Component'),
        '#description' => $this->t("Component to be used in Experience Builder"),
        '#required' => TRUE,
      ];
      $components = $this->pluginManager->getAllComponents();
    }
    else {
      $components = [$this->pluginManager->find($this->entity->getComponentMachineName())];
    }

    $options = [];
    foreach ($components as $component) {
      assert($component instanceof ComponentPlugin);
      if ($this->entity->isNew() && Component::loadByComponentMachineName($component->getPluginId()) instanceof Component) {
        continue;
      }

      $schema = $component->metadata->schema;
      if (!is_array($schema) || !array_key_exists('required', $schema)) {
        continue;
      }

      if (is_array($component->getPluginDefinition()) && array_key_exists('name', $component->getPluginDefinition())) {
        $value = $component->getPluginDefinition()['name'];
      }
      else {
        $value = $component->getDerivativeId();
      }

      $options[$component->getBaseId()][Component::convertMachineNameToId($component->getPluginId())] = $value;

      try {
        $suggestions = $this->fieldForComponentSuggester->suggest($component->getPluginId(), NULL);
      }
      catch (\LogicException $e) {
        if ($e->getMessage() == 'Support for "array" props is not yet implemented.') {
          continue;
        }
        throw $e;
      }
      $form[Component::convertMachineNameToId($component->getPluginId())]['__default_props__' . Component::convertMachineNameToId($component->getPluginId())] = [
        '#type' => 'vertical_tabs',
        '#description' => 'Configure which field type and widget to use for each component property. Default values may also be specified and determine the default preview of the component.',
        '#states' => [
          'visible' => [
            [
              ':input[name="component"]' => ['value' => Component::convertMachineNameToId($component->getPluginId())],
            ],
          ],
        ],
      ];
      $form['live_preview'] = [
        '#type' => 'container',
        '#markup' => '✨ @TODO: live preview of the component, using the defaults specified in the vertical tabs 👆',
      ];

      $prop_shapes = PropShape::getComponentProps($component);
      foreach ($suggestions as $component_prop_expression => ['required' => $is_required, 'types' => $static_prop_source_suggestions]) {
        $component_prop_name = ComponentPropExpression::fromString($component_prop_expression)->propName;

        $prop_schema = $schema['properties'][$component_prop_name];
        $primitive_type = SdcPropJsonSchemaType::from(
          // TRICKY: SDC always allowed `object` for Twig integration reasons.
          // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
          is_array($prop_schema['type']) ? $prop_schema['type'][0] : $prop_schema['type']
        );

        $form[Component::convertMachineNameToId($component->getPluginId())][$component_prop_name] = [
          '#type' => 'details',
          '#group' => '__default_props__' . Component::convertMachineNameToId($component->getPluginId()),
          '#title' => sprintf("<code>%s</code> (%s)", $component_prop_name, $is_required ? 'required' : 'optional'),
          '#attributes' => [
            'id' => $component_prop_name,
          ],
          '#description' => $primitive_type->value !== SdcPropJsonSchemaType::STRING->value
            ? sprintf("Prop shape: JSON schema type <code>%s</code>", $primitive_type->value)
            : sprintf("Prop shape: JSON schema type <code>%s</code>, format: %s", $primitive_type->value, array_key_exists('format', $prop_schema)
              ? '<code>' . JsonSchemaStringFormat::from($prop_schema['format'])->value . '</code>'
              : (
                array_key_exists('enum', $prop_schema)
                  ? 'none, but a list of allowed values ⇒ ' . StringSemanticsConstraint::STRUCTURED
                  : 'none ⇒ ' . StringSemanticsConstraint::PROSE
              ),
          ),
        ];

        $storable_prop_shape = $prop_shapes[$component_prop_expression]->getStorage();
        $widget_type_options = [];
        $field_type_options = [];
        $widget_forms = [];
        if (empty($static_prop_source_suggestions)) {
          // @see https://www.drupal.org/project/experience_builder/issues/3463583#comment-15710082
          // @todo This, and this entire file, will be removed in https://www.drupal.org/project/experience_builder/issues/3464025
          if (array_key_exists($component_prop_name, $this->entity->get('defaults')['props'] ?? [])) {
            // @phpstan-ignore-next-line
            \Drupal::messenger()->addWarning('This test-only component is provided as-is and cannot be edited.');
          }
          $form[Component::convertMachineNameToId($component->getPluginId())][$component_prop_name]['skip'] = [
            '#type' => 'container',
            '#markup' => $storable_prop_shape === NULL
              ? "⚠️ Skipped <b>$component_prop_name</b> as it has no available field types"
              : "<b>$component_prop_name</b> requires a field type to be precisely configured, configuring that in this UI is not supported.",
          ];
          continue;
        }

        foreach ($static_prop_source_suggestions as $field_type_label => $static_prop_source_expression) {
          if ($static_prop_source_expression instanceof ReferenceFieldTypePropExpression) {
            $form[Component::convertMachineNameToId($component->getPluginId())][$component_prop_name][$static_prop_source_expression->referencer->fieldType]['skip'] = [
              '#type' => 'container',
              '#markup' => "Skipped <b>$field_type_label</b> as it is instance of ReferenceFieldTypePropExpression",
            ];
            continue;
          }

          /* @todo Change to this, once the form is meant to handle reference field types.
           * $field_type = $prop_suggestion instanceof ReferenceFieldTypePropExpression
           * ? $prop_suggestion->referencer->fieldType
           * : $prop_suggestion->fieldType;
           */
          $field_type = $static_prop_source_expression->fieldType;
          $form_state->set("expressions|$component_prop_name|$field_type", $static_prop_source_expression);
          $field_type_options[$field_type] = $field_type_label;

          foreach ($this->widgetPluginManager->getOptions($field_type) as $widget_type => $option) {
            $widget_type_options[$field_type][$widget_type] = $option;
            $parents['#parents'] = [Component::convertMachineNameToId($component->getPluginId()), $component_prop_name, 'widget', $widget_type];

            // Generate an empty static prop source using the
            // StructuredDataPropExpressionInterface returned by the suggester
            // service.
            $static_prop_source = StaticPropSource::generate($static_prop_source_expression);
            // If a default value is already stored, populate the prop source.
            if (!$this->entity->isNew() && array_key_exists($component_prop_name, $this->entity->get('defaults')['props']) && $this->entity->get('defaults')['props'][$component_prop_name]['field_type'] === $field_type) {
              $static_prop_source = StaticPropSource::parse([
                'sourceType' => $static_prop_source->getSourceType(),
                'value' => $this->entity->get('defaults')['props'][$component_prop_name]['default_value'],
                'expression' => (string) $static_prop_source_expression,
              ]);
            }
            $form_state->set("static_prop_sources|$component_prop_name|$field_type|$widget_type", $static_prop_source);

            // @see \Drupal\Core\Field\WidgetBase::handlesMultipleValues()
            // @see \Drupal\Core\Field\Attribute\FieldWidget::__construct(multiple_values)
            $widget_plugin_definition = $static_prop_source->getWidget($component_prop_name, $component_prop_name, NULL)->getPluginDefinition();
            assert(is_array($widget_plugin_definition) && array_key_exists('multiple_values', $widget_plugin_definition));
            $handles_multiple_values = $widget_plugin_definition['multiple_values'];

            // @todo Refactor to use \Drupal\Core\Field\FieldItemListInterface::defaultValuesForm(), just like \Drupal\field_ui\Form\FieldConfigEditForm::form()?
            $widget_form = $static_prop_source->formTemporaryRemoveThisExclamationExclamationExclamation(NULL, 'nonsensical-uuid', $component_prop_name, $component_prop_name, User::create([]), $parents, $form_state);
            $single_item_form_path = ['widget', 0];
            if (!$handles_multiple_values) {
              // @phpstan-ignore-next-line
              $children = Element::children(NestedArray::getValue($widget_form, $single_item_form_path));
              $single_item_form_path[] = reset($children);
            }
            NestedArray::setValue($widget_form, [...$single_item_form_path, '#description'], 'Default value — used for preview');
            $widget_form['#description'] = 'Default value — used for preview';
            $widget_form['#states'] = [
              'visible' => [
                [
                  ':input[name="' . Component::convertMachineNameToId($component->getPluginId()) . '[' . $component_prop_name . '][widget_type][' . $field_type . ']"]' => ['value' => $widget_type],
                  'and',
                  ':input[name="' . Component::convertMachineNameToId($component->getPluginId()) . '[' . $component_prop_name . '][field_type]"]' => ['value' => $field_type],
                ],
              ],
            ];
            $widget_forms[$widget_type][] = $widget_form;
          }
        }

        if (!empty($field_type_options)) {
          $form[Component::convertMachineNameToId($component->getPluginId())][$component_prop_name]['field_type'] = [
            '#type' => 'select',
            '#title' => $this->t('Field type'),
            '#required' => !$this->entity->isNew(),
            '#description' => $this->t("Field type to be used for the prop"),
            '#options' => $field_type_options,
            '#empty_option' => $this->t('- Select -'),
            '#default_value' => $this->entity->isNew() ? ($storable_prop_shape ? $storable_prop_shape->fieldTypeProp->fieldType : NULL) : $this->entity->get('defaults')['props'][$component_prop_name]['field_type'] ?? NULL,
            '#parents' => [Component::convertMachineNameToId($component->getPluginId()), $component_prop_name, 'field_type'],
            '#states' => [
              'required' => [
                ':input[name="component"]' => ['value' => Component::convertMachineNameToId($component->getPluginId())],
              ],
            ],
          ];
        }
        if (!empty($widget_type_options)) {
          foreach ($widget_type_options as $field_type_key => $widget_options) {
            $form[Component::convertMachineNameToId($component->getPluginId())][$component_prop_name]['widget_type'][$field_type_key] = [
              '#type' => 'select',
              '#title' => $this->t('Widget'),
              '#description' => $this->t("Widget to be used for the prop — choices depend on the field type."),
              '#options' => $widget_options,
              '#empty_option' => $this->t('- Select -'),
              '#default_value' => $this->entity->isNew() ? ($storable_prop_shape ? $storable_prop_shape->fieldWidget : NULL) : $this->entity->get('defaults')['props'][$component_prop_name]['field_widget'] ?? NULL,
              '#parents' => [Component::convertMachineNameToId($component->getPluginId()), $component_prop_name, 'widget_type', $field_type_key],
              // @todo Make this required, this is not currently possible because this >1 <select> is generated: one per possible field type, and only the appropriate one is visible thanks to #states.
              '#states' => [
                'visible' => [
                  [
                    ':input[name="' . Component::convertMachineNameToId($component->getPluginId()) . '[' . $component_prop_name . '][field_type]"]' => ['value' => $field_type_key],
                  ],
                ],
                'required' => [
                  ':input[name="' . Component::convertMachineNameToId($component->getPluginId()) . '[' . $component_prop_name . '][field_type]"]' => ['value' => $field_type_key],
                ],
              ],
            ];
          }
        }
        if (!empty($widget_forms)) {
          foreach ($widget_forms as $key => $value) {
            $form[Component::convertMachineNameToId($component->getPluginId())][$component_prop_name]['widget'][$key] = $value;
          }
        }

      }
    }

    if ($this->entity->isNew()) {
      $form['component']['#options'] = $options;
    }

    return $form;
  }

  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof Component);
    $values = $form_state->getValues();

    foreach ($form_state->getValues() as $key => $value) {
      if (!in_array($key, ['label', 'component'])) {
        continue;
      }
      $entity->set($key, $value);
    }
    $defaults = ['props' => []];

    $component = $entity->isNew() ? $values['component'] : $entity->id();
    foreach ($values[$component] as $prop_name => $default) {
      $selected_field_type = $default['field_type'];
      $selected_field_widget = $default['widget_type'][$selected_field_type];

      // Extract the relevant values, but DO NOT minimize the field value: that
      // is expected for XB's field storage, but is not expected for config.
      // @see `type: field.value.*`
      // @see \Drupal\experience_builder\PropSource\StaticPropSource::minimizeValue()
      // @see \Drupal\experience_builder\PropSource\StaticPropSource::isMinimalRepresentation()
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentPropsValues::ensureMinimalPropSourceRepresentations()
      $raw_default_value = $default['widget'][$selected_field_widget][$prop_name];
      $static_prop_source = $form_state->getStorage()["static_prop_sources|$prop_name|$selected_field_type|$selected_field_widget"];
      assert($static_prop_source instanceof StaticPropSource);
      $massaged_default_value = $static_prop_source->massageFormValuesTemporaryRemoveThisExclamationExclamationExclamation(NULL, 'nonsensical-uuid', 'nonsensical-uuid', $raw_default_value, $form, $form_state);

      $defaults['props'][$prop_name] = [
        'field_type' => $selected_field_type,
        'field_widget' => $selected_field_widget,
        'default_value' => $massaged_default_value,
        'expression' => (string) $form_state->getStorage()["expressions|$prop_name|$selected_field_type"],
      ];
    }

    $entity->set('defaults', $defaults);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $violations = $this->entity->getTypedData()->validate();
    $index = 0;
    foreach ($violations as $violation) {
      assert($violation instanceof ConstraintViolation);
      // @todo Remove this silly index-based work-around, instead eventually associate with the correct form elements.
      $form_state->setErrorByName((string) $index++, $violation->getPropertyPath() . ': ' . $violation->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    assert($this->entity instanceof Component);
    $status = $this->entity->save();
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));

    if ($status == SAVED_UPDATED) {
      $this->messenger()->addStatus($this->t('Component %label has been updated.', ['%label' => $this->entity->label()]));
      $this->logger('experience_builder')->notice('Component %label has been updated.', ['%label' => $this->entity->label()]);
      return $status;
    }
    $this->messenger()->addStatus($this->t('Component %label has been added.', ['%label' => $this->entity->label()]));
    $this->logger('experience_builder')->notice('Component %label has been added.', ['%label' => $this->entity->label()]);

    return $status;
  }

}
