<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use PHPUnit\Framework\Attributes\Group;
use Drupal\image\Entity\ImageStyle;

/**
 * Tests Canvas Parametrized Width Image Style Avif Update.
 *
 * @legacy-covers \canvas_post_update_0012_canvas_image_style_avif
 */
#[Group('canvas')]
final class CanvasParametrizedWidthImageStyleAvifUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  protected const string EFFECT_ID = '249b8926-f421-4d60-8453-fb5d9265c731';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
  }

  /**
   * Tests collapsing inputs.
   */
  public function testCollapseInputs(): void {
    $image_style = ImageStyle::load('canvas_parametrized_width');
    \assert($image_style instanceof ImageStyle);
    self::assertEntityIsValid($image_style);
    \assert(!\is_null($image_style->getEffect(self::EFFECT_ID)));
    \assert('image_convert' === $image_style->getEffect(self::EFFECT_ID)->getPluginId());

    $this->runUpdates();

    $image_style = ImageStyle::load('canvas_parametrized_width');
    \assert($image_style instanceof ImageStyle);
    self::assertEntityIsValid($image_style);
    \assert(!\is_null($image_style->getEffect(self::EFFECT_ID)));
    \assert('image_convert_avif' === $image_style->getEffect(self::EFFECT_ID)->getPluginId());
  }

}
