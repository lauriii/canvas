<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Plugin\Validation\Constraint;

use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\canvas\Plugin\Validation\Constraint\ValidSlotNameConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\ValidSlotNameConstraintValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

#[Group('canvas')]
#[CoversClass(ValidSlotNameConstraintValidator::class)]
class ValidSlotNameConstraintValidatorTest extends UnitTestCase {

  protected ValidSlotNameConstraintValidator $validator;

  protected ExecutionContextInterface&MockObject $context;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new ValidSlotNameConstraintValidator();
    $this->context = $this->createMock(ExecutionContextInterface::class);
    $this->validator->initialize($this->context);
  }

  /**
   * @covers ::validate
   * @dataProvider providerValidate
   */
  public function testValidate(mixed $slot_name, bool $is_invalid): void {
    $constraint = new ValidSlotNameConstraint();

    $violation_builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $this->context->expects($is_invalid ? $this->once() : $this->never())
      ->method('buildViolation')
      ->with($this->anything())
      ->willReturn($violation_builder);

    $this->validator->validate($slot_name, $constraint);
  }

  /**
   * @covers ::validate
   */
  public function testValidateWithNullValue(): void {
    $constraint = new ValidSlotNameConstraint();
    $this->context->expects($this->never())
      ->method('buildViolation');

    $this->validator->validate(NULL, $constraint);
  }

  /**
   * @covers ::validate
   * @dataProvider providerValidate
   */
  public function testValidateWithNonStringValue(mixed $slot_name, bool $is_invalid): void {
    $constraint = new ValidSlotNameConstraint();
    $value = new \stdClass();

    $data = $this->createMock(TypedDataInterface::class);
    $data->expects($this->once())
      ->method('getName')
      ->willReturn($slot_name);

    $this->context->expects($this->once())
      ->method('getObject')
      ->willReturn($data);

    $violation_builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $this->context->expects($is_invalid ? $this->once() : $this->never())
      ->method('buildViolation')
      ->with($this->anything())
      ->willReturn($violation_builder);
    // We do not assert setParameter() or addViolation() to remain compatible with different validator implementations.

    $this->validator->validate($value, $constraint);
  }

  // cspell:ignore eird

  /**
   * Data provider for testValidate().
   */
  public static function providerValidate(): array {
    return [
      // Valid slot names.
      ['valid', FALSE],
      ['even_more-valid', FALSE],
      ['aaa', FALSE],

      // Invalid slot names - too short.
      ['a', TRUE],
      ['aa', TRUE],

      // Invalid slot names - starts with an invalid character.
      ['-', TRUE],
      ['--', TRUE],
      ['_', TRUE],
      ['__', TRUE],
      ['-not_valid', TRUE],
      ['_not_valid', TRUE],

      // Invalid slot names - ends with invalid character.
      ['not_valid-', TRUE],
      ['not_valid_', TRUE],

      // Invalid slot names - contains invalid characters.
      ['n😈t_valid', TRUE],
      ['spaces aren\'t okay', TRUE],
      ["newline\nnot_allowed", TRUE],
      ['rm -rf /', TRUE],
      ['slot_\u03E2eird', TRUE],

      // Children is a valid slot name, with a special meaning for code
      // components.
      // @see https://www.drupal.org/i/3531766
      ['children', FALSE],
    ];
  }

}
