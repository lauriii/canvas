<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Plugin\Component as SdcPlugin;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\Attribute\ComponentSource;
use Drupal\experience_builder\ComponentDoesNotMeetRequirementsException;
use Drupal\experience_builder\ComponentMetadataRequirementsChecker;
use Drupal\experience_builder\Entity\Component as ComponentEntity;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;

/**
 * Defines a component source based on XB JavaScript Component config entities.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Code Components')
)]
final class JsComponent extends GeneratedFieldExplicitInputUxComponentSourceBase {

  public const SOURCE_PLUGIN_ID = 'js';

  /**
   * {@inheritdoc}
   */
  protected function getSdcPlugin(): SdcPlugin {
    return self::buildEphemeralSdcPluginInstance($this->getJavaScriptComponent());
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      // @todo Rename in https://www.drupal.org/project/experience_builder/issues/3502982
      'plugin_id' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    // This component source doesn't use plugin classes.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getJavaScriptComponent(): JavaScriptComponent {
    $js_component_storage = $this->entityTypeManager->getStorage('js_component');
    assert($js_component_storage instanceof ConfigEntityStorageInterface);
    // @todo Rename plugin ID in https://www.drupal.org/project/experience_builder/issues/3502982
    $js_component = $js_component_storage->load($this->configuration['plugin_id']);
    assert($js_component instanceof JavaScriptComponent);
    return $js_component;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $dependencies = parent::calculateDependencies();
    // @todo Add the global asset library in https://www.drupal.org/project/experience_builder/issues/3499933.
    $dependencies['config'][] = $this->getJavaScriptComponent()->getConfigDependencyName();
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    try {
      $js_component = $this->getJavaScriptComponent();
      return new TranslatableMarkup('Code component: %name', [
        '%name' => $js_component->label(),
      ]);
    }
    catch (\Exception) {
      return new TranslatableMarkup('Invalid/broken code component');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, string $componentUuid): array {
    return [
      '#type' => 'astro_island',
      '#uuid' => $componentUuid,
      // @todo Rename plugin ID in https://www.drupal.org/project/experience_builder/issues/3502982
      '#component' => $this->configuration['plugin_id'],
      '#props' => ($inputs[self::EXPLICIT_INPUT_NAME] ?? []) + [
        'xb_uuid' => $componentUuid,
        'xb_slot_ids' => \array_keys($this->getSlotDefinitions()),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    $build['#slots'] = $slots;
  }

  /**
   * Returns the source label for this component.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The source label.
   */
  protected function getSourceLabel(): TranslatableMarkup {
    return $this->t('Code component');
  }

  /**
   * Creates the Component config entity for a "code component" config entity.
   *
   * @param \Drupal\experience_builder\Entity\JavaScriptComponent $js_component
   *   An XB "code component" config entity.
   *
   * @return \Drupal\experience_builder\Entity\ComponentInterface
   *   The component config entity.
   *
   * @throws \Drupal\experience_builder\ComponentDoesNotMeetRequirementsException
   *    When the component does not meet requirements.
   */
  public static function createConfigEntity(JavaScriptComponent $js_component): ComponentInterface {
    try {
      $ephemeral_sdc_component = self::buildEphemeralSdcPluginInstance($js_component);
    }
    catch (InvalidComponentException $e) {
      throw new ComponentDoesNotMeetRequirementsException($e->getMessage());
    }
    ComponentMetadataRequirementsChecker::check((string) $js_component->id(), $ephemeral_sdc_component->metadata, $js_component->getRequiredProps());
    $props = self::getPropsForComponentPlugin($ephemeral_sdc_component);
    return ComponentEntity::create([
      'id' => self::SOURCE_PLUGIN_ID . '.' . $js_component->id(),
      'label' => $js_component->label(),
      'category' => '@todo',
      'source' => self::SOURCE_PLUGIN_ID,
      'settings' => [
        // @todo rename plugin_id in https://www.drupal.org/project/experience_builder/issues/3502982
        'plugin_id' => $js_component->id(),
        'prop_field_definitions' => $props,
      ],
      'status' => $js_component->status(),
    ]);
  }

  /**
   * Updates the Component config entity for a "code component" config entity.
   *
   * @param \Drupal\experience_builder\Entity\JavaScriptComponent $js_component
   *   An XB "code component" config entity.
   *
   * @return \Drupal\experience_builder\Entity\ComponentInterface
   *   The component config entity.
   *
   * @throws \Drupal\experience_builder\ComponentDoesNotMeetRequirementsException
   *    When the component does not meet requirements.
   */
  public static function updateConfigEntity(JavaScriptComponent $js_component, ComponentInterface $component): ComponentInterface {
    $settings = $component->getSettings();
    try {
      $ephemeral_sdc_component = self::buildEphemeralSdcPluginInstance($js_component);
    }
    catch (InvalidComponentException $e) {
      throw new ComponentDoesNotMeetRequirementsException($e->getMessage());
    }
    ComponentMetadataRequirementsChecker::check((string) $js_component->id(), $ephemeral_sdc_component->metadata, $js_component->getRequiredProps());
    $settings['prop_field_definitions'] = self::getPropsForComponentPlugin($ephemeral_sdc_component);
    $component->setSettings($settings);
    return $component;
  }

  /**
   * Generate a component ID given a Javascript Component ID.
   *
   * @param string $javaScriptComponentId
   *   Component ID.
   *
   * @return string
   *   Generated component ID.
   */
  public static function componentIdFromJavascriptComponentId(string $javaScriptComponentId): string {
    return \sprintf('%s.%s', self::SOURCE_PLUGIN_ID, $javaScriptComponentId);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    $js_component = $this->getJavaScriptComponent();
    try {
      $ephemeral_sdc_component = self::buildEphemeralSdcPluginInstance($js_component);
    }
    catch (InvalidComponentException $e) {
      throw new ComponentDoesNotMeetRequirementsException($e->getMessage());
    }
    ComponentMetadataRequirementsChecker::check((string) $js_component->id(), $ephemeral_sdc_component->metadata, $js_component->getRequiredProps());
  }

  /**
   * Any valid JavaScript Component config entity can be mapped to SDC metadata.
   *
   * @see \Drupal\experience_builder\Plugin\Validation\Constraint\JsComponentHasValidSdcMetadataConstraintValidator::validate
   */
  private static function buildEphemeralSdcPluginInstance(JavaScriptComponent $component): SdcPlugin {
    $definition = $component->toSdcDefinition();
    return new SdcPlugin(
      [
        'app_root' => '',
        'enforce_schemas' => TRUE,
      ],
      $definition['id'],
      $definition,
    );
  }

}
