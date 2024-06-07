<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropSource;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\Plugin\AdapterManager;
use Drupal\experience_builder\Plugin\Adapter\AdapterInterface;

final class AdaptedPropSource extends PropSource {

  /**
   * @param \Drupal\experience_builder\Plugin\Adapter\AdapterInterface $adapter_instance
   * @param array<string, mixed> $adapter_inputs
   */
  public function __construct(
    private readonly AdapterInterface $adapter_instance,
    private readonly array $adapter_inputs,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    // @phpstan-ignore-next-line
    return json_encode([
      'sourceType' => 'adapter:' . $this->adapter_instance->getPluginId(),
      'adapterInputs' => array_map(
        fn (PropSource $source): array => json_decode((string) $source, TRUE),
        array_map(
          fn (string $input_name): PropSource => $this->getInputPropSource($input_name),
          array_keys($this->adapter_inputs)
        )
      ),
    ], JSON_UNESCAPED_UNICODE);
  }

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

  /**
   * {@inheritdoc}
   */
  public function evaluate(FieldableEntityInterface $host_entity): mixed {
    foreach ($this->adapter_inputs as $input_name => $input) {
      $value_object = $this->getInputPropSource($input_name);
      $value = $value_object->evaluate($host_entity);
      $this->adapter_instance->addInput($input_name, $value);
    }

    return $this->adapter_instance->adapt();
  }

  public function asChoice(): string {
    return $this->adapter_instance->getPluginId();
  }

  public function getInputPropSource(string $input_name) : StaticPropSource|DynamicPropSource {
    $input = $this->adapter_inputs[$input_name];
    return match(TRUE) {
      $input['sourceType'] === 'dynamic' => DynamicPropSource::parse($input),
      // @todo Determine whether nested adapted inputs should be supported.
      // str_starts_with($input['sourceType'], 'adapter:') => AdaptedPropSource::parse($input),
      str_starts_with($input['sourceType'], 'static:') => StaticPropSource::parse($input),
      default => throw new \OutOfRangeException(),
    };
  }

}
