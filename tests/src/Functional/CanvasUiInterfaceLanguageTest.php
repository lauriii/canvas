<?php

// cspell:ignore kaikki Valitse

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\locale\StringStorageInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the Canvas editor UI renders in the interface language.
 *
 * The editor is an administrative UI, so it has to follow the interface
 * language negotiation the rest of the admin UI follows, including the
 * "Account administration pages" method. That method applies only to
 * administrative routes.
 *
 * @see \Drupal\user\Plugin\LanguageNegotiation\LanguageNegotiationUserAdmin
 * @see \Drupal\canvas\Controller\CanvasController
 */
#[Group('canvas')]
class CanvasUiInterfaceLanguageTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'language',
    'locale',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A string the editor UI renders, wrapped in Drupal.t().
   *
   * @see ui/src/components/review/PublishReview.tsx
   */
  private const SOURCE_STRING = 'Select All';

  /**
   * The Finnish translation seeded for that string.
   */
  private const FINNISH_STRING = 'Valitse kaikki';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Drupal scans the built bundle, not the TypeScript source, so there is
    // nothing to assert without it.
    // @see _locale_parse_js_file()
    if (!file_exists($this->getBundlePath())) {
      $this->markTestSkipped('The editor UI has not been built. Run `npm run build -w ui`.');
    }

    // Without aggregation the generated translation file keeps its own URL, so
    // the test can tell which language's file the page carries.
    $this->config('system.performance')->set('js.preprocess', FALSE)->save();

    ConfigurableLanguage::createFromLangcode('fi')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();

    // The interface language comes from the user's administration language
    // preference where there is one, and otherwise from a language that is
    // neither the site default nor anything the browser asked for. That makes
    // the assertions below distinguish a correct implementation from one that
    // happens to fall back to the site default.
    $this->config('language.types')
      ->set('configurable', ['language_interface', 'language_content'])
      ->set('negotiation.language_interface.enabled', [
        'language-user-admin' => -10,
        'language-selected' => 12,
      ])
      ->set('negotiation.language_content.enabled', ['language-selected' => 12])
      ->save();
    $this->config('language.negotiation')->set('selected_langcode', 'de')->save();
  }

  /**
   * Path of the built editor bundle Drupal scans for translatable strings.
   */
  private function getBundlePath(): string {
    return \DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')
      ->getPath('canvas') . '/ui/dist/assets/index.js';
  }

  /**
   * Seeds a Finnish translation for a string the editor UI renders.
   *
   * The string is registered as a JavaScript string exactly as
   * _locale_parse_js_file() registers it, so that _locale_rebuild_js() picks it
   * up for the generated per-language translation file.
   */
  private function translateEditorString(): void {
    /** @var \Drupal\locale\StringStorageInterface $storage */
    $storage = $this->container->get(StringStorageInterface::class);
    $source = $storage->createString([
      'source' => self::SOURCE_STRING,
      'context' => '',
    ])->save();
    $source->addLocation('javascript', $this->getBundlePath());
    $source->save();
    $storage->createTranslation([
      'lid' => $source->lid,
      'language' => 'fi',
      'translation' => self::FINNISH_STRING,
    ])->save();
    _locale_invalidate_js('fi');
  }

  /**
   * Tests the editor page in a user's administration language.
   */
  public function testEditorRendersInAdministrationLanguage(): void {
    $this->translateEditorString();

    $user = $this->drupalCreateUser([
      'access administration pages',
      JavaScriptComponent::ADMIN_PERMISSION,
    ]);
    $user->set('preferred_langcode', 'en');
    $user->set('preferred_admin_langcode', 'fi');
    $user->save();
    $this->drupalLogin($user);

    // Two requests: the first lets locale scan the bundle and build the Finnish
    // translation file, which the second one then carries.
    $this->drupalGet('canvas');
    $this->drupalGet('canvas');
    $this->assertSession()->statusCodeEquals(200);

    // The page reports the interface language, not the content language and not
    // the site default.
    $this->assertSession()->elementAttributeContains('css', 'html', 'lang', 'fi');

    // The Finnish translation file is attached, and no other language's is.
    $directory = $this->config('locale.settings')->get('javascript.directory');
    $html = $this->getSession()->getPage()->getContent();
    $this->assertMatchesRegularExpression("#$directory/fi_[^\"]+\.js#", $html);
    $this->assertDoesNotMatchRegularExpression("#$directory/(de|en)_[^\"]+\.js#", $html);

    // The Finnish translation file really carries the editor's string.
    $this->assertStringContainsString(
      self::FINNISH_STRING,
      $this->getTranslationFileContents('fi'),
    );
  }

  /**
   * Tests that the editor falls back to the negotiated interface language.
   */
  public function testEditorWithoutAdministrationLanguagePreference(): void {
    $this->translateEditorString();

    $user = $this->drupalCreateUser([
      'access administration pages',
      JavaScriptComponent::ADMIN_PERMISSION,
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('canvas');
    $this->assertSession()->statusCodeEquals(200);

    // With no administration language preference, the next enabled method wins.
    // German here, which is neither the site default nor a content language
    // choice made by the editor UI.
    $this->assertSession()->elementAttributeContains('css', 'html', 'lang', 'de');
  }

  /**
   * Tests that the routes booting the editor are administrative.
   *
   * Without this, LanguageNegotiationUserAdmin never applies and the two tests
   * above would pass only by accident of the negotiation order.
   */
  public function testBootRoutesAreAdministrative(): void {
    $route_provider = $this->container->get('router.route_provider');
    foreach (['canvas.boot.app', 'canvas.boot.empty', 'canvas.boot.entity'] as $route_name) {
      $this->assertTrue(
        (bool) $route_provider->getRouteByName($route_name)->getOption('_admin_route'),
        "$route_name must be an administrative route so the interface language is negotiated for it."
      );
    }
  }

  /**
   * Returns the contents of the generated translation file for a language.
   */
  private function getTranslationFileContents(string $langcode): string {
    $hashes = \Drupal::state()->get('locale.translation.javascript', []);
    $this->assertArrayHasKey($langcode, $hashes);
    $directory = 'assets://' . $this->config('locale.settings')->get('javascript.directory');
    $contents = file_get_contents("$directory/$langcode" . '_' . $hashes[$langcode] . '.js');
    $this->assertIsString($contents);
    return $contents;
  }

}
