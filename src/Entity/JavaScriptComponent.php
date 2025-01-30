<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Site\Settings;
use Drupal\experience_builder\ClientSideRepresentation;

/**
 * @ConfigEntityType(
 *   id = "js_component",
 *   label = @Translation("Code component"),
 *   label_singular = @Translation("code component"),
 *   label_plural = @Translation("code components"),
 *   label_collection = @Translation("Code components"),
 *   admin_permission = "administer code components",
 *   handlers = {
 *     "storage" = \Drupal\experience_builder\EntityHandlers\JavascriptComponentStorage::class,
 *   },
 *   entity_keys = {
 *     "id" = "machineName",
 *     "label" = "name",
 *     "status" = "status",
 *   },
 *   config_export = {
 *     "machineName",
 *     "name",
 *     "props",
 *     "required",
 *     "slots",
 *     "source_code_js",
 *     "source_code_css",
 *     "compiled_js",
 *     "compiled_css",
 *   },
 *   constraints = {
 *     "JsComponentHasValidSdcMetadata" = null,
 *   },
 * )
 */
final class JavaScriptComponent extends ConfigEntityBase implements XbHttpApiEligibleConfigEntityInterface {

  /**
   * The component machine name.
   */
  protected string $machineName;

  /**
   * The human-readable label of the component.
   */
  protected ?string $name;

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
   * The JS source code of the component.
   */
  protected ?string $source_code_js;

  /**
   * The CSS source code of the component.
   */
  protected ?string $source_code_css;

  /**
   * The compiled JavaScript that runs the component.
   */
  protected ?string $compiled_js;

  /**
   * The compiled CSS that styles the component.
   */
  protected ?string $compiled_css;

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
    return ClientSideRepresentation::create(
      values: [
        'machineName' => $this->id(),
        'name' => (string) $this->label(),
        'status' => $this->status(),
        'props' => $this->props,
        'required' => $this->required,
        'slots' => $this->slots,
        'source_code_js' => $this->source_code_js,
        'source_code_css' => $this->source_code_css,
        'compiled_js' => $this->compiled_js,
        'compiled_css' => $this->compiled_css,
      ],
      preview: [
        '#markup' => '@todo Make something 🆒 in https://www.drupal.org/project/experience_builder/issues/3498889',
      ],
    )->addCacheableDependency($this);
  }

  /**
   * This corresponds to `CodeComponent` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   *
   * @return array{'machineName': string, 'name': string, 'status': boolean, 'required': array<int, string>, 'props': array, 'slots': array, 'source_code_js': string, 'source_code_css': string, 'compiled_js': string, 'compiled_css': string}
   */
  public static function denormalizeFromClientSide(array $data): array {
    return [
      'machineName' => $data['machineName'],
      'name' => $data['name'],
      'status' => $data['status'],
      // Only the (machine) name and status should be required, because creating
      // a code component in the UI starts out with only knowing that.
      'required' => $data['required'] ?? [],
      'props' => $data['props'] ?? [],
      'slots' => $data['slots'] ?? [],
      'source_code_js' => $data['source_code_js'] ?? '',
      'source_code_css' => $data['source_code_css'] ?? '',
      'compiled_js' => $data['compiled_js'] ?? '',
      'compiled_css' => $data['compiled_css'] ?? '',
    ];
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
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\JsComponentHasValidSdcMetadataConstraintValidator
    if ($this->slots) {
      $definition['slots'] = $this->slots;
    }
    // Required properties are optional. Setting the `props.required` key to an
    // empty array is invalid.
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\JsComponentHasValidSdcMetadataConstraintValidator
    if ($this->required) {
      $definition['props']['required'] = $this->required;
    }
    return $definition;
  }

  public function getCompiledJs(): string {
    return $this->compiled_js ?? '';
  }

  public function getCompiledCss(): string {
    return $this->compiled_css ?? '';
  }

  public function hasCss(): bool {
    return trim($this->getCompiledCss()) !== '';
  }

  public function getJsPath(): string {
    $hash = Crypt::hmacBase64($this->getCompiledJs(), Settings::getHashSalt());
    return 'assets://astro-island/' . $hash . '.js';
  }

  public function getCssPath(): string {
    $hash = Crypt::hmacBase64($this->getCompiledCss(), Settings::getHashSalt());
    return 'assets://astro-island/' . $hash . '.css';
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

}
