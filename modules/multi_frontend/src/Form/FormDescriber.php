<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Form;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\ElementInfoManagerInterface;

/**
 * Describes a Drupal form as data a client can render natively.
 *
 * A form is not a component, and this is deliberately not the component
 * contract wearing a different hat. A component is a thing that renders; a
 * form is a resource with a submit endpoint, so it gets a JSON Schema for its
 * values, a separate map of presentation hints, and an endpoint that
 * validates. That is the shape JSON:API's lineage uses for entities, and it
 * is the right one here.
 *
 * The honest part: not every element can be described. Rather than dropping
 * what it cannot express and leaving a client to render a form that silently
 * cannot work, the description reports those elements by name. A client can
 * then render the form natively, or decide this form is not one it should be
 * rendering at all.
 */
final class FormDescriber {

  /**
   * Element types this can describe, mapped to their JSON Schema shape.
   */
  private const SCALAR_TYPES = [
    'textfield' => ['type' => 'string'],
    'textarea' => ['type' => 'string'],
    'password' => ['type' => 'string', 'writeOnly' => TRUE],
    'email' => ['type' => 'string', 'format' => 'email'],
    'tel' => ['type' => 'string'],
    'url' => ['type' => 'string', 'format' => 'uri'],
    'search' => ['type' => 'string'],
    'number' => ['type' => 'number'],
    'range' => ['type' => 'number'],
    'checkbox' => ['type' => 'boolean'],
    'date' => ['type' => 'string', 'format' => 'date'],
    'datetime-local' => ['type' => 'string', 'format' => 'date-time'],
  ];

  /**
   * Element types that carry a fixed set of options.
   */
  private const CHOICE_TYPES = ['select', 'radios', 'checkboxes'];

  /**
   * Structural elements that are not values and are not gaps either.
   *
   * Submission machinery and pure layout. A client neither renders nor sends
   * these, and their absence is not something to report as unsupported.
   */
  private const IGNORED_TYPES = [
    'token', 'form_token', 'hidden_form_id', 'actions', 'submit', 'button',
    'container', 'details', 'fieldset', 'html_tag', 'markup', 'processed_text',
    'item', 'vertical_tabs', 'link',
    // A client sends values, not a rendered form, and programmatic submission
    // rebuilds the form server-side, so hidden inputs are machinery too.
    'hidden', 'value',
  ];

  /**
   * Element names that are Drupal's submission machinery, never fields.
   *
   * These would otherwise reach the contract as if they were something a
   * client had to fill in, which is exactly the kind of Drupalism the whole
   * exercise exists to keep out. Programmatic submission ignores them.
   */
  private const MACHINERY_NAMES = ['form_build_id', 'form_id', 'form_token'];

  public function __construct(
    private readonly FormBuilderInterface $formBuilder,
    private readonly ElementInfoManagerInterface $elementInfo,
  ) {}

  /**
   * Describes a form.
   *
   * @param class-string|string $form_arg
   *   The form class or service id, as FormBuilder accepts it.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Collects the built form's cacheability, so a caller can attach it to a
   *   response. A form's elements can come from configuration, so a
   *   description is not cacheable forever on code changes alone.
   *
   * @return array{id: string, schema: array<string, mixed>, ui: array<string, mixed>, unsupported: string[]}
   *   The description.
   */
  public function describe(string $form_arg, ?CacheableMetadata $cacheability = NULL): array {
    $form = $this->formBuilder->getForm($form_arg);
    $cacheability?->addCacheableDependency(CacheableMetadata::createFromRenderArray($form));

    $properties = [];
    $required = [];
    $ui = [];
    $unsupported = [];

    $this->walk($form, $properties, $required, $ui, $unsupported, $cacheability);

    \ksort($properties);
    \sort($required);
    \sort($unsupported);

    return [
      'id' => (string) ($form['#form_id'] ?? $form_arg),
      'schema' => [
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'type' => 'object',
        'properties' => $properties === [] ? new \stdClass() : $properties,
        'required' => $required,
        'additionalProperties' => FALSE,
      ],
      'ui' => $ui,
      'unsupported' => $unsupported,
    ];
  }

  /**
   * Walks a built form, collecting values, hints, and what it cannot express.
   */
  private function walk(array $element, array &$properties, array &$required, array &$ui, array &$unsupported, ?CacheableMetadata $cacheability): void {
    foreach (Element::children($element) as $key) {
      $child = $element[$key];
      if (!\is_array($child)) {
        continue;
      }
      // An element the account cannot access is not part of its contract.
      // Publishing it would advertise a field whose value the submit endpoint
      // is now required to ignore.
      if (!self::isAccessible($child, $cacheability)) {
        continue;
      }
      $type = $child['#type'] ?? NULL;
      if (isset($child['#cache'])) {
        $cacheability?->addCacheableDependency(CacheableMetadata::createFromRenderArray($child));
      }

      $structural = $type === NULL || \in_array($type, self::IGNORED_TYPES, TRUE);

      // A structural container marked #tree nests its children's values, and
      // this schema is flat, so publishing them would describe a payload the
      // form will not read. Report the subtree and do not descend into it.
      //
      // Only structural containers, though. Composite elements set #tree on
      // themselves as an implementation detail of how they collect their own
      // parts -- Checkboxes.php:62 and ManagedFile.php:224 both do -- and
      // treating that as author-declared nesting would wrongly exclude every
      // checkbox group and file field from the contract.
      if ($structural && !empty($child['#tree'])) {
        $unsupported[] = \sprintf('%s (nested #tree values)', (string) $key);
        continue;
      }

      if ($structural) {
        // Layout or machinery. Recurse in case it wraps real fields.
        $this->walk($child, $properties, $required, $ui, $unsupported, $cacheability);
        continue;
      }

      $name = (string) $key;
      if (\in_array($name, self::MACHINERY_NAMES, TRUE)) {
        continue;
      }
      $schema = $this->schemaFor($child, $type);
      if ($schema === NULL) {
        // Named, not silently dropped. A client can see what it is missing.
        $unsupported[] = \sprintf('%s (%s)', $name, $type);
        continue;
      }

      // Two elements at different depths can share a name. Values are flat,
      // so the second would overwrite the first and the published schema
      // would quietly describe only one of them. Report instead.
      if (isset($properties[$name])) {
        $unsupported[] = \sprintf('%s (duplicate element name)', $name);
        continue;
      }

      $properties[$name] = $schema;
      if (!empty($child['#required'])) {
        $required[] = $name;
      }
      $ui[$name] = \array_filter([
        'widget' => $type,
        'label' => isset($child['#title']) ? (string) $child['#title'] : NULL,
        'description' => isset($child['#description']) ? (string) $child['#description'] : NULL,
        'placeholder' => $child['#placeholder'] ?? NULL,
        'weight' => $child['#weight'] ?? NULL,
        'multiple' => $type === 'checkboxes' ? TRUE : NULL,
      ], static fn ($v) => $v !== NULL && $v !== '');
    }
  }

  /**
   * The JSON Schema for one element, or NULL when it cannot be described.
   */
  private function schemaFor(array $element, string $type): ?array {
    // Ask the element plugin before consulting the built-in map, so that an
    // element core has never heard of can still describe itself, and so that
    // an element can override how it is described.
    $self_described = $this->askElement($element, $type);
    if ($self_described !== NULL) {
      return $self_described;
    }

    if (\array_key_exists($type, self::SCALAR_TYPES)) {
      $schema = self::SCALAR_TYPES[$type];
      if (isset($element['#maxlength'])) {
        $schema['maxLength'] = (int) $element['#maxlength'];
      }
      if (isset($element['#default_value']) && \is_scalar($element['#default_value'])) {
        $schema['default'] = $element['#default_value'];
      }
      return $schema;
    }

    if (\in_array($type, self::CHOICE_TYPES, TRUE)) {
      $options = $element['#options'] ?? [];
      if ($options === []) {
        return NULL;
      }
      $values = \array_map(static fn ($k): string => (string) $k, \array_keys($options));
      $labels = \array_map(static fn ($v): string => \is_array($v) ? '' : (string) $v, \array_values($options));
      $one = ['type' => 'string', 'enum' => $values, 'meta:enum' => \array_combine($values, $labels)];
      $schema = ($type === 'checkboxes' || !empty($element['#multiple']))
        ? ['type' => 'array', 'items' => $one]
        : $one;

      // A single-value choice publishes its default the way a scalar does,
      // but only when the default is genuinely one of the options: a form can
      // carry a stale or placeholder default, and publishing one that is not
      // in the enum would make the schema contradict itself.
      $default = $element['#default_value'] ?? NULL;
      if ($schema['type'] === 'string' && \is_scalar($default) && \in_array((string) $default, $values, TRUE)) {
        $schema['default'] = (string) $default;
      }

      return $schema;
    }

    // Everything else: file uploads, entity autocompletes, date ranges,
    // managed files, anything a contrib module invented that has not taught
    // itself to answer. Reported, not guessed at.
    return NULL;
  }

  /**
   * Whether the element is accessible, with core's exact semantics.
   *
   * Deliberately mirrors FormBuilder::isElementAccessible() rather than
   * approximating it: #access may be an access result object carrying its own
   * cacheability, and anything that is not boolean TRUE denies. Getting this
   * subtly wrong is how a field the submit endpoint will ignore ends up
   * published as though a client could set it.
   *
   * Not handled: #access_callback with no #access, which core resolves during
   * rendering. Such an element is treated as accessible here.
   *
   * @see \Drupal\Core\Form\FormBuilder::isElementAccessible()
   */
  private static function isAccessible(array $element, ?CacheableMetadata $cacheability): bool {
    if (!isset($element['#access'])) {
      return TRUE;
    }
    if ($element['#access'] instanceof AccessResultInterface) {
      // The decision is part of why the description looks the way it does, so
      // its cacheability belongs on the response.
      $cacheability?->addCacheableDependency($element['#access']);
      return $element['#access']->isAllowed();
    }
    return $element['#access'] === TRUE;
  }

  /**
   * Lets the element plugin describe itself, if it knows how.
   */
  private function askElement(array $element, string $type): ?array {
    $definition = $this->elementInfo->getDefinition($type, FALSE);
    $class = $definition['class'] ?? NULL;
    // A #type with no element plugin behind it, or one that has not taught
    // itself to answer. The built-in map may still know it; if it does not, it
    // is reported as unsupported.
    return \is_string($class) && \is_a($class, JsonSchemaFormElementInterface::class, TRUE)
      ? $class::toJsonSchema($element)
      : NULL;
  }

}
