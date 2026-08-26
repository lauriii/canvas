<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Plugin\Validation\Constraint;

use Drupal\canvas\Plugin\Validation\Constraint\ColorComponentCountConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\ColorComponentCountConstraintValidator;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

#[Group('canvas')]
#[CoversClass(ColorComponentCountConstraintValidator::class)]
final class ColorComponentCountConstraintValidatorTest extends UnitTestCase {

  private ColorComponentCountConstraintValidator $validator;

  private ExecutionContextInterface&MockObject $context;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new ColorComponentCountConstraintValidator();
    $this->context = $this->createMock(ExecutionContextInterface::class);
    $this->validator->initialize($this->context);
  }

  /**
   * Builds the typed-data mock chain used by the validator.
   *
   * The validator calls:
   *   $this->context->getObject()->getParent()->getValue()
   * to retrieve the sibling `colorSpace` from the parent mapping.
   *
   * @param string $colorSpace
   *   The color-space to return from the parent mapping.
   */
  private function mockContextForColorSpace(string $colorSpace): void {
    $parent = $this->createMock(TypedDataInterface::class);
    $parent->method('getValue')
      ->willReturn(['colorSpace' => $colorSpace, 'components' => []]);

    $object = $this->createMock(TypedDataInterface::class);
    $object->method('getParent')
      ->willReturn($parent);

    $this->context->method('getObject')
      ->willReturn($object);
  }

  #[DataProvider('providerValid')]
  public function testNoViolationWhenValid(string $colorSpace, array $components): void {
    $this->mockContextForColorSpace($colorSpace);
    $this->context->expects($this->never())->method('buildViolation');

    $this->validator->validate($components, new ColorComponentCountConstraint());
  }

  public static function providerValid(): array {
    return [
      'sRGB with exactly 3 components' => ['srgb', [0.0, 0.4, 0.8]],
      'HSL with exactly 3 components' => ['hsl', [120.0, 100.0, 50.0]],
      // Unknown color spaces are intentionally allowed so future spaces can
      // be introduced without breaking validation.
      'Unknown color space with 2 components' => ['oklch', [0.5, 0.1]],
      'Unknown color space with 4 components' => ['xyz-d65', [0.1, 0.2, 0.3, 0.4]],
    ];
  }

  #[DataProvider('providerInvalid')]
  public function testViolationWhenInvalid(
    string $colorSpace,
    array $components,
    string $expected_message_pattern,
  ): void {
    $this->mockContextForColorSpace($colorSpace);

    $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $violationBuilder->method('setParameter')->willReturnSelf();
    $violationBuilder->expects($this->once())->method('addViolation');

    $this->context->expects($this->once())
      ->method('buildViolation')
      ->with($this->stringContains('%colorSpace'))
      ->willReturn($violationBuilder);

    $this->validator->validate($components, new ColorComponentCountConstraint());
  }

  public static function providerInvalid(): array {
    return [
      'sRGB with 2 components (too few)' => [
        'srgb', [0.0, 0.4],
        'srgb color space requires exactly 3',
      ],
      'sRGB with 4 components (too many)' => [
        'srgb', [0.0, 0.4, 0.8, 0.2],
        'srgb color space requires exactly 3',
      ],
      'sRGB with 1 component' => [
        'srgb', [0.5],
        'srgb color space requires exactly 3',
      ],
      'sRGB with 0 components' => [
        'srgb', [],
        'srgb color space requires exactly 3',
      ],
      'HSL with 2 components (too few)' => [
        'hsl', [120.0, 100.0],
        'hsl color space requires exactly 3',
      ],
      'HSL with 4 components (too many)' => [
        'hsl', [120.0, 100.0, 50.0, 0.5],
        'hsl color space requires exactly 3',
      ],
    ];
  }

  /**
   * Non-array values are silently ignored (type guard).
   */
  public function testNonArrayValueIsIgnored(): void {
    // getObject() should never be called because the guard returns early.
    $this->context->expects($this->never())->method('getObject');
    $this->context->expects($this->never())->method('buildViolation');

    $this->validator->validate('not-an-array', new ColorComponentCountConstraint());
    $this->validator->validate(NULL, new ColorComponentCountConstraint());
    $this->validator->validate(42, new ColorComponentCountConstraint());
  }

  /**
   * A null object from getObject() is silently ignored.
   */
  public function testNullObjectIsIgnored(): void {
    $this->context->method('getObject')->willReturn(NULL);
    $this->context->expects($this->never())->method('buildViolation');

    $this->validator->validate([1.0, 2.0], new ColorComponentCountConstraint());
  }

  /**
   * A null parent from getParent() is silently ignored.
   */
  public function testNullParentIsIgnored(): void {
    $object = $this->createMock(TypedDataInterface::class);
    $object->method('getParent')->willReturn(NULL);

    $this->context->method('getObject')->willReturn($object);
    $this->context->expects($this->never())->method('buildViolation');

    $this->validator->validate([1.0, 2.0], new ColorComponentCountConstraint());
  }

  /**
   * A missing colorSpace key in the parent mapping is silently ignored.
   */
  public function testMissingColorSpaceIsIgnored(): void {
    $parent = $this->createMock(TypedDataInterface::class);
    $parent->method('getValue')->willReturn([]);

    $object = $this->createMock(TypedDataInterface::class);
    $object->method('getParent')->willReturn($parent);

    $this->context->method('getObject')->willReturn($object);
    $this->context->expects($this->never())->method('buildViolation');

    $this->validator->validate([1.0, 2.0], new ColorComponentCountConstraint());
  }

  /**
   * A non-string colorSpace value in the parent mapping is silently ignored.
   */
  public function testNonStringColorSpaceIsIgnored(): void {
    $parent = $this->createMock(TypedDataInterface::class);
    $parent->method('getValue')->willReturn(['colorSpace' => 42]);

    $object = $this->createMock(TypedDataInterface::class);
    $object->method('getParent')->willReturn($parent);

    $this->context->method('getObject')->willReturn($object);
    $this->context->expects($this->never())->method('buildViolation');

    $this->validator->validate([1.0, 2.0], new ColorComponentCountConstraint());
  }

}
