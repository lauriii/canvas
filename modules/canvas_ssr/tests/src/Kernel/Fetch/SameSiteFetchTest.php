<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\Fetch;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\GlobalImports;
use Drupal\content_translation\BundleTranslationSettingsInterface;
use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\LanguageNegotiatorInterface;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationBrowser;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Tests the same-site fetch's subrequests: cacheability, user and headers.
 *
 * The two multilingual tests set up language negotiation differently, so each
 * has its own method.
 *
 * @legacy-covers \Drupal\canvas_ssr\Fetch\SameSiteFetch
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class SameSiteFetchTest extends CanvasSsrKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'serialization',
    'jsonapi',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * Tests what a fetch adds to the island's cacheability.
   *
   * The scenarios read whole collections, so they share one article and one
   * page.
   */
  public function testFetchBubblesCacheabilityOfWhatItRead(): void {
    $this->setUpCurrentUser([], ['access content']);

    $article = Node::create([
      'type' => 'article',
      'title' => 'Fetched Article',
      'status' => 1,
    ]);
    $article->save();
    $page = Page::create([
      'title' => 'Fetched Page',
      'status' => 1,
    ]);
    $page->save();

    $article_reader = $this->fetchingComponent('article reader', '/jsonapi/node/article');
    $page_reader = $this->fetchingComponent('canvas page reader', '/jsonapi/canvas_page/canvas_page');

    $this->assertScenarios([
      // Keep this first, so the swr shim has fetched nothing yet.
      //
      // The swr shim keeps what it fetched in module state, which the realm
      // keeps across islands. Without a reset per island, a later island
      // would read what an earlier one fetched, skip the fetch and bubble no
      // tags. The framework renderer resets it for each island.
      //
      // The second island is another component's, with the same key. An
      // island of the same component takes on the first's tags anyway, in
      // case the component's own modules kept what they fetched.
      'every island re-fetches, so each carries the tags, not just the first' => function () use ($article, $article_reader): void {
        $first = new CacheableMetadata();
        $first_output = $this->renderComponent($article_reader, cacheability: $first);
        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $first_output);
        self::assertContains('node:' . $article->id(), $first->getCacheTags());

        $second = new CacheableMetadata();
        $same_key_reader = $this->fetchingComponent('same key reader', '/jsonapi/node/article');
        $second_output = $this->renderComponent($same_key_reader, cacheability: $second);
        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $second_output);
        self::assertContains(
          'node:' . $article->id(),
          $second->getCacheTags(),
          'The second island must fetch again, and bubble the node tag.',
        );
      },

      'a fetched node bubbles its own tag and the list it came from' => function () use ($article, $article_reader): void {
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($article_reader, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('Fetched Article', $this->extractSsrMarkup($output));

        $cache_tags = $cacheability->getCacheTags();
        self::assertContains('node:' . $article->id(), $cache_tags);
        self::assertContains('node_list', $cache_tags);
      },

      'a fetched Canvas page bubbles its own tag and its list' => function () use ($page, $page_reader): void {
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($page_reader, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('Fetched Page', $this->extractSsrMarkup($output));

        $cache_tags = $cacheability->getCacheTags();
        self::assertContains('canvas_page:' . $page->id(), $cache_tags);
        self::assertContains('canvas_page_list', $cache_tags);
      },

      'two fetches in one render accumulate both sets of tags' => function () use ($article, $page): void {
        $component_source = <<<'JS'
        import { h } from "preact";
        import useSWR from "swr";
        export default function Root() {
          const { data: articlesData } = useSWR(
            "/jsonapi/node/article",
            (url) => fetch(url).then((r) => r.json()),
            { fallbackData: { data: [] } }
          );
          const { data: pagesData } = useSWR(
            "/jsonapi/canvas_page/canvas_page",
            (url) => fetch(url).then((r) => r.json()),
            { fallbackData: { data: [] } }
          );
          const articles = Array.isArray(articlesData) ? articlesData : articlesData?.data;
          const pages = Array.isArray(pagesData) ? pagesData : pagesData?.data;
          const articleTitle = articles?.[0]?.title || articles?.[0]?.attributes?.title || "none";
          const pageTitle = pages?.[0]?.title || pages?.[0]?.attributes?.title || "none";
          return h("div", null, `${articleTitle} | ${pageTitle}`);
        }
        JS;
        $root = $this->createComponent(
          'multi fetch cache tag test',
          $component_source,
          data_dependencies: ['drupalSettings' => ['v0.jsonapiSettings']],
        );

        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($root, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('Fetched Article | Fetched Page', $this->extractSsrMarkup($output));

        $cache_tags = $cacheability->getCacheTags();
        self::assertContains('node:' . $article->id(), $cache_tags);
        self::assertContains('canvas_page:' . $page->id(), $cache_tags);
      },

      // Otherwise markup one user may see could be served to everyone.
      'the contexts JSON:API varied by bubble alongside the tags' => function () use ($article_reader): void {
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($article_reader, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertContains('user.permissions', $cacheability->getCacheContexts());
      },
    ]);
  }

  /**
   * Tests a fetch reads what the current user may read, and no more.
   *
   * The subrequest carries the current request's session, so it runs as the
   * current user. An unpublished article is in the collection only for a
   * user who may see it.
   */
  public function testFetchRunsAsTheCurrentUser(): void {
    Node::create([
      'type' => 'article',
      'title' => 'Unpublished Article',
      'status' => 0,
    ])->save();
    $reader = $this->fetchingComponent('unpublished article reader', '/jsonapi/node/article');

    $this->setUpCurrentUser([], ['access content']);
    $output = $this->renderComponent($reader);
    self::assertSame('<div>no-data</div>', $this->extractSsrMarkup($output));

    $this->setUpCurrentUser([], ['access content', 'bypass node access']);
    $output = $this->renderComponent($reader);
    self::assertSame('<div>Unpublished Article</div>', $this->extractSsrMarkup($output));
  }

  /**
   * Tests fetch() request headers are forwarded to the subrequest.
   *
   * With browser negotiation for content language, JSON:API returns the
   * translation named by Accept-Language. So a fetch sending "es" should get
   * the Spanish title.
   *
   * The language manager keeps what it negotiates, whichever request asked
   * first. So the subrequest negotiates again, though the current request
   * already had, and the current request does after, so the rest of the
   * page isn't rendered in the subrequest's language.
   *
   * Kernel tests have no main request, so this wires up the negotiator the
   * way LanguageRequestSubscriber would.
   *
   * The headers may be given as fetch() takes them: a record, a Headers
   * object or a list of pairs.
   */
  #[TestWith(['{ "Accept-Language": "es" }'])]
  #[TestWith(['new Headers({ "Accept-Language": "es" })'])]
  #[TestWith(['[["Accept-Language", "es"]]'])]
  public function testFetchForwardsAcceptLanguageHeaderToSubrequest(string $headers): void {
    $this->installLanguages();

    // Negotiate content language from Accept-Language.
    $this->config('language.types')
      ->set('configurable', ['language_interface', 'language_content'])
      ->set('negotiation.language_content.enabled', [LanguageNegotiationBrowser::METHOD_ID => 0])
      ->save();

    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $negotiator = $this->container->get(LanguageNegotiatorInterface::class);
    \assert($negotiator instanceof LanguageNegotiatorInterface);
    $negotiator->setCurrentUser($this->container->get(AccountProxyInterface::class));
    $language_manager->setNegotiator($negotiator);
    $language_manager->reset();

    $this->setUpCurrentUser([], ['access content']);
    self::createTranslatedArticle();

    $component_source = <<<JS
    import { h } from "preact";
    import useSWR from "swr";
    export default function Root() {
      const { data } = useSWR(
        "/jsonapi/node/article",
        (url) => fetch(url, { headers: $headers }).then((r) => r.json()),
      );
      const articles = Array.isArray(data) ? data : data?.data;
      if (!articles || articles.length === 0) {
        return h("div", null, "no-data");
      }
      return h("div", null, articles[0].attributes?.title || articles[0].title);
    }
    JS;
    $root = $this->createComponent(
      'accept-language forwarding consumer',
      $component_source,
      data_dependencies: ['drupalSettings' => ['v0.jsonapiSettings']],
    );

    $this->assertFetchNegotiatesItsOwnLanguage($root);
  }

  /**
   * Tests a response in the visitor's language is cached for that language.
   *
   * A URL without a language prefix can leave the language to the visitor's
   * browser. The island is on a page in the page's language, whatever the
   * visitor's, so its markup would otherwise be cached for every visitor
   * to the page.
   */
  public function testFetchInTheBrowsersLanguageVariesByIt(): void {
    $this->installLanguages();
    $this->config('language.negotiation')
      ->set('url.source', LanguageNegotiationUrl::CONFIG_PATH_PREFIX)
      ->set('url.prefixes', ['en' => '', 'es' => 'es'])
      ->save();
    $methods = [LanguageNegotiationUrl::METHOD_ID => -8, LanguageNegotiationBrowser::METHOD_ID => -5];
    $this->config('language.types')
      ->set('configurable', ['language_interface', 'language_content'])
      ->set('negotiation.language_interface.enabled', $methods)
      ->set('negotiation.language_content.enabled', $methods)
      ->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->setUpCurrentUser([], ['access content']);
    self::createTranslatedArticle();

    $unprefixed = $this->fetchingComponent('unprefixed consumer', '/jsonapi/node/article');
    $prefixed = $this->fetchingComponent('prefixed consumer', '/es/jsonapi/node/article');
    $render = function (JavaScriptComponent $component, string $accept_language, ?CacheableMetadata &$cacheability = NULL): string {
      $this->visit('/es/node/1', $accept_language);
      $build = [
        '#cache' => ['keys' => ['canvas_ssr_test', $component->id()]],
        'island' => [
          '#type' => 'astro_island',
          '#uuid' => NULL,
          '#name' => (string) $component->label(),
          '#machine_name' => $component->id(),
          '#component_url' => '/canvas/api/v0/auto-saves/js/js_component/' . $component->id(),
          '#props' => [],
          '#slots' => [],
          '#framework' => 'preact',
          '#import_maps' => $this->container->get(GlobalImports::class)->getImportMap(),
        ],
      ];
      $renderer = $this->container->get('renderer');
      \assert($renderer instanceof RendererInterface);
      $output = (string) $renderer->renderInIsolation($build);
      $cacheability = CacheableMetadata::createFromRenderArray($build);

      return $this->extractSsrMarkup($output);
    };

    // The page is Spanish for both visitors.
    self::assertSame('<div>English Title</div>', $render($unprefixed, 'en', $cacheability));
    self::assertContains('headers:Accept-Language', $cacheability->getCacheContexts());
    self::assertSame('<div>Titulo en espanol</div>', $render($unprefixed, 'es'));
    self::assertSame('<div>English Title</div>', $render($unprefixed, 'en'));

    // A prefix decides the language for every visitor.
    self::assertSame('<div>Titulo en espanol</div>', $render($prefixed, 'en', $cacheability));
    self::assertNotContains('headers:Accept-Language', $cacheability->getCacheContexts());
  }

  /**
   * Starts a request as a visitor, with the browser's languages.
   *
   * @param string $path
   *   The path.
   * @param string $accept_language
   *   The Accept-Language header.
   */
  private function visit(string $path, string $accept_language): void {
    $stack = $this->container->get(RequestStack::class);
    \assert($stack instanceof RequestStack);
    $session = $stack->getCurrentRequest()?->getSession();
    \assert($session instanceof SessionInterface);
    while ($stack->getCurrentRequest() !== $stack->getMainRequest()) {
      $stack->pop();
    }
    $request = Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => $accept_language]);
    $request->setSession($session);
    $stack->push($request);
    // A PHP request negotiates afresh.
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $negotiator = $this->container->get(LanguageNegotiatorInterface::class);
    \assert($negotiator instanceof LanguageNegotiatorInterface);
    $negotiator->setCurrentUser($this->container->get(AccountProxyInterface::class));
    $language_manager->setNegotiator($negotiator);
    $language_manager->reset();
    $negotiator->reset();
  }

  /**
   * Tests a language-prefixed JSON:API URL is allowlisted and localized.
   *
   * With path prefix negotiation, a component may fetch "/es/jsonapi/...".
   * The path policies must check the system path ("/jsonapi/..."), but the
   * subrequest must keep the prefix so Spanish is negotiated.
   */
  public function testFetchWithLanguagePrefixReturnsTranslatedResults(): void {
    $this->installLanguages();

    // Negotiate from the path prefix. The container is rebuilt below so the
    // language module registers the path processor that strips "/es".
    $this->config('language.negotiation')
      ->set('url.source', LanguageNegotiationUrl::CONFIG_PATH_PREFIX)
      ->set('url.prefixes', ['en' => '', 'es' => 'es'])
      ->save();
    // JSON:API uses the content language, so negotiate that from the prefix
    // too.
    $this->config('language.types')
      ->set('configurable', ['language_interface', 'language_content'])
      ->set('negotiation.language_content.enabled', [LanguageNegotiationUrl::METHOD_ID => 0])
      ->save();
    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], ['access content']);
    self::createTranslatedArticle();

    $component_source = <<<'JS'
    import { h } from "preact";
    import useSWR from "swr";
    export default function Root() {
      const { data } = useSWR(
        "/es/jsonapi/node/article",
        (url) => fetch(url).then((r) => r.json()),
      );
      const articles = Array.isArray(data) ? data : data?.data;
      if (!articles || articles.length === 0) {
        return h("div", null, "no-data");
      }
      return h("div", null, articles[0].attributes?.title || articles[0].title);
    }
    JS;
    $root = $this->createComponent(
      'language prefix consumer',
      $component_source,
      data_dependencies: ['drupalSettings' => ['v0.jsonapiSettings']],
    );

    $this->assertFetchNegotiatesItsOwnLanguage($root);
  }

  /**
   * Checks a fetch gets Spanish, and the current request stays English.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $root
   *   A component that renders the title of the article it fetches in
   *   Spanish.
   */
  private function assertFetchNegotiatesItsOwnLanguage(JavaScriptComponent $root): void {
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $types = [LanguageInterface::TYPE_INTERFACE, LanguageInterface::TYPE_CONTENT];
    $negotiated = static fn(): array => \array_map(
      static fn(string $type): string => $language_manager->getCurrentLanguage($type)->getId(),
      $types,
    );
    // The current request has negotiated before the island renders, as when
    // something before it on the page asked.
    self::assertSame(['en', 'en'], $negotiated());

    $output = $this->renderComponent($root);
    self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
    self::assertSame('<div>Titulo en espanol</div>', $this->extractSsrMarkup($output));
    self::assertSame(['en', 'en'], $negotiated());

    // And when nothing had asked before the island, so the subrequest was
    // the first to.
    $language_manager->reset();
    $this->container->get('cache.render')->deleteAll();
    $output = $this->renderComponent($root);
    self::assertSame('<div>Titulo en espanol</div>', $this->extractSsrMarkup($output));
    self::assertSame(['en', 'en'], $negotiated());
  }

  /**
   * Builds a component that renders a JSON:API collection's first title.
   *
   * @param string $label
   *   The component's label.
   * @param string $url
   *   The collection URL.
   */
  private function fetchingComponent(string $label, string $url): JavaScriptComponent {
    $component_source = \sprintf(<<<'JS'
    import { h } from "preact";
    import useSWR from "swr";
    export default function Root() {
      const { data } = useSWR(
        %s,
        (url) => fetch(url).then((r) => r.json()),
        { fallbackData: "unused" }
      );
      const items = Array.isArray(data) ? data : data?.data;
      if (!items || items.length === 0) {
        return h("div", null, "no-data");
      }
      return h("div", null, items[0].title || items[0].attributes?.title);
    }
    JS, \json_encode($url, JSON_THROW_ON_ERROR));

    return $this->createComponent(
      $label,
      $component_source,
      data_dependencies: ['drupalSettings' => ['v0.jsonapiSettings']],
    );
  }

  /**
   * Makes the site multilingual with translatable articles.
   */
  private function installLanguages(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    \assert($module_installer instanceof ModuleInstallerInterface);
    $module_installer->install(['language', 'content_translation']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $content_translation_manager = $this->container->get(BundleTranslationSettingsInterface::class);
    \assert($content_translation_manager instanceof ContentTranslationManagerInterface);
    $content_translation_manager->setEnabled('node', 'article', TRUE);
  }

  /**
   * Creates an article with English and Spanish titles.
   */
  private static function createTranslatedArticle(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'English Title',
      'status' => 1,
    ]);
    $node->addTranslation('es', ['title' => 'Titulo en espanol']);
    $node->save();
  }

}
