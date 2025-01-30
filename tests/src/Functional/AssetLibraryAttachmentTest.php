<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\experience_builder\Entity\AssetLibrary;

final class AssetLibraryAttachmentTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function test(): void {
    $library = AssetLibrary::create([
      'id' => 'global',
      'label' => 'Test',
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'js' => [
        'original' => 'console.log( "Test" )',
        'compiled' => 'console.log("Test")',
      ],
    ]);
    $library->save();

    $url_generator = \Drupal::service(FileUrlGeneratorInterface::class);
    $css_path = $url_generator->generateString($library->getCssPath());
    $js_path = $url_generator->generateString($library->getJsPath());

    $this->drupalGet('/user');
    $this->assertSession()->elementExists('css', 'link[href^="' . $css_path . '"]');
    $this->assertSession()->elementExists('css', 'script[src^="' . $js_path . '"]');

    $this->drupalGet($css_path);
    $this->assertSame($library->getCss(), $this->getTextContent());

    $this->drupalGet($js_path);
    $this->assertSame($library->getJs(), $this->getTextContent());
  }

}
