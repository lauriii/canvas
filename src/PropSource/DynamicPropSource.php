<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\canvas\Plugin\Adapter\AdapterInterface;
use Drupal\canvas\Plugin\AdapterManager;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Component\Plugin\Definition\PluginDefinitionInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\canvas\MissingHostEntityException;
use Drupal\canvas\PropExpressions\StructuredData\Evaluator;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Describes structured data to map to 1 explicit input of a component instance.
 *
 * Conceptual sibling of HostEntityUrlPropSource, but:
 * - HostEntityUrlPropSource generates a URL to the host entity
 * - this retrieves information from structured data in the host entity (aka a
 *   field on the host entity)
 *
 * @see \Drupal\canvas\PropSource\HostEntityUrlPropSource
 * @see \Drupal\canvas\ShapeMatcher\JsonSchemaFieldInstanceMatcher
 *
 * @phpstan-import-type PropSourceArray from PropSourceBase
 * @internal
 */
final class DynamicPropSource extends PropSourceBase {

  /**
   * @param \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface $expression
   * @param \Drupal\canvas\Plugin\Adapter\AdapterInterface|null $adapter
   *   Optionally, a single adapter plugin instance can be specified, with a
   *   single input.
   */
  public function __construct(
    public readonly EntityFieldBasedPropExpressionInterface $expression,
    private readonly ?AdapterInterface $adapter = NULL,
  ) {
    // If the (optional) adapter plugin instance is provided, perform extra
    // validation: only *some* adapter plugins are acceptable.
    if ($adapter instanceof AdapterInterface) {
      if (count($adapter->getInputs()) > 1) {
        throw new \LogicException('Only adapter plugins with a single input are accepted.');
      }
    }
  }

  public function withAdapter(string $adapter_plugin_id): static {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $adapter_manager = \Drupal::service(AdapterManager::class);
    \assert($adapter_manager instanceof AdapterManager);
    $adapter_instance = $adapter_manager->createInstance($adapter_plugin_id);
    \assert($adapter_instance instanceof AdapterInterface);
    return new static(
      expression: $this->expression,
      adapter: $adapter_instance,
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return PropSourceArray
   */
  public function toArray(): array {
    $array_representation = [
      'sourceType' => $this->getSourceType(),
      'expression' => (string) $this->expression,
    ];
    if ($this->adapter) {
      $array_representation['adapter'] = $this->adapter->getPluginId();
    }
    return $array_representation;
  }

  /**
   * {@inheritdoc}
   */
  public static function parse(array $sdc_prop_source): static {
    // `sourceType = dynamic` requires an expression to be specified.
    $missing = array_diff(['expression'], array_keys($sdc_prop_source));
    if (!empty($missing)) {
      throw new \LogicException(\sprintf('Missing the keys %s.', implode(',', $missing)));
    }
    \assert(array_key_exists('expression', $sdc_prop_source));

    // @phpstan-ignore-next-line argument.type
    $instance = new DynamicPropSource(StructuredDataPropExpression::fromString($sdc_prop_source['expression']));

    // Optionally, a single adapter plugin ID can be specified.
    $has_adapter = array_key_exists('adapter', $sdc_prop_source);
    if (!$has_adapter) {
      return $instance;
    }
    \assert(\is_string($sdc_prop_source['adapter']));
    return $instance->withAdapter($sdc_prop_source['adapter']);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(?FieldableEntityInterface $host_entity, bool $is_required): EvaluationResult {
    if ($host_entity === NULL) {
      throw new MissingHostEntityException();
    }
    $raw_result = Evaluator::evaluate($host_entity, $this->expression, $is_required);

    // Only adapt non-empty results.
    if ($this->adapter && $raw_result->value !== NULL) {
      // Only adapter plugins with a single input are accepted, which is how
      // this is able to remain much simpler than AdaptedPropSource
      // @see ::__construct()
      // @see \Drupal\canvas\PropSource\AdaptedPropSource
      $sole_input_name = array_keys($this->adapter->getInputs())[0];
      $this->adapter->addInput($sole_input_name, $raw_result->value);
      $adapted_result = new EvaluationResult($this->adapter->adapt(), $raw_result);
      return $adapted_result;
    }

    return $raw_result;
  }

  public function asChoice(): string {
    return (string) $this->expression;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    \assert($host_entity === NULL || $host_entity instanceof FieldableEntityInterface);
    // The only dependencies are those of the used expression. If a host entity
    // is given, then `content` dependencies may appear as well; otherwise the
    // calculated dependencies will be limited to the entity types, bundle (if
    // any) and fields (if any) that this expression depends on.
    // @see \Drupal\Tests\canvas\Kernel\PropExpressionDependenciesTest
    $deps = $this->expression->calculateDependencies($host_entity);

    if ($this->adapter) {
      $plugin_definition = $this->adapter->getPluginDefinition();
      $deps['module'][] = match (TRUE) {
        $plugin_definition instanceof PluginDefinitionInterface => $plugin_definition->getProvider(),
        is_array($plugin_definition) => $plugin_definition['provider'],
        default => NULL,
      };
    }

    return $deps;
  }

  public function label(): TranslatableMarkup|string {
    $field_name = $this->expression->getFieldName();
    $entity_data_definition = $this->expression->getHostEntityDataDefinition();
    $field_definitions = $entity_data_definition->getPropertyDefinitions();
    \assert(\array_key_exists($field_name, $field_definitions));
    // @phpstan-ignore-next-line return.type
    return $field_definitions[$field_name]->getLabel();
  }

}
