<?php

declare(strict_types = 1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Plugin\Validation\Constraint\ConfigExistsConstraint;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\Schema\Sequence;
use Drupal\Core\Config\Schema\TypeResolver;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\Validation\Plugin\Validation\Constraint\ValidKeysConstraint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the SequenceKeysMustMatch constraint.
 */
final class SequenceKeysMustMatchConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a SequenceKeysMustMatchConstraintValidator object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TypedConfigManagerInterface $typedConfigManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ConfigFactoryInterface::class),
      $container->get(TypedConfigManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!is_array($value)) {
      throw new UnexpectedTypeException($value, 'sequence');
    }
    if (!$constraint instanceof SequenceKeysMustMatchConstraint) {
      throw new UnexpectedTypeException($constraint, SequenceKeysMustMatchConstraint::class);
    }

    assert(!is_null($constraint->configName));
    assert($this->context->getObject() instanceof TypedDataInterface);
    $resolved_config_name = TypeResolver::resolveExpression(
      $constraint->configName,
      $this->context->getObject(),
    );
    // This implies the existence of that other config, so reuse the message
    // from the ConfigExistsConstraint when that is not the case.
    if (!in_array($constraint->configPrefix . $resolved_config_name, $this->configFactory->listAll($constraint->configPrefix), TRUE)) {
      $config_exists_constraint = new ConfigExistsConstraint();
      $this->context->addViolation($config_exists_constraint->message, ['@name' => $constraint->configPrefix . $resolved_config_name]);
    }

    // Now get the target sequence's keys.
    $target_config_object = $this->typedConfigManager->get($constraint->configPrefix . $resolved_config_name);
    assert($target_config_object instanceof Mapping);
    \assert($constraint->propertyPathToSequence !== NULL);
    $target_sequence = $target_config_object->get($constraint->propertyPathToSequence);
    // Verify the target property is indeed a sequence; if not, that's a logical
    // error in the config schema, not in concrete config.
    if (!$target_sequence instanceof Sequence) {
      throw new \LogicException(sprintf(
        "The `%s` config object's `%s` property path was expected to point to a `%s` instance, but got a `%s` instead.",
        $constraint->configPrefix . $resolved_config_name,
        $constraint->propertyPathToSequence,
        Sequence::class,
        $target_sequence::class,
      ));
    }
    $expected_sequence_keys = array_keys($target_sequence->getElements());

    $missing_keys = array_diff($expected_sequence_keys, array_keys($value));
    $invalid_keys = array_diff(array_keys($value), $expected_sequence_keys);
    if (empty($missing_keys) && empty($invalid_keys)) {
      return;
    }

    // Reuse the messages from the ValidKeysConstraint when missing or invalid
    // keys are found.
    $valid_keys_constraint = new ValidKeysConstraint('<infer>');
    foreach ($missing_keys as $key) {
      $this->context->addViolation($valid_keys_constraint->missingRequiredKeyMessage, ['@key' => $key]);
    }
    foreach ($invalid_keys as $key) {
      $this->context->buildViolation($valid_keys_constraint->invalidKeyMessage)
        ->setParameter('@key', $key)
        ->atPath((string) $key)
        ->setInvalidValue($key)
        ->addViolation();
    }
  }

}
