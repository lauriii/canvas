<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

// cspell:ignore Bonjour fichier fichiers

use Drupal\canvas\Controller\ApiInterfaceTranslationController;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\PageVariant;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\locale\PluralFormulaInterface;
use Drupal\locale\StringStorageInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Tests looking up interface translations without registering strings.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiInterfaceTranslationController::class)]
#[Group('canvas')]
final class ApiInterfaceTranslationControllerTest extends CanvasKernelTestBase {

  use OpenApiSpecTrait;
  use RequestTrait;
  use UserCreationTrait;

  private const string URL = '/canvas/api/v0/interface-translation';

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    // Burn uid 1, which bypasses access checks.
    $this->createUser();
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION]);
  }

  /**
   * Requests the list of languages.
   *
   * @return int
   *   The status code.
   */
  private function languagesStatus(): int {
    try {
      return $this->request(Request::create(self::URL))->getStatusCode();
    }
    catch (HttpExceptionInterface $e) {
      return $e->getStatusCode();
    }
  }

  /**
   * Looks up the translations of strings.
   *
   * @param bool $validateRequest
   *   FALSE to send strings that the OpenAPI specification rejects, to the
   *   validation of the controller itself.
   *
   * @return array{int, array<mixed>}
   *   The status code, and the decoded response of a successful lookup.
   */
  private function lookup(string $langcode, mixed $strings, bool $validateRequest = TRUE): array {
    $request = Request::create(
      self::URL . '/' . $langcode,
      'POST',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: (string) \json_encode(['strings' => $strings]),
    );
    if (!$validateRequest) {
      $request->headers->set('X-NO-OPENAPI-VALIDATION', 'the controller validates the strings itself');
    }
    try {
      $response = $this->request($request);
    }
    catch (HttpExceptionInterface $e) {
      return [$e->getStatusCode(), []];
    }
    return [$response->getStatusCode(), static::decodeResponse($response)];
  }

  public function testLanguages(): void {
    // Without the Language module, the default language.
    $data = static::decodeResponse($this->request(Request::create(self::URL)));
    self::assertSame([
      'defaultLangcode' => 'en',
      'languages' => [['langcode' => 'en', 'label' => 'English', 'direction' => 'ltr']],
    ], $data);
    $this->assertDataCompliesWithApiSpecification($data, 'InterfaceTranslationLanguages');

    $this->enableModules(['language']);
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('ar')->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('system.site')->set('default_langcode', 'fr')->save();
    $data = static::decodeResponse($this->request(Request::create(self::URL)));
    // In the site's order, without the locked languages.
    self::assertSame([
      'defaultLangcode' => 'fr',
      'languages' => [
        ['langcode' => 'ar', 'label' => 'Arabic', 'direction' => 'rtl'],
        ['langcode' => 'en', 'label' => 'English', 'direction' => 'ltr'],
        ['langcode' => 'fr', 'label' => 'French', 'direction' => 'ltr'],
      ],
    ], $data);
    $this->assertDataCompliesWithApiSpecification($data, 'InterfaceTranslationLanguages');
  }

  public function testLookup(): void {
    $this->enableModules(['language', 'locale']);
    $this->installConfig(['language', 'locale']);
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $storage = $this->container->get(StringStorageInterface::class);
    \assert($storage instanceof StringStorageInterface);
    $translate = static function (string $source, string $context, string $translation, string $langcode = 'fr') use ($storage): void {
      $string = $storage->findString(['source' => $source, 'context' => $context])
        ?? $storage->createString(['source' => $source, 'context' => $context])->save()->addLocation('path', '/used-here')->save();
      $storage->createTranslation(['lid' => $string->getId(), 'language' => $langcode, 'translation' => $translation])->save();
    };
    $translate('Hello @name', '', 'Bonjour @name');
    $translate('May', 'Long month name', 'mai');
    $translate("1 file\x03@count files", '', "1 fichier\x03@count fichiers");
    // Untranslated.
    $storage->createString(['source' => 'Registered', 'context' => ''])->save();
    $this->setSetting('locale_custom_strings_fr', ['' => ['Read more' => 'Lire la suite']]);
    $this->container->get(PluralFormulaInterface::class)->setPluralFormula('fr', 2, [0 => 0, 1 => 0, 'default' => 1]);
    $database = $this->container->get(Connection::class);
    \assert($database instanceof Connection);
    // Registered by an earlier version of Drupal.
    $database->update('locales_source')->fields(['version' => '1.0'])->execute();
    $rows = static fn (): array => \array_map(
      static fn (string $table): array => $database->select($table)->fields($table)->execute()?->fetchAll(FetchAs::Associative) ?? [],
      ['locales_source', 'locales_target', 'locales_location'],
    );
    $stored = $rows();

    [$status, $data] = $this->lookup('fr', [
      ['source' => 'Hello @name'],
      ['source' => 'May', 'context' => 'Long month name'],
      ['source' => '1 file', 'plural' => '@count files'],
      ['source' => 'Read more'],
      ['source' => 'Registered'],
      ['source' => 'Unknown'],
      ['source' => 'May'],
    ]);
    self::assertSame(200, $status);
    self::assertSame([
      'strings' => [
        '' => [
          'Hello @name' => 'Bonjour @name',
          "1 file\x03@count files" => "1 fichier\x03@count fichiers",
          'Read more' => 'Lire la suite',
        ],
        'Long month name' => ['May' => 'mai'],
      ],
      'pluralFormula' => [0 => 0, 1 => 0, 'default' => 1],
    ], $data);
    $this->assertDataCompliesWithApiSpecification($data, 'InterfaceTranslations');
    // Lookups only read: they never register strings, nor update the version
    // of the strings they find.
    self::assertSame($stored, $rows());
    self::assertSame('1.0', $storage->findString(['source' => 'Hello @name'])?->getVersion());

    // Without translated plural forms, there is no plural formula.
    [$status, $data] = $this->lookup('fr', [['source' => 'Unknown']]);
    self::assertSame([200, ['strings' => []]], [$status, $data]);
    self::assertSame('{"strings":{}}', $this->getRawContent());

    // Locale translates into English only when it is configured to, while the
    // site's custom strings always take precedence.
    $translate('Hello @name', '', 'Hi @name', 'en');
    $translate('Read more', '', 'More', 'en');
    $this->setSetting('locale_custom_strings_en', ['' => ['Read more' => 'Continue reading']]);
    $english = [['source' => 'Hello @name'], ['source' => 'Read more']];
    self::assertFalse($this->config('locale.settings')->get('translate_english'));
    self::assertSame([200, ['strings' => ['' => ['Read more' => 'Continue reading']]]], $this->lookup('en', $english));
    $this->config('locale.settings')->set('translate_english', TRUE)->save();
    self::assertSame([200, ['strings' => ['' => ['Hello @name' => 'Hi @name', 'Read more' => 'Continue reading']]]], $this->lookup('en', $english));
  }

  public function testInvalidLookups(): void {
    $this->enableModules(['language']);
    $this->installConfig(['language']);
    self::assertSame(404, $this->lookup('fr', [])[0]);
    // Locked languages are not interface languages.
    self::assertSame(404, $this->lookup('und', [])[0]);
    // The rules of stored translatable strings, whatever the OpenAPI
    // validation of requests.
    foreach ([
      NULL,
      'Hello',
      ['source' => 'Hello'],
      ['Hello'],
      [[]],
      [['source' => 1]],
      [['context' => 'Greeting']],
      [['source' => 'Hello', 'context' => ['Greeting']]],
      [['source' => 'Hello', 'lid' => '1']],
      [['source' => '']],
      [['source' => "One\x03Two"]],
      [['source' => '1 item', 'plural' => '']],
      [['source' => '1 item', 'plural' => "@count\x03items"]],
      [['source' => 'May', 'context' => '']],
      [['source' => 'May', 'context' => 'Month – long']],
      [['source' => 'May', 'context' => \str_repeat('a', 256)]],
    ] as $strings) {
      self::assertSame(422, $this->lookup('en', $strings, FALSE)[0], \var_export($strings, TRUE));
    }
    self::assertSame(200, $this->lookup('en', [['source' => 'May', 'context' => \str_repeat('a', 255)]])[0]);
    self::assertSame(422, $this->lookup('en', \array_fill(0, ApiInterfaceTranslationController::MAX_STRINGS + 1, ['source' => 'Hello']), FALSE)[0]);
    self::assertSame(200, $this->lookup('en', \array_fill(0, ApiInterfaceTranslationController::MAX_STRINGS, ['source' => 'Hello']))[0]);
  }

  public function testAccess(): void {
    // The scopes that Canvas Workbench authenticates with, each on its own.
    foreach ([
      JavaScriptComponent::ADMIN_PERMISSION,
      ContentTemplate::ADMIN_PERMISSION,
      PageVariant::ADMIN_PERMISSION,
    ] as $permission) {
      $this->setUpCurrentUser([], [$permission]);
      self::assertSame(200, $this->languagesStatus(), $permission);
      self::assertSame(200, $this->lookup('en', [])[0], $permission);
    }

    $this->setUpCurrentUser([], ['access content']);
    self::assertSame(403, $this->languagesStatus());
    self::assertSame(403, $this->lookup('en', [])[0]);

    // Translations of interface text that pages do not show are not public.
    $this->setUpCurrentUser();
    self::assertSame(403, $this->languagesStatus());
    self::assertSame(403, $this->lookup('en', [])[0]);
  }

}
