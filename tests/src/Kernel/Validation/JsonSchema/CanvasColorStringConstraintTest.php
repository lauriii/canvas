<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Validation\JsonSchema;

use Drupal\canvas\Entity\Color;
use Drupal\canvas\Validation\JsonSchema\CanvasColorStringConstraint;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use JsonSchema\Constraints\Factory;
use JsonSchema\DraftIdentifiers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests CanvasColorStringConstraint validation paths.
 *
 * Exercises the constraint directly via the JSON Schema Factory service to
 * avoid the $ref-resolution circular-reference that occurs when the constraint
 * is reached through ComponentValidator::validateProps(). The constraint
 * receives a schema whose $ref has already been dereferenced by the library;
 * the schema object it sees at call time still carries the $ref property,
 * which is how the constraint identifies color props.
 *
 * @see \Drupal\canvas\Validation\JsonSchema\CanvasColorStringConstraint
 * @see \Drupal\canvas\CanvasServiceProvider::alter()
 */
#[Group('canvas')]
#[CoversClass(CanvasColorStringConstraint::class)]
final class CanvasColorStringConstraintTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('color');
  }

  /**
   * Builds a color-prop schema object as the constraint receives it at runtime.
   *
   * At validation time the JSON Schema library has already resolved the $ref
   * on the prop and passed the resolved schema (which still carries $ref) to
   * CanvasColorStringConstraint::check(). We reproduce that object here.
   */
  private static function colorPropSchema(): \stdClass {
    return (object) [
      'type' => 'string',
      '$ref' => 'json-schema-definitions://canvas.module/color',
    ];
  }

  /**
   * Returns a CanvasColorStringConstraint backed by a correctly configured Factory.
   *
   * Replicates the setup from CanvasServiceProvider::alter() so the constraint
   * runs in the same environment as production without needing the compiled
   * service container to expose Factory as a public service.
   *
   * @see \Drupal\canvas\CanvasServiceProvider::alter()
   */
  private static function buildConstraint(): CanvasColorStringConstraint {
    $factory = new Factory();
    $factory->setDefaultDialect(DraftIdentifiers::DRAFT_7);
    $factory->setConstraintClass('string', CanvasColorStringConstraint::class);
    return new CanvasColorStringConstraint($factory);
  }

  /**
   * Tests valid and invalid color strings.
   *
   * @param string $color_value
   *   The color string to validate.
   * @param bool $expect_valid
   *   Whether the value should pass validation.
   *
   * @see \Drupal\canvas\Validation\JsonSchema\CanvasColorStringConstraint::check()
   */
  #[DataProvider('providerColorStringValidation')]
  public function testColorStringConstraint(string $color_value, bool $expect_valid): void {
    $constraint = $this->buildConstraint();
    $schema = $this->colorPropSchema();
    $element = $color_value;
    $constraint->check($element, $schema);

    if ($expect_valid) {
      $this->assertTrue($constraint->isValid(), \sprintf("Color value '%s' should be valid but got errors: %s", $color_value, \json_encode($constraint->getErrors())));
    }
    else {
      $this->assertFalse($constraint->isValid(), \sprintf("Color value '%s' should be invalid.", $color_value));
    }
  }

  /**
   * Data provider for ::testColorStringConstraint().
   *
   * @return array<string, array{string, bool}>
   */
  public static function providerColorStringValidation(): array {
    return [
      // Valid 6-digit hex.
      'Valid: 6-digit hex #ff0000' => ['#ff0000', TRUE],
      'Valid: 6-digit hex uppercase #00FF00' => ['#00FF00', TRUE],
      'Valid: 6-digit hex #abcdef' => ['#abcdef', TRUE],

      // Valid 8-digit hex.
      'Valid: 8-digit hex #ff0000ff' => ['#ff0000ff', TRUE],
      'Valid: 8-digit hex #00ff00aa' => ['#00ff00aa', TRUE],
      'Valid: 8-digit hex uppercase #ABCDEF00' => ['#ABCDEF00', TRUE],

      // Invalid hex length.
      'Invalid: 3-digit hex #f00' => ['#f00', FALSE],
      'Invalid: 5-digit hex #ff000' => ['#ff000', FALSE],
      'Invalid: 7-digit hex #ff0000f' => ['#ff0000f', FALSE],
      'Invalid: 9-digit hex #ff0000fff' => ['#ff0000fff', FALSE],

      // Valid HSL.
      'Valid: hsl(120, 100%, 50%)' => ['hsl(120, 100%, 50%)', TRUE],
      'Valid: hsl boundary hsl(0, 0%, 0%)' => ['hsl(0, 0%, 0%)', TRUE],
      'Valid: hsl boundary hsl(360, 100%, 100%)' => ['hsl(360, 100%, 100%)', TRUE],

      // Valid HSLA.
      'Valid: hsla(240, 50%, 75%, 0.50)' => ['hsla(240, 50%, 75%, 0.50)', TRUE],
      'Valid: hsla boundary hsla(0, 0%, 0%, 0)' => ['hsla(0, 0%, 0%, 0)', TRUE],
      'Valid: hsla boundary hsla(0, 0%, 0%, 1)' => ['hsla(0, 0%, 0%, 1)', TRUE],

      // Out-of-range HSL/HSLA components.
      'Invalid: hue > 360 hsl(361, 50%, 50%)' => ['hsl(361, 50%, 50%)', FALSE],
      'Invalid: saturation > 100 hsl(120, 101%, 50%)' => ['hsl(120, 101%, 50%)', FALSE],
      'Invalid: lightness > 100 hsl(120, 50%, 101%)' => ['hsl(120, 50%, 101%)', FALSE],
      'Invalid: HSLA alpha > 1 hsla(120, 50%, 50%, 1.1)' => ['hsla(120, 50%, 50%, 1.1)', FALSE],
      'Invalid: HSLA alpha < 0 hsla(120, 50%, 50%, -0.1)' => ['hsla(120, 50%, 50%, -0.1)', FALSE],

      // Unrecognized formats.
      'Invalid: rgb() format' => ['rgb(255, 0, 0)', FALSE],
      'Invalid: named color' => ['red', FALSE],
      'Invalid: empty string' => ['', FALSE],
      'Invalid: arbitrary string' => ['not-a-color', FALSE],
    ];
  }

  /**
   * Tests Brand Kit reference validation.
   *
   * @param string $color_ref
   *   The Brand Kit reference to validate.
   * @param bool $expect_valid
   *   Whether the reference should pass validation.
   *
   * @see \Drupal\canvas\Validation\JsonSchema\CanvasColorStringConstraint::check()
   */
  #[DataProvider('providerBrandKitReferenceValidation')]
  public function testBrandKitReferenceConstraint(string $color_ref, bool $expect_valid): void {
    $constraint = $this->buildConstraint();
    $schema = $this->colorPropSchema();
    $element = $color_ref;
    $constraint->check($element, $schema);

    if ($expect_valid) {
      $this->assertTrue($constraint->isValid(), \sprintf("Brand Kit ref '%s' should be valid.", $color_ref));
    }
    else {
      $this->assertFalse($constraint->isValid(), \sprintf("Brand Kit ref '%s' should be invalid.", $color_ref));
    }
  }

  /**
   * Data provider for ::testBrandKitReferenceConstraint().
   *
   * @return array<string, array{string, bool}>
   */
  public static function providerBrandKitReferenceValidation(): array {
    return [
      // Empty UUID after the prefix.
      'Invalid: empty UUID canvas-color:' => ['canvas-color:', FALSE],

      // UUID present but no matching Color entity.
      'Invalid: nonexistent color 00000000-…' => [
        'canvas-color:00000000-0000-0000-0000-000000000000',
        FALSE,
      ],
    ];
  }

  /**
   * Tests that a canvas-color: reference to an existing entity passes.
   *
   * @see \Drupal\canvas\Validation\JsonSchema\CanvasColorStringConstraint::colorEntityExists()
   */
  public function testValidBrandKitColorReference(): void {
    $color = Color::create([
      'name' => 'Test Brand Color',
      'cssVariable' => '--color-test-brand',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'hex' => '#ff0000',
      ],
      'weight' => 0,
    ]);
    $color->save();

    $constraint = $this->buildConstraint();
    $schema = $this->colorPropSchema();
    $color_ref = 'canvas-color:' . $color->id();
    $element = $color_ref;
    $constraint->check($element, $schema);

    $this->assertTrue($constraint->isValid(), \sprintf("Valid Brand Kit color ref '%s' should pass validation.", $color_ref));
  }

  /**
   * Tests the short-circuit for already-resolved color objects (arrays).
   *
   * At render time the stored color string has been resolved to a rich array.
   * The constraint must skip string validation in that case.
   *
   * @see \Drupal\canvas\Validation\JsonSchema\CanvasColorStringConstraint::check()
   */
  public function testArrayShortCircuitForResolvedColorObjects(): void {
    $constraint = $this->buildConstraint();
    $schema = $this->colorPropSchema();
    // A resolved color object (array) — what the render pipeline passes.
    $element = [
      'hex' => '#ff0000',
      'hex8' => '#ff0000ff',
      'cssVariable' => '--color-red',
      'colorName' => 'Red',
      'opacity' => 1.0,
      'cssValue' => 'var(--color-red)',
    ];
    $constraint->check($element, $schema);

    $this->assertTrue($constraint->isValid(), 'Resolved color objects (arrays) must skip validation and be considered valid.');
  }

  /**
   * Tests that non-color string props are not validated as colors.
   *
   * The constraint is registered as the 'string' JSON Schema constraint and
   * therefore runs for every string prop. It must be a no-op for any schema
   * that does not carry the canvas.module/color $ref.
   *
   * @see \Drupal\canvas\Validation\JsonSchema\CanvasColorStringConstraint::check()
   */
  public function testNonColorPropIsNotValidatedAsColor(): void {
    $constraint = $this->buildConstraint();
    // A plain string schema — no color $ref.
    $schema = (object) ['type' => 'string'];
    $element = 'not-a-hex-color-at-all';
    $constraint->check($element, $schema);

    $this->assertTrue($constraint->isValid(), 'Arbitrary strings on non-color props must not trigger color validation.');
  }

}
