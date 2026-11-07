import { describe, expect, it } from 'vitest';

import { readControl } from './usePropVisibility';

const wrapper = (html: string) => {
  const element = document.createElement('div');
  element.innerHTML = html;
  return element;
};

describe('readControl', () => {
  it.each<[string, string, unknown]>([
    ['a switch', '<button role="switch" aria-checked="true"></button>', true],
    [
      'a checkbox',
      '<input type="checkbox" name="canvas_component_props[u][show][value]">',
      false,
    ],
    [
      'a number',
      '<input type="number" name="canvas_component_props[u][count][0][value]" value="2">',
      '2',
    ],
    [
      'radios',
      '<input type="radio" name="canvas_component_props[u][layout]" value="stack"><input type="radio" name="canvas_component_props[u][layout]" value="split" checked>',
      'split',
    ],
    [
      'a select without a value',
      '<select name="canvas_component_props[u][layout]"><option value="_none" selected>None</option><option value="split">Split</option></select>',
      null,
    ],
    [
      'several inputs',
      '<input name="canvas_component_props[u][items][0][value]" value="a"><input name="canvas_component_props[u][items][1][value]" value="b">',
      undefined,
    ],
    [
      'a switch next to other inputs',
      '<button role="switch" aria-checked="true"></button><input name="canvas_component_props[u][group][0][title]" value="a"><input name="canvas_component_props[u][group][1][title]" value="b">',
      undefined,
    ],
  ])('reads %s', (_, html, expected) => {
    expect(readControl(wrapper(html))).toBe(expected);
  });
});
