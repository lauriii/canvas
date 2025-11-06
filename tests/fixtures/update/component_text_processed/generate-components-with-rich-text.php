<?php

/**
 * @file
 * This script is NOT used in the tests.
 *
 * This script is expected to run with e.g. the alpha1 codebase, not
 * the current codebase.
 *
 * This script just generates the state we want to put in top of
 * a bare dump to help generating our different test cases.
 *
 * The actual test just runs a fixture script including SQL commands
 * on top of the bare dump.
 * This is included in the repo as a reference for others, plus in
 * case we need to regenerate some test scenarios for some reason.
 *
 * After we run this on alpha1, we need to export the dump, and find
 * the relevant inserts to these config entities, and put those in our
 * actual fixture script. See `components-with-rich-text.php`.
 */

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;

$js = <<<JS
import FormattedText from '@/lib/FormattedText';

const Component = ({ text }) => {
  return (
    <FormattedText>
      { text }
    </FormattedText>
  );
};

export default Component;
JS;

$compiled_js = <<<JS
    import { jsx as _jsx } from "react/jsx-runtime";
    import FormattedText from '@/lib/FormattedText';
    const Component = ({ text })=>{
        return /*#__PURE__*/ _jsx(FormattedText, {
            children: text
        });
    };
    export default Component;
JS;


$props = [
  'text' => [
    'type' => 'string',
    'title' => 'Text',
    'contentMediaType' => 'text/html',
    'x-formatting-context' => 'block',
    'examples' => ['This is an example'],
  ],
];

$js_component = JavaScriptComponent::create([
  'machineName' => 'component_with_rich_text',
  'name' => 'Component with rich text',
  'status' => TRUE,
  'props' => $props,
  'required' => ['text'],
  'slots' => [],
  'js' => [
    'original' => $js,
    'compiled' => $compiled_js,
  ],
  'css' => [
    'original' => '',
    'compiled' => '',
  ],
  'dataDependencies' => [],
]);
$js_component->save();

JsComponent::createConfigEntity($js_component);
