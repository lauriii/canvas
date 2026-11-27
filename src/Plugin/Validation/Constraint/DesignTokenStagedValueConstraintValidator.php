<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Controller\ErrorCodesEnum;
use Drupal\canvas\DesignToken\BrandKitTokens;
use Drupal\canvas\Plugin\ConfigAction\DesignTokenValue;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class DesignTokenStagedValueConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly BrandKitTokens $tokens,
  ) {}

  public static function create(ContainerInterface $container): self {
    $tokens = $container->get(BrandKitTokens::class);
    \assert($tokens instanceof BrandKitTokens);
    return new self($tokens);
  }

  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof DesignTokenStagedValueConstraint);
    if ($value instanceof TypedDataInterface) {
      $value = $value->getValue();
    }
    if ($value instanceof ConfigEntityInterface) {
      $value = $value->toArray();
    }
    if (!\is_array($value)) {
      return;
    }
    foreach ($value['actions'] ?? [] as $delta => $action) {
      if (($action['name'] ?? NULL) !== BrandKitTokens::CONFIG_ACTION) {
        continue;
      }
      $at = "actions.$delta.input";
      if (($value['target'] ?? NULL) !== DesignTokenValue::TARGET) {
        $this->context->buildViolation($constraint->targetMessage, ['@target' => DesignTokenValue::TARGET])->atPath('target')->addViolation();
        return;
      }
      $input = $action['input'] ?? NULL;
      // Only `:root` values: any other key (`scopes`, …) is refused.
      if (!\is_array($input) || \array_diff(\array_keys($input), ['path', 'type', 'value', 'base']) !== [] || !\array_key_exists('base', $input) || !\array_key_exists('value', $input) || !\is_string($input['path'] ?? NULL)) {
        $this->context->buildViolation($constraint->shapeMessage)->atPath($at)->addViolation();
        continue;
      }
      $path = $input['path'];
      if (($value['id'] ?? NULL) !== BrandKitTokens::stagedUpdateId($path)) {
        $this->context->buildViolation($constraint->idMessage, ['@id' => BrandKitTokens::stagedUpdateId($path)])->atPath('id')->addViolation();
      }
      if (!$this->tokens->isEnabled()) {
        $this->context->buildViolation($constraint->disabledMessage)->atPath($at)->addViolation();
        continue;
      }
      $type = $this->tokens->listedType($path);
      if ($type === NULL || !BrandKitTokens::isStorablePath($path)) {
        $this->context->buildViolation($constraint->notListedMessage)->atPath("$at.path")->addViolation();
        continue;
      }
      // A theme update can change a token's type between staging and
      // publishing.
      if (($input['type'] ?? NULL) !== $type) {
        $this->context->buildViolation($constraint->typeMessage, ['@type' => $type])->atPath("$at.type")->addViolation();
        continue;
      }
      if ($input['value'] !== NULL) {
        [, $errors] = $this->tokens->validateRootValue($path, $type, $input['value']);
        foreach ($errors as $sub => $message) {
          $this->context->buildViolation($message)->atPath($sub === '' ? "$at.value" : "$at.value.$sub")->addViolation();
        }
      }
      // A CLI push, drush, or a config import changed the published value
      // after this draft was created: never silently overwrite it.
      if (!BrandKitTokens::sameValue($type, $this->tokens->publishedValue($path), $input['base'])) {
        $this->context->buildViolation($constraint->changedElsewhereMessage)
          ->atPath("$at.base")
          ->setCode((string) ErrorCodesEnum::DesignTokenChangedElsewhere->value)
          ->addViolation();
      }
    }
  }

}
