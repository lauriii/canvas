<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropShape;
use Drupal\experience_builder\PropSource\PropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Allows editing the prop sources for a component.
 */
final class ComponentPropsForm extends FormBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ComponentPluginManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'component_props_form';
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\Core\Entity\Entity\EntityFormDisplay::buildForm()
   * @see \Drupal\Core\Field\WidgetBase::form()
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?FieldableEntityInterface $entity = NULL): array {
    $props = $this->getRequest()->get('props');
    $component_instance_uuid = $this->getRequest()->get('selected');
    $stored_prop_sources = json_decode($props, TRUE)[$component_instance_uuid];
    $component_machine_name = json_decode($this->getRequest()->get('tree'), TRUE)['type'];

    // ⚠️ This is HORRIBLY HACKY and will go away! ☺️
    // @see \Drupal\experience_builder\Controller\SdcController::layout()
    if (!$entity || $entity->bundle() !== 'article') {
      throw new \LogicException('For now, this assumes the entity is an article!');
    }

    $component = Component::loadByComponentMachineName($component_machine_name);
    assert($component !== NULL);
    $component_plugin = $this->componentPluginManager->createInstance($component_machine_name);
    $prop_shapes = PropShape::getComponentProps($component_plugin);

    $form['#parents'] = ['xb_component_props', $component_instance_uuid];
    foreach ($stored_prop_sources as $sdc_prop_name => $prop_source_array) {
      $source = PropSource::parse($prop_source_array);
      if ($source instanceof StaticPropSource) {
        // 1. If the given static prop source matches the *current* field type
        // configuration, use the configured widget.
        // 2. Otherwise, fall back to the field widget specified in the
        // StorablePropShape.
        // 3. Worst case: fall back to the default widget for this field type.
        // @todo Improve this in https://www.drupal.org/project/experience_builder/issues/3463996
        $field_widget_plugin_id = NULL;
        if ($source->getSourceType() === 'static:field_item:' . $component->get('defaults')['props'][$sdc_prop_name]['field_type']) {
          $field_widget_plugin_id = $component->get('defaults')['props'][$sdc_prop_name]['field_widget'] ?? NULL;
        }
        else {
          $component_prop_expression = new ComponentPropExpression($component_machine_name, $sdc_prop_name);
          $prop_shape = $prop_shapes[(string) $component_prop_expression];
          $storable_prop_shape = $prop_shape->getStorage();
          if ($storable_prop_shape !== NULL && $source->getSourceType() === $storable_prop_shape->toStaticPropSource()->getSourceType()) {
            $field_widget_plugin_id = $storable_prop_shape->fieldWidget;
          }
        }
        // @todo Remove the fallback value in https://www.drupal.org/project/experience_builder/issues/3463999 — that will make the presence of `title` for each SDC prop required.
        $label = $component_plugin->metadata->schema['properties'][$sdc_prop_name]['title'] ?? $sdc_prop_name;
        $form[$sdc_prop_name] = $source->formTemporaryRemoveThisExclamationExclamationExclamation($field_widget_plugin_id, $component_instance_uuid, $sdc_prop_name, $label, $entity, $form, $form_state);
      }
      // @todo Design is undefined for the DynamicPropSource UX. Related: https://www.drupal.org/project/experience_builder/issues/3459234
      // @todo Design is undefined for the AdaptedPropSource UX.
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // @todo implement submitForm() method.
  }

}
