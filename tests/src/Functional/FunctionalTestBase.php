<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\EventSubscriber\LanguageConfigOverrideSchemaChecker;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Base class for functional tests of Canvas, ensures OpenAPI validation is active.
 *
 * Provides common setup and helper methods for functional tests.
 */
#[Group('canvas')]
abstract class FunctionalTestBase extends BrowserTestBase {

  use TestFileCreationTrait;
  use CanvasFieldCreationTrait;

  /**
   * {@inheritdoc}
   *
   * Registers LanguageConfigOverrideSchemaChecker analogously to how
   * FunctionalTestSetupTrait::prepareSettings() registers ConfigSchemaChecker:
   * by injecting it into the test site's services.yml.
   *
   * @see \Drupal\Core\Config\Development\ConfigSchemaChecker
   * @see \Drupal\Core\Test\FunctionalTestSetupTrait::prepareSettings()
   */
  protected function prepareSettings() {
    parent::prepareSettings();

    if ($this->strictConfigSchema) {
      $filename = $this->siteDirectory . '/services.yml';
      $services = Yaml::parse((string) file_get_contents($filename));
      // Only fires when the `language` module is installed, so safe to register
      // unconditionally (analogous to how ConfigSchemaChecker is registered).
      $services['services']['canvas.testing.language_config_override_schema_checker'] = [
        'class' => LanguageConfigOverrideSchemaChecker::class,
        'arguments' => ['@config.typed', '@config.factory'],
        'tags' => [['name' => 'event_subscriber']],
      ];
      file_put_contents($filename, Yaml::dump($services));
    }
  }

  protected function setUp(): void {
    parent::setUp();
    $config = $this->container->get(ConfigFactoryInterface::class)->getEditable('system.performance');
    $config->set('js.preprocess', TRUE);
    $config->set('css.preprocess', TRUE);
    $config->save();
    if ($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas')) {
      $response_validator = $this->container->get('canvas.openapi.http_response_validator.subscriber');
      $request_validator = $this->container->get('canvas.openapi.http_request_validator.subscriber');
      if (!($request_validator->isValidationEnabled() && $response_validator->isValidationEnabled())) {
        $this->fail('OpenAPI validation must be enabled to run functional tests. See the CONTRIBUTING.md file.');
      }
    }
  }

  protected function createTestNode(): Node {
    $nodes = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node')->loadMultiple();
    $expected_nid = count($nodes) + 1;
    $this->assertNull(Node::load($expected_nid));
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    // The `thumbnail` image style already exists.
    $this->assertInstanceOf(ImageStyle::class, ImageStyle::load('thumbnail'));

    // Navigate to `/node/add/article` and press `Save`, do nothing else.
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('node/add/article');
    $assert_session->statusCodeEquals(200);
    $page->pressButton('Save');
    $this->assertStringEndsWith('node/add/article', $this->getSession()->getCurrentUrl());
    // @todo For some reason, specifying `type: 'error'` fails: the expected HTML structure is different?! 🤯
    $this->assertSession()->statusMessageContains('Title field is required.');

    // One entity fields is required: `Title`. Fill it, press `Save`.
    $page->fillField('title[0][value]', 'The first entity using Canvas!');
    $page->pressButton('Save');

    // Success!
    $this->assertStringEndsWith("node/$expected_nid", $this->getSession()->getCurrentUrl());

    $node = Node::load($expected_nid);
    // @phpstan-ignore-next-line
    $this->assertInstanceOf(Node::class, $node);
    return $node;
  }

}
