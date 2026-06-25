<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests config schema validation for supported component source keys.
 */
#[Group('canvas_ai')]
final class CanvasAiComponentDescriptionSettingsSchemaTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_ai',
  ];

  /**
   * Tests that supported component source keys pass config schema validation.
   */
  public function testAcceptsSupportedComponentSourceKeys(): void {
    $typed_config = $this->container->get(TypedConfigManagerInterface::class);
    $config = $typed_config->createFromNameAndData('canvas_ai.component_description.settings', [
      'langcode' => 'en',
      'component_context' => [
        SingleDirectoryComponent::SOURCE_PLUGIN_ID => [
          'enabled' => TRUE,
          'data' => "foo: bar\n",
        ],
        JsComponent::SOURCE_PLUGIN_ID => [
          'enabled' => FALSE,
          'data' => "foo: baz\n",
        ],
        BlockComponent::SOURCE_PLUGIN_ID => [
          'enabled' => TRUE,
          'data' => "foo: qux\n",
        ],
      ],
    ]);

    $violations = $config->validate();
    self::assertCount(0, $violations, (string) $violations);
  }

  /**
   * Tests that unsupported plugin ID keys fail config schema validation.
   */
  public function testRejectsUnsupportedPluginIdKeys(): void {
    $typed_config = $this->container->get(TypedConfigManagerInterface::class);
    $config = $typed_config->createFromNameAndData('canvas_ai.component_description.settings', [
      'langcode' => 'en',
      'component_context' => [
        'third_party_source' => [
          'enabled' => TRUE,
          'data' => "foo: bar\n",
        ],
      ],
    ]);

    $violations = $config->validate();
    self::assertGreaterThan(0, $violations->count());
    self::assertStringContainsString(
      'not a supported component source plugin ID',
      implode("\n", self::violationsToArray($violations)),
    );
  }

  /**
   * Tests that the post-update hook normalizes old active config.
   */
  public function testPostUpdateNormalizesComponentDescriptionSettings(): void {
    $this->includePostUpdateFile();

    $this->container->get('config.storage')->write('canvas_ai.component_description.settings', [
      'langcode' => 'en',
      'component_context' => [
        SingleDirectoryComponent::SOURCE_PLUGIN_ID => [
          'enabled' => TRUE,
          'data' => "foo: bar\n",
        ],
        JsComponent::SOURCE_PLUGIN_ID => [
          'enabled' => 1,
          'data' => '',
        ],
        'invalid.source' => [
          'enabled' => TRUE,
          'data' => "remove: me\n",
        ],
        // Unsupported sources are stripped by the post-update hook.
        'fallback' => 'legacy scalar value',
        'p13n' => ['enabled' => FALSE, 'data' => "legacy: data\n"],
      ],
    ]);
    $this->container->get('config.factory')->reset('canvas_ai.component_description.settings');

    canvas_ai_post_update_0005_normalize_component_description_settings();

    $config = $this->config('canvas_ai.component_description.settings')->get('component_context');
    self::assertSame([
      JsComponent::SOURCE_PLUGIN_ID => [
        'enabled' => TRUE,
        'data' => "{}\n",
      ],
      SingleDirectoryComponent::SOURCE_PLUGIN_ID => [
        'enabled' => TRUE,
        'data' => "foo: bar\n",
      ],
    ], $config);
  }

  /**
   * Tests that the post-update hook does not create missing settings config.
   */
  public function testPostUpdateSkipsMissingComponentDescriptionSettings(): void {
    $this->includePostUpdateFile();

    $this->container->get('config.storage')->delete('canvas_ai.component_description.settings');
    $this->container->get('config.factory')->reset('canvas_ai.component_description.settings');

    canvas_ai_post_update_0005_normalize_component_description_settings();

    self::assertTrue($this->config('canvas_ai.component_description.settings')->isNew());
  }

  /**
   * Includes the Canvas AI post-update file.
   */
  private function includePostUpdateFile(): void {
    $module_path = $this->container->get('extension.list.module')->getPath('canvas_ai');
    require_once DRUPAL_ROOT . '/' . $module_path . '/canvas_ai.post_update.php';
  }

  /**
   * Converts constraint violations to strings for easier assertions.
   *
   * @return array<string>
   */
  private static function violationsToArray(ConstraintViolationListInterface $violations): array {
    $list = [];
    foreach ($violations as $violation) {
      \assert($violation instanceof ConstraintViolation);
      $list[] = $violation->getPropertyPath() . ': ' . \strip_tags((string) $violation->getMessage());
    }
    return $list;
  }

}
