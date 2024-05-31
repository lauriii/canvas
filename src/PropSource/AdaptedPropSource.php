<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropSource;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\Plugin\AdapterManager;
use Drupal\experience_builder\Plugin\Adapter\AdapterInterface;

final class AdaptedPropSource extends PropSource {

  private FieldableEntityInterface $hostEntity;

  /**
   * @param \Drupal\experience_builder\Plugin\Adapter\AdapterInterface $adapter_instance
   * @param array<string, mixed> $adapter_inputs
   */
  public function __construct(
    private readonly AdapterInterface $adapter_instance,
    private readonly array $adapter_inputs,
  ) {}

  /**
   * @param array{sourceType: string, expression: string, value?: array<string, mixed>, adapterInputs?: array<string, mixed>} $sdc_prop_source
   */
  public static function parse(array $sdc_prop_source): static {
    $adapter_manager = \Drupal::service(AdapterManager::class);
    assert($adapter_manager instanceof AdapterManager);
    $adapter_instance = $adapter_manager->createInstance(explode(':', $sdc_prop_source['sourceType'])[1]);
    assert($adapter_instance instanceof AdapterInterface);

    // `sourceType = adapter:*` requires adapterInputs to be specified.
    $missing = array_diff(['adapterInputs'], array_keys($sdc_prop_source));
    if (!empty($missing)) {
      throw new \LogicException(sprintf('Missing the keys %s.', implode(',', $missing)));
    }
    assert(array_key_exists('adapterInputs', $sdc_prop_source));

    return new AdaptedPropSource($adapter_instance, $sdc_prop_source['adapterInputs']);
  }

  public function withHostEntity(FieldableEntityInterface $host_entity): self {
    $this->hostEntity = $host_entity;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(): mixed {
    foreach ($this->adapter_inputs as $input_name => $input) {
      $value_object = match(TRUE) {
        $input['sourceType'] === 'dynamic' => DynamicPropSource::parse($input),
        // @todo: Support for nested adapted inputs?
        // str_starts_with($input['sourceType'], 'adapter:') => AdaptedPropSource::parse($input),
        str_starts_with($input['sourceType'], 'static:') => StaticPropSource::parse($input),
        default => throw new \OutOfRangeException(),
      };
      if ($value_object instanceof DynamicPropSource) {
        if (!isset($this->hostEntity)) {
          throw new \LogicException('Can only evaluate a dynamic prop source after calling withHostEntity().');
        }
        $value_object->withHostEntity($this->hostEntity);
        $value = $value_object->withHostEntity($this->hostEntity)->evaluate();
      }
      else {
        $value = $value_object->evaluate();
      }

      $this->adapter_instance->addInput($input_name, $value);
    }

    return $this->adapter_instance->adapt();
  }

}
