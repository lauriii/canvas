<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\Component as SdcPlugin;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\experience_builder\Attribute\ComponentSource;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\ComponentDoesNotMeetRequirementsException;
use Drupal\experience_builder\ComponentMetadataRequirementsChecker;
use Drupal\experience_builder\ComponentSource\UrlRewriteInterface;
use Drupal\experience_builder\Entity\Component as ComponentEntity;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a component source based on XB JavaScript Component config entities.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Code Components'),
  supportsImplicitInputs: FALSE,
)]
final class JsComponent extends GeneratedFieldExplicitInputUxComponentSourceBase implements UrlRewriteInterface {

  public const SOURCE_PLUGIN_ID = 'js';

  protected ExtensionPathResolver $extensionPathResolver;
  protected AutoSaveManager $autoSaveManager;
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->extensionPathResolver = $container->get(ExtensionPathResolver::class);
    $instance->autoSaveManager = $container->get(AutoSaveManager::class);
    $instance->fileUrlGenerator = $container->get(FileUrlGeneratorInterface::class);
    return $instance;
  }

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
  public function renderComponent(array $inputs, string $componentUuid, bool $isPreview = FALSE): array {
    $component = $this->getJavaScriptComponent();
    if ($isPreview) {
      $autoSave = $this->autoSaveManager->getAutoSaveData($component);
      if ($autoSave->isEmpty()) {
        // Do NOT consider this a preview if there's no auto-saved draft
        // version.
        $isPreview = FALSE;
      }
      else {
        \assert($autoSave->data !== NULL);
        $component = $component->forAutoSavePreview($autoSave->data);
      }
    }

    $component_url = $this->fileUrlGenerator->generateString($component->getJsPath());

    if ($isPreview) {
      $component_url = Url::fromRoute('experience_builder.api.config.auto-save.get.js', [
        'xb_config_entity_type_id' => JavaScriptComponent::ENTITY_TYPE_ID,
        'xb_config_entity' => $component->id(),
      ])->toString();
    }

    $build = [];
    $base_path = \base_path();
    $valid_props = $component->getProps() ?? [];
    if ($component->hasCss()) {
      $css_library = 'experience_builder/astro_island.' . $component->id();
      if ($isPreview) {
        $css_library .= '.draft';
      }
      $build['#attached']['library'][] = $css_library;
    }
    $xb_path = $this->extensionPathResolver->getPath('module', 'experience_builder');
    // Resource hints.
    $resource_hints = [
      'preact/signals' => \sprintf('%s%s/ui/lib/astro-hydration/dist/signals.module.js', $base_path, $xb_path),
      '@/lib/preload-helper' => \sprintf('%s%s/ui/lib/astro-hydration/dist/preload-helper.js', $base_path, $xb_path),
    ];
    foreach ($resource_hints as $url) {
      $build['#attached']['html_head_link'][] = [
        [
          'rel' => 'modulepreload',
          'fetchpriority' => 'high',
          'href' => $url,
        ],
      ];
    }
    return $build + [
      '#type' => 'astro_island',
      '#uuid' => $componentUuid,
      '#import_maps' => [
        // @todo Add any additional imports from the component itself - see
        // https://www.drupal.org/project/experience_builder/issues/3500761 for
        // proof of concept.
        'preact' => \sprintf('%s%s/ui/lib/astro-hydration/dist/preact.module.js', $base_path, $xb_path),
        'preact/hooks' => \sprintf('%s%s/ui/lib/astro-hydration/dist/hooks.module.js', $base_path, $xb_path),
        'react/jsx-runtime' => \sprintf('%s%s/ui/lib/astro-hydration/dist/jsxRuntime.module.js', $base_path, $xb_path),
        'react' => \sprintf('%s%s/ui/lib/astro-hydration/dist/compat.module.js', $base_path, $xb_path),
        'react-dom' => \sprintf('%s%s/ui/lib/astro-hydration/dist/compat.module.js', $base_path, $xb_path),
        'react-dom/client' => \sprintf('%s%s/ui/lib/astro-hydration/dist/compat.module.js', $base_path, $xb_path),
        // @todo Remove this hard-coding and calculate it on a per component
        // basis - see https://drupal.org/i/3500761.
        'clsx' => \sprintf('%s%s/ui/lib/astro-hydration/dist/clsx.js', $base_path, $xb_path),
        'class-variance-authority' => \sprintf('%s%s/ui/lib/astro-hydration/dist/class-variance-authority.js', $base_path, $xb_path),
        'tailwind-merge' => \sprintf('%s%s/ui/lib/astro-hydration/dist/tailwind-merge.js', $base_path, $xb_path),
        '@/lib/utils' => \sprintf('%s%s/ui/lib/astro-hydration/dist/utils.js', $base_path, $xb_path),
      ],
      '#name' => $component->label(),
      '#component_url' => $component_url,
      '#props' => (\array_intersect_key($inputs[self::EXPLICIT_INPUT_NAME] ?? [], $valid_props)) + [
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
      throw new ComponentDoesNotMeetRequirementsException([$e->getMessage()]);
    }
    ComponentMetadataRequirementsChecker::check((string) $js_component->id(), $ephemeral_sdc_component->metadata, $js_component->getRequiredProps());
    $props = self::getPropsForComponentPlugin($ephemeral_sdc_component);
    return ComponentEntity::create([
      'id' => self::SOURCE_PLUGIN_ID . '.' . $js_component->id(),
      'label' => $js_component->label(),
      'category' => '@todo',
      'provider' => NULL,
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
    $label_key = $component->getEntityType()->getKey('label');
    assert(is_string($label_key));
    $component->set($label_key, $js_component->label());
    $component->setStatus($js_component->status());
    try {
      $ephemeral_sdc_component = self::buildEphemeralSdcPluginInstance($js_component);
    }
    catch (InvalidComponentException $e) {
      throw new ComponentDoesNotMeetRequirementsException([$e->getMessage()]);
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
      throw new ComponentDoesNotMeetRequirementsException([$e->getMessage()]);
    }
    ComponentMetadataRequirementsChecker::check((string) $js_component->id(), $ephemeral_sdc_component->metadata, $js_component->getRequiredProps());
  }

  /**
   * Any valid JavaScript Component config entity can be mapped to SDC metadata.
   *
   * @see \Drupal\experience_builder\Plugin\Validation\Constraint\JsComponentHasValidAndSupportedSdcMetadataConstraintValidator::validate
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

  /**
   * {@inheritdoc}
   */
  public function rewriteExampleUrl(string $url): string {
    $parsed_url = parse_url($url);
    \assert(\is_array($parsed_url));
    if (array_intersect_key($parsed_url, array_flip(['scheme', 'host']))) {
      return $url;
    }
    throw new \InvalidArgumentException('Default images for Javascript Components must be a fully-qualified URL with both scheme and host.');
  }

}
