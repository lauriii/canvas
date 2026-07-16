<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests custom object props ("groups") in the component instance form.
 *
 * @see docs/adr/0021-object-props-in-code-components.md
 */
#[CoversClass(JsonSchemaPropsComponentSourceBase::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ObjectPropsComponentInstanceFormTest extends ApiLayoutControllerTestBase {

  use NodeCreationTrait;

  private const TEST_UUID = '5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('module_installer')->install(['system']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();

    // @todo Refactor this away in https://www.drupal.org/project/canvas/issues/3531679
    (new CanvasTestSetup())->setup();
    $this->setUpCurrentUser(permissions: ['edit any article content', Page::EDIT_PERMISSION]);

    JavaScriptComponent::create([
      'machineName' => 'grouped',
      'name' => 'Grouped',
      'status' => TRUE,
      'props' => [
        'ingredient' => [
          'title' => 'Ingredient',
          'type' => 'object',
          'properties' => [
            'name' => ['title' => 'Ingredient name', 'type' => 'string'],
            'amount' => ['title' => 'Amount', 'type' => 'number'],
            'unit' => ['title' => 'Unit', 'type' => 'string', 'enum' => ['g', 'ml'], 'meta:enum' => ['g' => 'Grams', 'ml' => 'Milliliters']],
          ],
          'required' => ['name'],
          'examples' => [
            ['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g'],
          ],
        ],
        'authors' => [
          'title' => 'Authors',
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'properties' => [
              'name' => ['title' => 'Author name', 'type' => 'string'],
              'link' => ['title' => 'Author link', 'type' => 'string', 'format' => 'uri'],
            ],
            'required' => ['name'],
          ],
          'examples' => [
            [
              ['name' => 'Ada', 'link' => 'https://example.com/ada'],
              ['name' => 'Grace'],
            ],
          ],
        ],
      ],
      'required' => ['ingredient'],
      'slots' => [],
      'js' => ['original' => 'console.log("t")', 'compiled' => 'console.log("t")'],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ])->save();
  }

  private static function getComponent(): Component {
    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId('grouped'));
    \assert($component instanceof Component);
    return $component;
  }

  /**
   * A single-value group renders as one labeled group of per-sub widgets.
   */
  public function testGroupedInstanceForm(): void {
    $component = self::getComponent();
    $source = $component->getComponentSource();
    \assert($source instanceof JsonSchemaPropsComponentSourceBase);

    // The client-side info exposes the composite source and the composed
    // example object.
    $client_side_info = $source->getClientSideInfo($component);
    self::assertArrayHasKey('propSources', $client_side_info);
    self::assertSame('object-props', $client_side_info['propSources']['ingredient']['sourceType']);
    self::assertSame(
      ['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g'],
      $client_side_info['propSources']['ingredient']['default_values']['resolved'],
    );
    self::assertSame(
      [
        ['name' => 'Ada', 'link' => 'https://example.com/ada'],
        ['name' => 'Grace'],
      ],
      $client_side_info['propSources']['authors']['default_values']['resolved'],
    );

    // Build the form through the HTTP endpoint, like the client does. The
    // values differ from the component's example; values equal to the
    // example are represented by a DefaultRelativeUrlPropSource instead and
    // prefill the widgets with the example (asserted further below).
    // @see ::testDefaultRelativeUrlSourceRoundTrip()
    // @see \Drupal\canvas\PropSource\DefaultRelativeUrlPropSource
    $page = Page::create(['title' => $this->randomMachineName()]);
    self::assertSame(SAVED_NEW, $page->save());
    $form_canvas_props = [
      'resolved' => [
        'ingredient' => ['name' => 'Sugar', 'amount' => 250.5, 'unit' => 'kg'],
        'authors' => [
          ['name' => 'Marie', 'link' => 'https://example.com/marie'],
          ['name' => 'Joan'],
        ],
      ],
      'source' => [
        'ingredient' => [
          'value' => ['name' => 'Sugar', 'amount' => 250.5, 'unit' => 'kg'],
        ] + \array_intersect_key($client_side_info['propSources']['ingredient'], \array_flip(['sourceType', 'sources', 'sourceTypeSettings'])),
        'authors' => [
          'value' => [
            ['name' => 'Marie', 'link' => 'https://example.com/marie'],
            ['name' => 'Joan'],
          ],
        ] + \array_intersect_key($client_side_info['propSources']['authors'], \array_flip(['sourceType', 'sources', 'sourceTypeSettings'])),
      ],
    ];
    $json = self::decodeResponse($this->request(Request::create(
      '/canvas/api/v0/form/component-instance/canvas_page/' . $page->id(),
      'PATCH',
      [
        'form_canvas_tree' => json_encode([
          'nodeType' => 'component',
          'slots' => [],
          'type' => "{$component->id()}@{$component->getActiveVersion()}",
          'uuid' => self::TEST_UUID,
        ], JSON_THROW_ON_ERROR),
        'form_canvas_props' => json_encode($form_canvas_props, JSON_THROW_ON_ERROR),
        'form_canvas_selected' => self::TEST_UUID,
      ],
    )));

    self::assertArrayHasKey('html', $json);
    $crawler = new Crawler($json['html']);
    // One labeled group containing one widget per sub-property, with form
    // state keyed `propName.subPropName`.
    self::assertStringContainsString('Ingredient', $crawler->text());
    self::assertStringContainsString('Ingredient name', $crawler->text());
    self::assertCount(1, $crawler->filter('[name*="ingredient.name"]'));
    self::assertCount(1, $crawler->filter('[name*="ingredient.amount"]'));
    self::assertCount(1, $crawler->filter('select[name*="ingredient.unit"]'));
    self::assertSame('Sugar', $crawler->filter('[name*="ingredient.name"]')->attr('value'));

    // The multi-value group renders one item form per stored item, with form
    // state keyed `propName.delta.subPropName`, plus add and remove buttons.
    self::assertCount(1, $crawler->filter('[name*="authors.0.name"]'));
    self::assertCount(1, $crawler->filter('[name*="authors.1.name"]'));
    self::assertSame('Marie', $crawler->filter('[name*="authors.0.name"]')->attr('value'));
    self::assertSame('Joan', $crawler->filter('[name*="authors.1.name"]')->attr('value'));
    self::assertCount(1, $crawler->filter('input[type="submit"][value="Add new"][data-object-props-add]'));
    self::assertCount(2, $crawler->filter('input[type="submit"][value="Remove"][data-object-props-remove]'));

    // Every sub-property widget declares its transforms, keyed by its form
    // state key.
    self::assertArrayHasKey('transforms', $json);
    self::assertSame(['mainProperty' => ['multiple' => FALSE]], $json['transforms']['ingredient.name']);
    self::assertSame(['mainProperty' => ['multiple' => FALSE]], $json['transforms']['ingredient.amount']);
    self::assertArrayHasKey('ingredient.unit', $json['transforms']);
    self::assertSame(['link' => ['multiple' => FALSE]], $json['transforms']['authors.0.link']);
    self::assertSame(['mainProperty' => ['multiple' => FALSE]], $json['transforms']['authors.1.name']);

    // A fresh instance (the client model equals the component's example, so
    // the groups are represented by a DefaultRelativeUrlPropSource) prefills
    // the widgets with the example values, like scalar props whose example
    // is stored as the field's default value.
    $fresh_form_canvas_props = [
      'resolved' => [
        'ingredient' => ['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g'],
        'authors' => [
          ['name' => 'Ada', 'link' => 'https://example.com/ada'],
          ['name' => 'Grace'],
        ],
      ],
      'source' => [
        'ingredient' => \array_intersect_key($client_side_info['propSources']['ingredient'], \array_flip(['sourceType', 'sources', 'sourceTypeSettings'])),
        'authors' => \array_intersect_key($client_side_info['propSources']['authors'], \array_flip(['sourceType', 'sources', 'sourceTypeSettings'])),
      ],
    ];
    $json = self::decodeResponse($this->request(Request::create(
      '/canvas/api/v0/form/component-instance/canvas_page/' . $page->id(),
      'PATCH',
      [
        'form_canvas_tree' => json_encode([
          'nodeType' => 'component',
          'slots' => [],
          'type' => "{$component->id()}@{$component->getActiveVersion()}",
          'uuid' => self::TEST_UUID,
        ], JSON_THROW_ON_ERROR),
        'form_canvas_props' => json_encode($fresh_form_canvas_props, JSON_THROW_ON_ERROR),
        'form_canvas_selected' => self::TEST_UUID,
      ],
    )));
    $crawler = new Crawler($json['html']);
    self::assertSame('Flour', $crawler->filter('[name*="ingredient.name"]')->attr('value'));
    self::assertSame('500.5', $crawler->filter('[name*="ingredient.amount"]')->attr('value'));
    self::assertSame('Ada', $crawler->filter('[name*="authors.0.name"]')->attr('value'));
    self::assertSame('https://example.com/ada', $crawler->filter('[name*="authors.0.link"]')->attr('value'));
    self::assertSame('Grace', $crawler->filter('[name*="authors.1.name"]')->attr('value'));
  }

  /**
   * Editing sub-properties stores one nested object under the prop name.
   */
  public function testClientModelToInputStoresNestedObject(): void {
    $component = self::getComponent();
    $source = $component->getComponentSource();
    \assert($source instanceof JsonSchemaPropsComponentSourceBase);
    $client_side_info = $source->getClientSideInfo($component);
    self::assertArrayHasKey('propSources', $client_side_info);

    $source_meta = fn (string $prop): array => \array_intersect_key($client_side_info['propSources'][$prop], \array_flip(['sourceType', 'sources', 'sourceTypeSettings']));

    // The Content Creator edited two sub-properties of the single-value group,
    // and added/ordered two items in the multi-value group.
    $client_model = [
      'source' => [
        'ingredient' => $source_meta('ingredient') + [
          'value' => ['name' => 'Sugar', 'amount' => 250.5],
        ],
        'authors' => $source_meta('authors') + [
          'value' => [
            ['name' => 'Grace'],
            ['name' => 'Ada', 'link' => ['uri' => 'https://example.com/ada']],
          ],
        ],
      ],
      'resolved' => [
        'ingredient' => ['name' => 'Sugar', 'amount' => 250.5],
        'authors' => [
          ['name' => 'Grace'],
          ['name' => 'Ada', 'link' => 'https://example.com/ada'],
        ],
      ],
    ];
    // @phpstan-ignore argument.type
    $inputs = $source->clientModelToInput(self::TEST_UUID, $component, $client_model, NULL);
    // The stored `inputs` value is one nested object under the prop name…
    self::assertSame(['name' => 'Sugar', 'amount' => 250.5], $inputs['ingredient']);
    // …and an ordered array of objects for the multi-value group.
    self::assertSame([
      ['name' => 'Grace'],
      ['name' => 'Ada', 'link' => ['uri' => 'https://example.com/ada', 'options' => []]],
    ], $inputs['authors']);

    // Those inputs are valid…
    $violations = $source->validateComponentInput($inputs, self::TEST_UUID, NULL);
    self::assertSame([], \array_map(static fn ($v) => (string) $v->getMessage(), \iterator_to_array($violations)));

    // …and hydrate into the props the component receives.
    $page = Page::create(['title' => $this->randomMachineName()]);
    $page->setComponentTree([
      [
        'uuid' => self::TEST_UUID,
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => $inputs,
      ],
    ]);
    self::assertCount(0, $page->validate());
    self::assertSame(SAVED_NEW, $page->save());
    $item = $page->getComponentTree()->first();
    \assert($item !== NULL);
    $explicit_input = $source->getExplicitInput(self::TEST_UUID, $item);
    self::assertSame(['name' => 'Sugar', 'amount' => 250.5], $explicit_input['resolved']['ingredient']->value);
    self::assertSame([
      ['name' => 'Grace'],
      ['name' => 'Ada', 'link' => 'https://example.com/ada'],
    ], $explicit_input['resolved']['authors']->value);

    // The collapsed (stored) group inputs still contribute their
    // sub-properties' dependencies: the authors group's `link` sub-property
    // is stored in a conjured link field.
    // @see \Drupal\canvas\Plugin\DataType\ComponentInputs::getPropSources()
    $dependencies = $item->get('inputs')->calculateDependencies();
    self::assertContains('link', $dependencies['module'] ?? []);
  }

  /**
   * Fresh instances of group props round-trip through the example fallback.
   *
   * A component instance that has no author-entered value for a group prop
   * stores a DefaultRelativeUrlPropSource (the component's example, with
   * relative URLs rewritten at runtime). ::inputToClientModel() must map that
   * back to the default *composite* source for the client — not crash trying
   * to conjure a single static prop source (regression: 500 on layout GET).
   *
   * @see \Drupal\canvas\PropSource\DefaultRelativeUrlPropSource
   */
  public function testDefaultRelativeUrlSourceRoundTrip(): void {
    $component = self::getComponent();
    $source = $component->getComponentSource();
    \assert($source instanceof JsonSchemaPropsComponentSourceBase);
    $client_side_info = $source->getClientSideInfo($component);
    self::assertArrayHasKey('propSources', $client_side_info);

    // A fresh instance: the client provides the composite source meta with no
    // author-entered value, so the example fallback is stored.
    $client_model = [
      'source' => [
        'ingredient' => \array_intersect_key($client_side_info['propSources']['ingredient'], \array_flip(['sourceType', 'sources', 'sourceTypeSettings'])),
      ],
      'resolved' => [
        'ingredient' => [],
      ],
    ];
    // @phpstan-ignore argument.type
    $inputs = $source->clientModelToInput(self::TEST_UUID, $component, $client_model, NULL);
    self::assertSame('default-relative-url', $inputs['ingredient']['sourceType'] ?? NULL);

    // Store it on a page and round-trip back to the client model.
    $page = Page::create(['title' => $this->randomMachineName()]);
    $page->setComponentTree([
      [
        'uuid' => self::TEST_UUID,
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => $inputs,
      ],
    ]);
    self::assertCount(0, $page->validate());
    self::assertSame(SAVED_NEW, $page->save());
    $item = $page->getComponentTree()->first();
    \assert($item !== NULL);
    $explicit_input = $source->getExplicitInput(self::TEST_UUID, $item);
    // The preview keeps rendering the composed example…
    self::assertSame(['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g'], $explicit_input['resolved']['ingredient']->value);
    // …while the form-facing source maps back to the (empty) composite.
    $model = $source->inputToClientModel($explicit_input);
    self::assertSame('object-props', $model['source']['ingredient']['sourceType']);
    self::assertSame(['name', 'amount', 'unit'], \array_keys($model['source']['ingredient']['sources']));
  }

  /**
   * Required sub-properties are enforced per group (or item), when populated.
   */
  public function testRequiredSubPropertyEnforcement(): void {
    $component = self::getComponent();
    $source = $component->getComponentSource();
    \assert($source instanceof JsonSchemaPropsComponentSourceBase);

    // A partially populated group missing its required sub-property is
    // blocked.
    $violations = $source->validateComponentInput([
      'ingredient' => ['unit' => 'g'],
    ], self::TEST_UUID, NULL);
    self::assertCount(1, $violations);
    self::assertStringContainsString('The property name is required', (string) $violations->get(0)->getMessage());

    // Enforcement is per item for multi-value groups.
    $violations = $source->validateComponentInput([
      'ingredient' => ['name' => 'Flour'],
      'authors' => [
        ['name' => 'Ada'],
        ['link' => ['uri' => 'https://example.com/grace']],
      ],
    ], self::TEST_UUID, NULL);
    self::assertCount(1, $violations);
    self::assertStringContainsString('The property name is required', (string) $violations->get(0)->getMessage());

    // A fully empty item is valid: it is dropped.
    $violations = $source->validateComponentInput([
      'ingredient' => ['name' => 'Flour'],
      'authors' => [
        ['name' => 'Ada'],
        [],
      ],
    ], self::TEST_UUID, NULL);
    self::assertCount(0, $violations);
  }

}
