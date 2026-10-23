<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Plugin\Field\FieldWidget;

use Drupal\canvas\Plugin\Field\FieldWidget\CanvasFocalPointWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Drupal\canvas\Plugin\Field\FieldWidget\CanvasFocalPointWidget.
 */
#[CoversClass(CanvasFocalPointWidget::class)]
#[Group('canvas')]
final class CanvasFocalPointWidgetTest extends UnitTestCase {

  /**
   * Builds the form element for a stored point.
   *
   * @param array<string, mixed> $settings
   *   Widget settings.
   * @param array{x: float|null, y: float|null}|null $stored
   *   The stored point, or NULL for no field item.
   * @param bool $required
   *   Whether the element is required.
   *
   * @return array<string, mixed>
   *   The form element.
   */
  private function buildElement(array $settings, ?array $stored, bool $required = FALSE): array {
    $item = NULL;
    if ($stored !== NULL) {
      $item = $this->createMock(FieldItemInterface::class);
      $item->method('get')->willReturnCallback(function (string $property) use ($stored): TypedDataInterface {
        $typed_data = $this->createMock(TypedDataInterface::class);
        $typed_data->method('getValue')->willReturn($stored[$property]);
        return $typed_data;
      });
    }
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('get')->with(0)->willReturn($item);

    $widget = new CanvasFocalPointWidget(
      'canvas_focal_point',
      [],
      $this->createMock(FieldDefinitionInterface::class),
      $settings,
      [],
    );
    $widget->setStringTranslation($this->getStringTranslationStub());

    $form = [];
    return $widget->formElement($items, 0, ['#required' => $required], $form, new FormState());
  }

  /**
   * Tests the fieldset with its x and y inputs.
   *
   * @param array<string, mixed> $settings
   *   Widget settings.
   * @param array{x: float|null, y: float|null}|null $stored
   *   The stored point.
   * @param string $expected_image_prop
   *   The expected data-canvas-focal-point-image attribute.
   * @param array{0: float|null, 1: float|null} $expected_values
   *   The expected x and y default values.
   */
  #[DataProvider('formElementProvider')]
  public function testFormElement(array $settings, ?array $stored, string $expected_image_prop, array $expected_values): void {
    $element = $this->buildElement($settings, $stored);

    self::assertSame('fieldset', $element['#type']);
    self::assertSame($expected_image_prop, $element['#attributes']['data-canvas-focal-point-image']);
    foreach (['x', 'y'] as $index => $axis) {
      self::assertSame('number', $element[$axis]['#type']);
      self::assertSame('invisible', $element[$axis]['#title_display']);
      self::assertSame(0, $element[$axis]['#min']);
      self::assertSame(100, $element[$axis]['#max']);
      self::assertSame('any', $element[$axis]['#step']);
      self::assertSame($expected_values[$index], $element[$axis]['#default_value']);
      self::assertFalse($element[$axis]['#required']);
    }
  }

  /**
   * @return array<string, array{0: array<string, mixed>, 1: array{x: float|null, y: float|null}|null, 2: string, 3: array{0: float|null, 1: float|null}}>
   */
  public static function formElementProvider(): array {
    return [
      'stored point, image prop' => [['image_prop' => 'image'], ['x' => 25.0, 'y' => 75.0], 'image', [25.0, 75.0]],
      'edges are values' => [['image_prop' => 'image'], ['x' => 0.0, 'y' => 100.0], 'image', [0.0, 100.0]],
      'no field item, no image prop' => [[], NULL, '', [NULL, NULL]],
    ];
  }

  /**
   * Tests that a required prop makes both inputs required.
   */
  public function testRequired(): void {
    $element = $this->buildElement([], NULL, TRUE);
    self::assertTrue($element['x']['#required']);
    self::assertTrue($element['y']['#required']);
  }

  /**
   * Tests the default settings.
   */
  public function testDefaultSettings(): void {
    self::assertNull(CanvasFocalPointWidget::defaultSettings()['image_prop']);
  }

}
