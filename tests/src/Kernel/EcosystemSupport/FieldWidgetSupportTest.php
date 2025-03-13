<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\EcosystemSupport;

/**
 * Checks that all core field widgets have XB client-side transforms metadata.
 *
 * @covers \experience_builder_field_widget_info_alter()
 * @see docs/redux-integrated-field-widgets.md#3.4
 * @group experience_builder
 */
final class FieldWidgetSupportTest extends EcosystemSupportTestBase {

  public const COMPLETION = 0.35714285714285715;
  public const SUPPORTED = [
    'boolean_checkbox',
    'datetime_default',
    'email_default',
    'image_image',
    'link_default',
    'media_library_widget',
    'number',
    'options_select',
    'string_textarea',
    'string_textfield',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'comment',
    'content_moderation',
    'datetime',
    'datetime_range',
    'file',
    'image',
    'link',
    'media',
    'media_library',
    'path',
    'telephone',
    'text',
    // Modules that field widget-providing modules depend on.
    'views',
  ];

  public function test(): void {
    $this->assertSame(['layout_builder'], self::getUninstalledStableModulesWithPlugin('Plugin/Field/FieldWidget'));

    $field_widget_definitions = $this->container->get('plugin.manager.field.widget')->getDefinitions();
    ksort($field_widget_definitions);
    $supported = array_filter($field_widget_definitions, fn (array $def): bool => array_key_exists('xb', $def) && array_key_exists('transforms', $def['xb']));
    $missing = array_diff_key($field_widget_definitions, $supported);

    $this->assertSame(self::SUPPORTED, array_keys($supported));
    $this->assertSame(
      self::COMPLETION,
      count($supported) / count($field_widget_definitions),
      sprintf('Not yet supported: %s', implode(', ', array_keys($missing)))
    );
  }

}
