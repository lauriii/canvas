<?php

/**
 * @file
 * This script is NOT used in the tests.
 *
 * This script documents how the config rows in
 * `derived-schema-metadata-fixture.php` were generated, in case they need to
 * be regenerated. Unlike `tracking-required`, it runs against the CURRENT
 * codebase (e.g. as the body of a throwaway kernel test with
 * `canvas_test_sdc`, `node`, `field`, `link`, `options`, `text` and `filter`
 * installed): `derived_schema_metadata` is excluded from the version hash, so
 * stripping it from the generated rows yields valid pre-update config without
 * needing an old codebase.
 *
 * After running it, export the three config rows named below from the
 * `config` table, strip every `derived_schema_metadata` key from all
 * `prop_field_definitions` (in all versions), and put the serialized data in
 * `derived-schema-metadata-fixture.php`.
 */

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;

$some_js = 'console.log("hey");';
// Every string shape a code component can express (multi-line `pattern` is
// not expressible in a code component — the `canvas_test_sdc:code-example`
// SDC covers it), plus non-translatable props: string enum, email, integer.
// @see \Drupal\canvas\PropShape\PropShape::getTranslatableStringShape()
$props = [
  'plain' => [
    'type' => 'string',
    'title' => 'Plain string',
    'examples' => ['Hello, world!'],
  ],
  'uri' => [
    'type' => 'string',
    'format' => 'uri',
    'title' => 'URI',
    'examples' => ['https://example.com'],
  ],
  'uri_reference' => [
    'type' => 'string',
    'format' => 'uri-reference',
    'title' => 'URI reference',
    'examples' => ['/about'],
  ],
  'iri' => [
    'type' => 'string',
    'format' => 'iri',
    'title' => 'IRI',
    'examples' => ['https://example.com/d%C3%BCrst'],
  ],
  'iri_reference' => [
    'type' => 'string',
    'format' => 'iri-reference',
    'title' => 'IRI reference',
    'examples' => ['/about'],
  ],
  'rich_text' => [
    'type' => 'string',
    'contentMediaType' => 'text/html',
    'x-formatting-context' => 'block',
    'title' => 'Rich text',
    'examples' => ['<p>Hello, world!</p>'],
  ],
  'string_enum' => [
    'type' => 'string',
    'enum' => ['foo', 'bar'],
    'meta:enum' => ['foo' => 'Foo', 'bar' => 'Bar'],
    'title' => 'String enum',
    'examples' => ['foo'],
  ],
  'email' => [
    'type' => 'string',
    'format' => 'email',
    'title' => 'Email',
    'examples' => ['hello@example.com'],
  ],
  'quantity' => [
    'type' => 'integer',
    'title' => 'Quantity',
    'examples' => [3],
  ],
  'strings' => [
    'type' => 'array',
    'items' => ['type' => 'string'],
    'title' => 'Strings',
    'examples' => [['Hello', 'world']],
  ],
];

$js_component = JavaScriptComponent::create([
  'machineName' => 'string_shapes',
  'name' => 'Every string shape a code component supports',
  'status' => TRUE,
  'props' => $props,
  'required' => [],
  'slots' => [],
  'js' => ['original' => $some_js, 'compiled' => $some_js],
  'css' => ['original' => '', 'compiled' => ''],
  'dataDependencies' => [],
]);
// Saving an enabled JavaScriptComponent auto-creates the corresponding
// `canvas.component.js.string_shapes` Component config entity.
$js_component->save();

// Trigger a past version by changing an example.
$props['plain']['examples'] = ['Trigger a new version'];
$js_component->setProps($props);
$js_component->save();
$component = Component::load('js.string_shapes');
\assert($component instanceof Component && count($component->getVersions()) === 2);

// Generate `canvas.component.sdc.canvas_test_sdc.code-example`, covering the
// multi-line `pattern` shape.
\Drupal::service(ComponentSourceManager::class)->generateComponents();
\assert(Component::load('sdc.canvas_test_sdc.code-example') instanceof Component);
