<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\DrupalSettings;

// cspell:ignore czytaj plik pliki plików tylko witaj więcej
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\locale\PluralFormulaInterface;
use Drupal\Tests\canvas_ssr\CollectingLogger;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\canvas_ssr\Kernel\ChunkedLibraryProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that an island gets the interface translations of its graph.
 *
 * Canvas gives a client-rendered island the translations of the strings its
 * graph's components pass to `t()` and `formatPlural()`, for
 * `useTranslation()`, without a declaration. Without them, SSR would render
 * the source strings, and hydration would change them.
 *
 * @legacy-covers \Drupal\canvas_ssr\DrupalSettings\CanvasInterfaceTranslations
 * @legacy-covers \Drupal\canvas_ssr\DrupalSettings\GraphDrupalSettings
 * @legacy-covers \Drupal\canvas_ssr\DrupalSettings\DrupalSettingsResolver
 *
 * @see \Drupal\canvas\CodeComponentDataProvider::getCanvasDataTranslationsV0()
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class InterfaceTranslationsTest extends CanvasSsrKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas_ssr', 'language', 'locale'];

  /**
   * Polish, which has three plural forms.
   */
  private const string LANGCODE = 'pl';

  /**
   * Collects what canvas_ssr logs.
   */
  private CollectingLogger $logger;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // For a module that reads the translations as it evaluates.
    $container
      ->register(ChunkedLibraryProvider::class, ChunkedLibraryProvider::class)
      ->addTag('canvas_ssr.js_module_provider', ['priority' => 200]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (!\in_array('getCanvasDataTranslationsV0', \get_class_methods(CodeComponentDataProvider::class), TRUE)) {
      $this->markTestSkipped('This Canvas gives no interface translations.');
    }
    $this->installRunner();
    // Canvas registers a component's strings with Locale as it's saved.
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location']);
    $this->logger = new CollectingLogger();
    $factory = $this->container->get(LoggerChannelFactoryInterface::class);
    \assert($factory instanceof LoggerChannelFactoryInterface);
    $factory->addLogger($this->logger);

    // Every translation is set up front: Locale caches a lookup, even of a
    // string it doesn't translate, for the rest of the request.
    $this->setSetting('locale_custom_strings_' . self::LANGCODE, [
      '' => [
        'Hello @name' => 'Witaj @name',
        'Read more' => 'Czytaj więcej',
        'Only theirs' => 'Tylko ich',
        "1 file\x03@count files" => "1 plik\x03@count pliki\x03@count plików",
      ],
    ]);
    ConfigurableLanguage::createFromLangcode(self::LANGCODE)->save();
    $this->config('system.site')->set('default_langcode', self::LANGCODE)->save();
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof LanguageManagerInterface);
    $language_manager->reset();
    $translation_manager = $this->container->get(TranslationInterface::class);
    \assert($translation_manager instanceof TranslationManager);
    $translation_manager->setDefaultLangcode(self::LANGCODE);
    $plural_formula = $this->container->get(PluralFormulaInterface::class);
    \assert($plural_formula instanceof PluralFormulaInterface);
    $plural_formula->setPluralFormula(self::LANGCODE, 3, self::polishPluralFormula());
  }

  /**
   * Tests what an island's translations render, and what they bubble.
   */
  public function testInterfaceTranslations(): void {
    $this->assertScenarios([
      'a translated string renders translated, with its cacheability' => function (): void {
        $component = $this->translating('greeter', 't("Hello @name", { "@name": "Canvas" })', [['source' => 'Hello @name']]);

        self::assertSame('<p>Witaj Canvas</p>', $this->ssrMarkup($component, $cacheability));
        self::assertContains('languages:language_interface', $cacheability->getCacheContexts());
        self::assertContains('locale', $cacheability->getCacheTags());
      },

      "a plural renders by the language's formula" => function (): void {
        $component = $this->translating(
          'file_counter',
          '[1, 2, 5].map((count) => formatPlural(count, "1 file", "@count files")).join(", ")',
          [['source' => '1 file', 'plural' => '@count files']],
        );

        self::assertSame('<p>1 plik, 2 pliki, 5 plików</p>', $this->ssrMarkup($component));
      },

      // The cacheability still counts: a translation added later must
      // invalidate the island.
      'an untranslated string renders as written' => function (): void {
        $component = $this->translating('untranslated', 't("Not translated")', [['source' => 'Not translated']]);

        self::assertSame('<p>Not translated</p>', $this->ssrMarkup($component, $cacheability));
        self::assertContains('languages:language_interface', $cacheability->getCacheContexts());
        self::assertContains('locale', $cacheability->getCacheTags());
      },

      "a dependency's strings count for the whole graph" => function (): void {
        $this->translating('reader', 't("Read more")', [['source' => 'Read more']]);
        $root = $this->createComponent('reader_root', <<<'JS'
          import { h } from "preact";
          import Reader from "@/components/reader";
          export default function C() {
            return h("div", null, h(Reader, null));
          }
          JS, imports: ['reader']);

        self::assertSame('<div><p>Czytaj więcej</p></div>', $this->ssrMarkup($root));
      },

      // In the browser, the second island's t() gets only its own graph's
      // translations, whatever islands rendered before it.
      "an island gets no other graph's translations" => function (): void {
        $theirs = $this->translating('theirs', 't("Only theirs")', [['source' => 'Only theirs']]);
        $mine = $this->translating('mine', 't("Only theirs") + "|" + t("Hello @name", { "@name": "me" })', [['source' => 'Hello @name']]);

        self::assertSame('<p>Tylko ich</p>', $this->ssrMarkup($theirs));
        self::assertSame('<p>Only theirs|Witaj me</p>', $this->ssrMarkup($mine));
      },

      'a graph without strings gets no translations and carries none of their cacheability' => function (): void {
        $component = $this->createComponent('stringless', 'import { h } from "preact"; export default function C() { return h("p", null, typeof drupalSettings); }');

        self::assertSame('<p>undefined</p>', $this->ssrMarkup($component, $cacheability));
        // Not the interface language context, which every render carries
        // with the language module on.
        self::assertNotContains('locale', $cacheability->getCacheTags());
      },

      "a preview island gets its draft's translations" => function (): void {
        $this->translating('drafted', 't("Read more")', [['source' => 'Read more']]);
        $draft = JavaScriptComponent::load('drafted');
        \assert($draft instanceof JavaScriptComponent);
        $draft_source = self::source('t("Hello @name", { "@name": "draft" })');
        $draft->set('js', ['original' => $draft_source, 'compiled' => $draft_source]);
        $draft->set('translatableStrings', [['source' => 'Hello @name']]);
        $auto_save_manager = $this->container->get(AutoSaveManager::class);
        \assert($auto_save_manager instanceof AutoSaveManager);
        $auto_save_manager->saveEntity($draft);

        $output = $this->renderComponent($draft, preview: TRUE);
        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertSame('<p>Witaj draft</p>', $this->extractSsrMarkup($output));
      },

      // It could keep them for islands of other graphs.
      'reading them while a module imported during the render evaluates falls back to CSR' => function (): void {
        $component = $this->createComponent('translations_importer', <<<'JS'
          import { h } from "preact";
          import { useTranslation } from "drupal-canvas/react";
          let read = null;
          export default function C() {
            const { t } = useTranslation();
            if (read === null) {
              throw import(String("@canvas-ssr-test/translations-reader")).then((module) => { read = module.read; });
            }
            return h("p", null, `${t("Read more")}:${read}`);
          }
          JS, machine_name: 'translations_importer');
        $component->set('translatableStrings', [['source' => 'Read more']])->save();

        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $this->renderComponent($component));
        $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
        self::assertNotSame([], $warnings);
        self::assertStringContainsString('the translations_importer component on the client, since it read page data or interface translations while a module it imported during the render evaluated', (string) \end($warnings));
      },
    ]);
  }

  /**
   * Creates a component that renders translated text in a paragraph.
   *
   * @param string $machine_name
   *   The machine name.
   * @param string $expression
   *   What it renders, with `t()` and `formatPlural()` in scope.
   * @param list<array{source: string, plural?: string, context?: string}> $strings
   *   The strings the code editor would have found in it.
   *
   * @return \Drupal\canvas\Entity\JavaScriptComponent
   *   The component.
   */
  private function translating(string $machine_name, string $expression, array $strings): JavaScriptComponent {
    $component = $this->createComponent($machine_name, self::source($expression), machine_name: $machine_name);
    // As the code editor saves them, alongside the source.
    $component->set('translatableStrings', $strings)->save();

    return $component;
  }

  /**
   * Returns the source of a component that renders an expression.
   *
   * @param string $expression
   *   What it renders, with `t()` and `formatPlural()` in scope.
   *
   * @return string
   *   The source.
   */
  private static function source(string $expression): string {
    return <<<JS
      import { h } from "preact";
      import { useTranslation } from "drupal-canvas/react";
      export default function C() {
        const { t, formatPlural } = useTranslation();
        return h("p", null, $expression);
      }
      JS;
  }

  /**
   * Renders a component that must render on the server.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $component
   *   The component.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Set to the island's cacheability.
   *
   * @param-out \Drupal\Core\Cache\CacheableMetadata $cacheability
   *
   * @return string
   *   The island's server-rendered markup.
   */
  private function ssrMarkup(JavaScriptComponent $component, ?CacheableMetadata &$cacheability = NULL): string {
    $output = $this->renderComponent($component, cacheability: $cacheability);
    self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);

    return $this->extractSsrMarkup($output);
  }

  /**
   * Returns Polish's plural formula, as Locale stores it.
   *
   * @return array<int|string, int>
   *   The plural form for each count up to 199, and a default.
   */
  private static function polishPluralFormula(): array {
    $formula = ['default' => 2];
    foreach (\range(0, 199) as $n) {
      $formula[$n] = match (TRUE) {
        $n === 1 => 0,
        $n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) => 1,
        default => 2,
      };
    }

    return $formula;
  }

}
