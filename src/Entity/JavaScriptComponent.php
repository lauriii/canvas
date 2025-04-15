<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\experience_builder\ClientSideRepresentation;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @ConfigEntityType(
 *   id = \Drupal\experience_builder\Entity\JavaScriptComponent::ENTITY_TYPE_ID,
 *   label = @Translation("Code component"),
 *   label_singular = @Translation("code component"),
 *   label_plural = @Translation("code components"),
 *   label_collection = @Translation("Code components"),
 *   admin_permission = \Drupal\experience_builder\Entity\JavaScriptComponent::ADMIN_PERMISSION,
 *   handlers = {
 *     "storage" = \Drupal\experience_builder\EntityHandlers\JavascriptComponentStorage::class,
 *     "access" = \Drupal\experience_builder\EntityHandlers\XbConfigEntityAccessControlHandler::class,
 *   },
 *   entity_keys = {
 *     "id" = "machineName",
 *     "label" = "name",
 *     "status" = "status",
 *   },
 *   config_export = {
 *     "machineName",
 *     "name",
 *     "block_override",
 *     "props",
 *     "required",
 *     "slots",
 *     "js",
 *     "css",
 *   },
 *   constraints = {
 *     "JsComponentHasValidAndSupportedSdcMetadata" = null,
 *   },
 *   xb_visible_when_disabled = TRUE
 * )
 */
final class JavaScriptComponent extends ConfigEntityBase implements XbAssetInterface {

  use XbAssetLibraryTrait;

  public const string ENTITY_TYPE_ID = 'js_component';
  public const string ADMIN_PERMISSION = 'administer code components';
  private const string ASSETS_DIRECTORY = 'assets://astro-island/';

  /**
   * The component machine name.
   */
  protected string $machineName;

  /**
   * The human-readable label of the component.
   */
  protected ?string $name;

  /**
   * NULL or a block base plugin ID.
   *
   * ⚠️ This is highly experimental and *will* be refactored.
   */
  protected ?string $block_override;

  /**
   * The props of the component.
   */
  protected ?array $props = [];

  /**
   * The required props of the component.
   *
   * @var string[]
   */
  protected ?array $required = [];

  /**
   * The slots of the component.
   */
  protected ?array $slots = [];

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->machineName;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `CodeComponent` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    // TRICKY: config entity properties may allow NULL, but only valid, saved
    // config entities are ever normalized: those that have passed validation
    // against config schema.
    assert(is_array($this->js));
    assert(is_array($this->css));
    return ClientSideRepresentation::create(
      values: [
        'machineName' => $this->id(),
        'name' => (string) $this->label(),
        'status' => $this->status(),
        'block_override' => $this->block_override,
        'props' => $this->props,
        'required' => $this->required,
        'slots' => $this->slots,
        'source_code_js' => $this->js['original'] ?? '',
        'source_code_css' => $this->css['original'] ?? '',
        'compiled_js' => $this->js['compiled'] ?? '',
        'compiled_css' => $this->css['compiled'] ?? '',
      ],
      preview: [
        '#markup' => '@todo Make something 🆒 in https://www.drupal.org/project/experience_builder/issues/3498889',
      ],
    )->addCacheableDependency($this);
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `CodeComponent` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public static function createFromClientSide(array $data): static {
    $entity = static::create(['machineName' => $data['machineName']]);
    $entity->updateFromClientSide($data);
    return $entity;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `CodeComponent` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function updateFromClientSide(array $data): void {
    foreach (array_intersect_key($data, array_flip(['machineName', 'name', 'status', 'required', 'props', 'slots'])) as $key => $value) {
      $this->set($key, $value);
    }

    if (array_key_exists('source_code_css', $data) || array_key_exists('compiled_css', $data)) {
      $this->set('css', [
        'original' => $data['source_code_css'] ?? '',
        'compiled' => $data['compiled_css'] ?? '',
      ]);
    }

    if (array_key_exists('source_code_js', $data) || array_key_exists('compiled_js', $data)) {
      if (!array_key_exists('imported_js_components', $data)) {
        $violation_list = new ConstraintViolationList();
        $violation_list->add(new ConstraintViolation(
          "The 'imported_js_components' field is required when 'source_code_js' or 'compiled_js' is provided",
          "The 'imported_js_components' field is required when 'source_code_js' or 'compiled_js' is provided",
          [],
          NULL,
          "imported_js_components",
          NULL
        ));
        throw new ConstraintViolationException($violation_list);
      }
      // The client calculates imported JavaScript components dependencies. This
      // value is never returned to the client as it will always recalculate it
      // based off source_code_js.
      $this->addJavaScriptComponentsDependencies($data['imported_js_components']);
      $this->set('js', [
        'original' => $data['source_code_js'] ?? '',
        'compiled' => $data['compiled_js'] ?? '',
      ]);
    }

    // ⚠️ This is highly experimental and *will* be refactored.
    if (array_key_exists('block_override', $data)) {
      $this->set('block_override', $data['block_override']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Nothing to do.
  }

  /**
   * Code components are not Twig-defined but still aim to match SDC closely.
   *
   * TRICKY: while `props` and `slots` are already individually validated
   * against the JSON schema, the overall structure must also be valid in a way
   * that the SDC's JSON schema does not actually validate: crucial parts are
   * validated only in PHP!
   *
   * @return array{machineName: string, extension_type: string, id: string, provider: string, name: string, props: array, slots?: array, library: array, path: string, template: string}}
   *
   * @see core/assets/schemas/v1/metadata-full.schema.json
   * @see \Drupal\Core\Theme\Component\ComponentValidator::validateDefinition()
   * @see \Drupal\Tests\Core\Theme\Component\ComponentValidatorTest::loadComponentDefinitionFromFs()
   */
  public function toSdcDefinition(): array {
    $definition = [
      'machineName' => (string) $this->id(),
      'extension_type' => 'module',
      'id' => 'experience_builder:' . $this->id(),
      'provider' => 'experience_builder',
      'name' => (string) $this->label(),
      'props' => [
        'type' => 'object',
        'properties' => $this->props ?? [],
      ],
      // No equivalents exist nor can be generated; specify hard-coded values
      // that allow this to be considered a valid SDC definition.
      'library' => [],
      'path' => '',
      // This needs to be non empty.
      'template' => 'phony',
    ];
    // Slots are optional. Setting the `slots` key to an empty array is invalid.
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\JsComponentHasValidAndSupportedSdcMetadataConstraintValidator
    if ($this->slots) {
      $definition['slots'] = $this->slots;
    }
    // Required properties are optional. Setting the `props.required` key to an
    // empty array is invalid.
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\JsComponentHasValidAndSupportedSdcMetadataConstraintValidator
    if ($this->required) {
      $definition['props']['required'] = $this->required;
    }
    return $definition;
  }

  /**
   * Sets value for props.
   *
   * @param array<string, array{type: string, format?: string, examples?: string|array<string>, title: string}> $props
   *   Value for Props.
   */
  public function setProps(array $props): self {
    $this->props = $props;
    return $this;
  }

  /**
   * Gets required props.
   *
   * @return array
   *   Required props.
   */
  public function getRequiredProps(): array {
    return $this->required ?? [];
  }

  /**
   * Gets component props.
   *
   * @return array|null
   *   Component props.
   */
  public function getProps(): ?array {
    if ($this->block_override) {
      return self::getExperimentalHardcodedBlockProps($this->block_override);
    }
    return $this->props;
  }

  /**
   * ⚠️ This is highly experimental and *will* be refactored.
   *
   * This is here to satisfy the call from AstroIsland::preRenderIsland().
   */
  private function getExperimentalHardcodedBlockProps(string $override): ?array {
    switch ($this->block_override) {
      case 'system_branding_block':
        return [
          'homeUrl' => ['type' => 'string', 'format' => 'uri-reference'],
          'logo' => ['type' => 'string', 'format' => 'uri-reference'],
          'siteName' => ['type' => 'string'],
        ];

      case 'system_breadcrumb_block':
        return [
          'links' => ['type' => 'array'],
        ];

      case 'system_menu_block':
        return [
          'id' => ['type' => 'string'],
          'links' => ['type' => 'array'],
          'label' => ['type' => 'string'],
        ];

      default:
        return $this->props;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    // The files generated in XbAssetStorage::doSave() have a content-dependent
    // hash in their name. This has 2 consequences:
    // 1. Cached responses that referred to an older version, continue to work.
    // 2. New responses must use the newly generated files, which requires the
    //    asset library to point to those new files. Hence the library info must
    //    be recalculated.
    // @see \experience_builder_library_info_build()
    Cache::invalidateTags(['library_info']);
  }

  /**
   * Add the imported Javascript components as enforced dependencies.
   *
   * Enforced dependencies are not reset during dependency calculation.
   *
   * @param array<string> $imported_js_components
   *   The names of the JavaScript components to add as dependencies.
   *
   * @throws \Drupal\experience_builder\Exception\ConstraintViolationException
   *   Thrown if any of the JavaScript components do not exist.
   *
   * @see \Drupal\Core\Config\Entity\ConfigEntityBase::calculateDependencies
   */
  protected function addJavaScriptComponentsDependencies(array $imported_js_components): void {
    $violation_list = new ConstraintViolationList();
    foreach ($imported_js_components as $key => $js_component_name) {
      $js_component = JavaScriptComponent::load($js_component_name);
      if (!$js_component) {
        $violation_list->add(new ConstraintViolation(
          "The JavaScript component with machine name '$js_component_name' does not exist.",
          "The JavaScript component with machine name '$js_component_name' does not exist.",
          [],
          NULL,
          "imported_js_components.$key",
          $js_component_name
        ));
      }
    }
    if ($violation_list->count() > 0) {
      throw new ConstraintViolationException($violation_list);
    }
    $imported_js_component_dependency_names = array_values(array_map(
      fn(string $component_name) => $this->getConfigPrefix() . ".$component_name",
      $imported_js_components
    ));
    $this->dependencies['enforced']['config'] ??= [];
    // Remove all the current JavaScript component enforced dependencies.
    $this->dependencies['enforced']['config'] = array_filter(
      $this->dependencies['enforced']['config'],
      fn(string $dependency) => !str_starts_with($dependency, $this->getConfigPrefix())
    );
    $this->dependencies['enforced']['config'] = array_unique(array_merge(
      $this->dependencies['enforced']['config'],
      $imported_js_component_dependency_names
    ));
    if (empty($this->dependencies['enforced']['config'])) {
      unset($this->dependencies['enforced']['config']);
    }
  }

  protected function getConfigPrefix(): string {
    $entity_type = $this->getEntityType();
    assert($entity_type instanceof ConfigEntityTypeInterface);
    return $entity_type->getConfigPrefix();
  }

}
