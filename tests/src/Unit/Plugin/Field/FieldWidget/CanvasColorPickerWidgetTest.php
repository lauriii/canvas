<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Plugin\Field\FieldWidget;

use Drupal\canvas\Plugin\Field\FieldWidget\CanvasColorPickerWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Drupal\canvas\Plugin\Field\FieldWidget\CanvasColorPickerWidget.
 */
#[CoversClass(CanvasColorPickerWidget::class)]
#[Group('canvas')]
final class CanvasColorPickerWidgetTest extends UnitTestCase {

  /**
   * Creates a widget instance with the given settings and stored value.
   *
   * @param array<string, mixed> $settings
   *   Widget settings.
   * @param string $stored_value
   *   The stored field value.
   *
   * @return array{\Drupal\canvas\Plugin\Field\FieldWidget\CanvasColorPickerWidget, \Drupal\Core\Field\FieldItemListInterface}
   *   The widget instance and its mocked item list.
   */
  private function createWidget(array $settings, string $stored_value): array {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getLabel')->willReturn('Color');

    $item = $this->createMock(FieldItemInterface::class);
    $item->method('getValue')->willReturn(['value' => $stored_value]);

    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('get')->with(0)->willReturn($item);

    $widget = new CanvasColorPickerWidget(
      'canvas_color_picker',
      [],
      $field_definition,
      $settings,
      [],
    );
    $widget->setStringTranslation($this->getStringTranslationStub());

    return [$widget, $items];
  }

  /**
   * Tests the form element rendering for various stored values and settings.
   *
   * @param array<string, mixed> $settings
   *   Widget settings.
   * @param string $stored_value
   *   The stored field value.
   * @param array<string, mixed> $expected
   *   Expected form element subset.
   */
  #[DataProvider('formElementProvider')]
  public function testFormElement(array $settings, string $stored_value, array $expected): void {
    [$widget, $items] = $this->createWidget($settings, $stored_value);
    $form = [];
    $element = $widget->formElement(
      $items,
      0,
      [],
      $form,
      new FormState(),
    );

    self::assertSame('color', $element['value']['#type']);
    self::assertSame('Color', $element['value']['#title']);
    self::assertSame($expected['#default_value'], $element['value']['#default_value']);
    self::assertSame($expected['#attributes'], $element['value']['#attributes']);
  }

  /**
   * Provides form element scenarios.
   *
   * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<string, mixed>}>
   */
  public static function formElementProvider(): array {
    return [
      'empty value defaults to black' => [
        [],
        '',
        [
          '#default_value' => '#000000',
          '#attributes' => [
            'data-canvas-color-picker' => 'kit-only',
            'data-canvas-color-value' => '',
            'data-canvas-color-label' => 'Color',
          ],
        ],
      ],
      'brand kit reference shows black fallback' => [
        [],
        'canvas-color:a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        [
          '#default_value' => '#000000',
          '#attributes' => [
            'data-canvas-color-picker' => 'kit-only',
            'data-canvas-color-value' => 'canvas-color:a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
            'data-canvas-color-label' => 'Color',
          ],
        ],
      ],
      '6-digit hex passes through' => [
        [],
        '#ff0000',
        [
          '#default_value' => '#ff0000',
          '#attributes' => [
            'data-canvas-color-picker' => 'kit-only',
            'data-canvas-color-value' => '#ff0000',
            'data-canvas-color-label' => 'Color',
          ],
        ],
      ],
      '8-digit hex strips alpha' => [
        [],
        '#00ff0080',
        [
          '#default_value' => '#00ff00',
          '#attributes' => [
            'data-canvas-color-picker' => 'kit-only',
            'data-canvas-color-value' => '#00ff0080',
            'data-canvas-color-label' => 'Color',
          ],
        ],
      ],
      '3-digit shorthand expands' => [
        [],
        '#f0f',
        [
          '#default_value' => '#ff00ff',
          '#attributes' => [
            'data-canvas-color-picker' => 'kit-only',
            'data-canvas-color-value' => '#f0f',
            'data-canvas-color-label' => 'Color',
          ],
        ],
      ],
      'custom mode and folders are rendered' => [
        [
          'mode' => 'kit-and-free',
          'folders' => ['folder-1', 'folder-2'],
        ],
        '#123456',
        [
          '#default_value' => '#123456',
          '#attributes' => [
            'data-canvas-color-picker' => 'kit-and-free',
            'data-canvas-color-value' => '#123456',
            'data-canvas-color-label' => 'Color',
            'data-canvas-color-folders' => '["folder-1","folder-2"]',
          ],
        ],
      ],
      'hsl string falls back to black' => [
        [],
        'hsl(120, 100%, 50%)',
        [
          '#default_value' => '#000000',
          '#attributes' => [
            'data-canvas-color-picker' => 'kit-only',
            'data-canvas-color-value' => 'hsl(120, 100%, 50%)',
            'data-canvas-color-label' => 'Color',
          ],
        ],
      ],
      'hsla string falls back to black' => [
        [],
        'hsla(240, 50%, 75%, 0.50)',
        [
          '#default_value' => '#000000',
          '#attributes' => [
            'data-canvas-color-picker' => 'kit-only',
            'data-canvas-color-value' => 'hsla(240, 50%, 75%, 0.50)',
            'data-canvas-color-label' => 'Color',
          ],
        ],
      ],
    ];
  }

  /**
   * Tests default widget settings.
   */
  public function testDefaultSettings(): void {
    [$widget] = $this->createWidget([], '');

    self::assertSame('kit-only', $widget->getSetting('mode'));
    self::assertSame([], $widget->getSetting('folders'));
  }

  /**
   * Tests the settings form structure.
   */
  public function testSettingsForm(): void {
    [$widget] = $this->createWidget([], '');
    $form = $widget->settingsForm([], new FormState());

    self::assertSame('select', $form['mode']['#type']);
    self::assertSame('kit-only', $form['mode']['#default_value']);
    self::assertSame([
      'kit-only' => 'Brand Kit',
      'kit-and-free' => 'Freeform',
    ], \array_map(
      static fn (mixed $markup): string => (string) $markup,
      $form['mode']['#options'],
    ));
  }

}
