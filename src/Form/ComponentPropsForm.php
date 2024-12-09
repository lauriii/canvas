<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Form;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\experience_builder\PropSource\PropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Allows editing the prop sources for a component.
 */
final class ComponentPropsForm extends FormBase {

  /**
   * The component plugin manager.
   *
   * @var \Drupal\Core\Theme\ComponentPluginManager
   */
  protected $componentPluginManager;

  public function __construct(
    ComponentPluginManager $componentPluginManager,
  ) {
    // Unable to use property injection due to extending a class that does not
    // use it.
    $this->componentPluginManager = $componentPluginManager;
  }

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
    $component_id = json_decode($this->getRequest()->get('tree'), TRUE)['type'];

    // ⚠️ This is HORRIBLY HACKY and will go away! ☺️
    // @see \Drupal\experience_builder\Controller\ApiLayoutController
    if (!$entity || ($entity->getEntityTypeId() !== 'xb_page' && $entity->bundle() !== 'article')) {
      throw new \LogicException('For now, this assumes the entity is an xb_page or an article node!');
    }

    $component = Component::load($component_id);
    assert($component !== NULL);
    $source = $component->getComponentSource();
    // @todo Support non-SDC plugins, see https://www.drupal.org/project/experience_builder/issues/3484669
    if (!$source instanceof SingleDirectoryComponent) {
      $form['#markup'] = $this->t('You clicked a %component_source component, customizing its settings is not yet supported.', ['%component_source' => $source->getPluginDefinition()['label']]);
      return $form;
    }

    $component_schema = $source->getSchema();

    // Allow form alterations specific to XB component prop forms (currently
    // only "static prop sources").
    $form_state->set('is_xb_static_prop_source', TRUE);

    // Prevent form submission while specifying values for component props,
    // because changes are saved via Redux instead of a traditional submit.
    // @see ui/src/components/form/inputBehaviors.tsx
    // @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/form#method
    $form['#method'] = 'dialog';

    $form['#parents'] = ['xb_component_props', $component_instance_uuid];
    foreach ($stored_prop_sources as $sdc_prop_name => $prop_source_array) {
      $source = PropSource::parse($prop_source_array);
      if ($source instanceof StaticPropSource) {
        // 1. If the given static prop source matches the *current* field type
        // configuration, use the configured widget.
        // 2. Worst case: fall back to the default widget for this field type.
        // @todo Implement 2. in https://www.drupal.org/project/experience_builder/issues/3463996
        $field_widget_plugin_id = NULL;
        if ($source->getSourceType() === 'static:field_item:' . $component->get('settings')['props'][$sdc_prop_name]['field_type']) {
          $field_widget_plugin_id = $component->get('settings')['props'][$sdc_prop_name]['field_widget'];
        }
        assert(isset($component_schema['properties'][$sdc_prop_name]['title']));
        $label = $component_schema['properties'][$sdc_prop_name]['title'];
        $is_required = isset($component_schema['required']) && in_array($sdc_prop_name, $component_schema['required'], TRUE);
        $form[$sdc_prop_name] = $source->formTemporaryRemoveThisExclamationExclamationExclamation($field_widget_plugin_id, $sdc_prop_name, $label, $is_required, $entity, $form, $form_state);
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
