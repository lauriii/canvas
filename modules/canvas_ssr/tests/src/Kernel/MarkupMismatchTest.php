<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

// cspell:ignore cimg
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Tests\canvas_ssr\CollectingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests markup that would parse differently in the browser falls back to CSR.
 *
 * In the browser, Preact sets the content of a script, style and the like as
 * text, so it's inert. Server-rendered, the HTML parser reads it, and it can
 * end the element early. A content editor's prop could then add markup to a
 * component that's safe in the browser.
 *
 * @see js/renderers/preact.js
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_ssr')]
#[Group('canvas_ssr_requires_runner')]
final class MarkupMismatchTest extends CanvasSsrKernelTestBase {

  /**
   * A prop a content editor could set, to end a script and add an image.
   */
  private const BREAKOUT = '</script><img src=x onerror=alert(1)>';

  /**
   * Collects what canvas_ssr logs.
   */
  private CollectingLogger $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
    $this->logger = new CollectingLogger();
    $factory = $this->container->get(LoggerChannelFactoryInterface::class);
    \assert($factory instanceof LoggerChannelFactoryInterface);
    $factory->addLogger($this->logger);
  }

  /**
   * Tests what renders on the server and what falls back.
   */
  public function testMismatches(): void {
    $this->assertScenarios([
      // The common JSON-LD pattern, which JSON.stringify() leaves open.
      'a JSON-LD script a prop ends early falls back to CSR, with a warning' => function (): void {
        $component = $this->component('json_ld', "h('script', { type: 'application/ld+json', dangerouslySetInnerHTML: { __html: JSON.stringify({ name: props.name }) } })");

        $output = $this->renderComponent($component, props: ['name' => self::BREAKOUT], cacheability: $cacheability);
        self::assertStringContainsString('client="only"', $output);
        self::assertStringNotContainsString('<img', $output);
        // It would recur with the same props.
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('json_ld', $warnings[0]);
        self::assertStringContainsString('"</script"', $warnings[0]);

        $output = $this->renderComponent($component, props: ['name' => 'Plain']);
        self::assertSame('<script type="application/ld+json">{"name":"Plain"}</script>', $this->extractSsrMarkup($output));
      },

      'a JSON-LD script that escapes "<" renders on the server' => function (): void {
        $component = $this->component('escaped_json_ld', "h('script', { type: 'application/ld+json', dangerouslySetInnerHTML: { __html: JSON.stringify({ name: props.name }).replace(/</g, '\\\\u003c') } })");

        $output = $this->renderComponent($component, props: ['name' => self::BREAKOUT]);
        self::assertSame('<script type="application/ld+json">{"name":"\u003c/script>\u003cimg src=x onerror=alert(1)>"}</script>', $this->extractSsrMarkup($output));
      },

      // The parser ignores case, in the tag name and in the end tag.
      'any case of the element or its end tag counts' => function (): void {
        $component = $this->component('upper_style', "h('STYLE', { dangerouslySetInnerHTML: { __html: props.name } })");

        $output = $this->renderComponent($component, props: ['name' => '</Style><img src=x onerror=alert(1)>']);
        self::assertStringContainsString('client="only"', $output);
      },

      // Every element whose content the parser reads as text.
      'every such element is checked' => function (): void {
        foreach (['script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'textarea', 'title'] as $name) {
          $component = $this->component("raw_$name", "h('$name', { dangerouslySetInnerHTML: { __html: props.name } })");
          $output = $this->renderComponent($component, props: ['name' => "</$name><img src=x onerror=alert(1)>"]);
          self::assertStringContainsString('client="only"', $output, $name);
        }
      },

      'a script comment that keeps the element open falls back to CSR' => function (): void {
        $component = $this->component('script_comment', "h('script', { type: 'application/ld+json', dangerouslySetInnerHTML: { __html: props.name } })");

        $output = $this->renderComponent($component, props: ['name' => '"<!--<script>"']);
        self::assertStringContainsString('client="only"', $output);
        // A comment alone can't.
        $output = $this->renderComponent($component, props: ['name' => '"<!-- -->"']);
        self::assertSame('<script type="application/ld+json">"<!-- -->"</script>', $this->extractSsrMarkup($output));
      },

      'plaintext never ends, so it falls back to CSR' => function (): void {
        $component = $this->component('plaintext', "h('plaintext', null, 'text')");

        self::assertStringContainsString('client="only"', $this->renderComponent($component));
      },

      // Server rendering escapes it, and style doesn't decode what it
      // escapes, so quoted font names would break.
      'style text that server rendering would escape falls back to CSR' => function (): void {
        $component = $this->component('style_text', "h('style', null, props.name)");

        $output = $this->renderComponent($component, props: ['name' => '.a { font-family: "Inter"; }']);
        self::assertStringContainsString('client="only"', $output);
        $output = $this->renderComponent($component, props: ['name' => '.a { color: red; }']);
        self::assertSame('<style>.a { color: red; }</style>', $this->extractSsrMarkup($output));
      },

      // A textarea decodes what server rendering escapes, so its text
      // shows as it would in the browser. An element can't be its child.
      'textarea text renders, an element in it falls back to CSR' => function (): void {
        $text = $this->component('textarea_text', "h('textarea', null, props.name)");
        $output = $this->renderComponent($text, props: ['name' => '</textarea><b>"&"</b>']);
        self::assertSame('<textarea>&lt;/textarea>&lt;b>&quot;&amp;&quot;&lt;/b></textarea>', $this->extractSsrMarkup($output));

        $element = $this->component('textarea_element', "h('textarea', null, h('b', null, 'bold'))");
        self::assertStringContainsString('client="only"', $this->renderComponent($element));
      },

      // Markup there is markup in the browser too.
      'other elements are not checked' => function (): void {
        $component = $this->component('div_html', "h('div', { dangerouslySetInnerHTML: { __html: props.name } })");

        $output = $this->renderComponent($component, props: ['name' => '<b>bold</b>']);
        self::assertSame('<div><b>bold</b></div>', $this->extractSsrMarkup($output));
      },
    ]);
  }

  /**
   * Tests a style object renders as the browser would set it.
   *
   * In the browser, each property is set on its own, and one whose value
   * isn't a single declaration's sets nothing. A content editor's prop
   * could otherwise add declarations to a component that's safe there.
   */
  public function testStyleObjects(): void {
    $component = $this->component('styled', "h('div', { style: { color: props.color, width: '5px' } })");
    $render = fn (string $color): string => $this->extractSsrMarkup($this->renderComponent($component, props: ['color' => $color]));

    $this->assertScenarios([
      'a value that would add a declaration sets nothing' => function () use ($render): void {
        self::assertSame('<div style="width:5px;"></div>', $render('red;position:fixed;inset:0'));
        self::assertSame('<div style="color:red;width:5px;"></div>', $render('red'));
      },

      'a value the browser refuses sets nothing' => function () use ($render): void {
        foreach (['red !important', 'red}', 'red)', "\"a\nb\"", 'url(a"b)'] as $refused) {
          self::assertSame('<div style="width:5px;"></div>', $render($refused), $refused);
        }
      },

      'a ";" within a string, URL or brackets is part of the value' => function () use ($render): void {
        self::assertSame('<div style="color:url(&quot;data:image/png;base64,AA&quot;);width:5px;"></div>', $render('url("data:image/png;base64,AA")'));
        self::assertSame('<div style="color:url(data:image/png;base64,AA);width:5px;"></div>', $render('url(data:image/png;base64,AA)'));
        self::assertSame('<div style="color:var(--a, {b;c});width:5px;"></div>', $render('var(--a, {b;c})'));
      },

      // Left open in the attribute, it would take in the width.
      'what a value leaves open is closed, as the browser closes it' => function () use ($render): void {
        self::assertSame('<div style="color:rgb(1,2,3);width:5px;"></div>', $render('rgb(1,2,3'));
        self::assertSame('<div style="color:&quot;Inter&quot;;width:5px;"></div>', $render('"Inter'));
        self::assertSame('<div style="color:red/**/;width:5px;"></div>', $render('red/*'));
        self::assertSame('<div style="color:url(a);width:5px;"></div>', $render('url(a'));
      },

      // Left as it is, it would escape what follows it.
      'a backslash at the end is read as the browser reads it' => function () use ($render): void {
        self::assertSame('<div style="color:&quot;ab&quot;;width:5px;"></div>', $render('"ab\\'));
        self::assertSame("<div style=\"color:ab\u{FFFD};width:5px;\"></div>", $render('ab\\'));
        self::assertSame("<div style=\"color:url(ab\u{FFFD});width:5px;\"></div>", $render('url(ab\\'));
      },

      'a name that is not a property\'s sets nothing' => function (): void {
        $component = $this->component('styled_names', "h('div', { style: { [props.name]: 'blue', '--a': 'b;c', '--d': '1', backgroundColor: 'red', width: 5 } })");

        $output = $this->renderComponent($component, props: ['name' => 'color:red;background']);
        self::assertSame('<div style="--d:1;background-color:red;width:5px;"></div>', $this->extractSsrMarkup($output));
        $output = $this->renderComponent($component, props: ['name' => 'border-color']);
        self::assertSame('<div style="border-color:blue;--d:1;background-color:red;width:5px;"></div>', $this->extractSsrMarkup($output));
      },

      // That's the browser's to read, as it would in a style attribute.
      'a style given as a string renders as given' => function (): void {
        $component = $this->component('styled_string', "h('div', { style: props.name })");

        $output = $this->renderComponent($component, props: ['name' => 'color:red;width:5px']);
        self::assertSame('<div style="color:red;width:5px"></div>', $this->extractSsrMarkup($output));
      },
    ]);
  }

  /**
   * Creates a component that renders one expression.
   *
   * @param string $machine_name
   *   The component's machine name.
   * @param string $expression
   *   What it returns, which may read props.
   */
  private function component(string $machine_name, string $expression): JavaScriptComponent {
    return $this->createComponent(
      $machine_name,
      "import { h } from 'preact';\nexport default function C(props) { return $expression; }",
      machine_name: $machine_name,
    );
  }

}
