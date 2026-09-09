import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import ComponentPreview from '@/components/ComponentPreview';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { DynamicComponent } from '@/types/Component';

const component: DynamicComponent = {
  id: 'block.system_branding_block',
  name: 'Site branding',
  library: 'dynamic_components',
  source: 'Block',
  default_markup: '<div class="site-branding">Canvas</div>',
  css: '',
  js_header: '',
  js_footer: '',
  version: '1',
  broken: false,
};

describe('ComponentPreview', () => {
  // Themes such as Olivero only expose their configured brand color as CSS
  // custom properties on the <html> element, so a preview document without
  // those attributes silently falls back to the theme's defaults.
  // @see https://git.drupalcode.org/project/canvas/-/issues/3504925
  it("applies the theme's <html> attributes to the preview document", () => {
    getCanvasSettings().globalAssets = {
      css: '',
      jsHeader: '',
      jsFooter: '',
      htmlAttributes: ' lang="en" dir="ltr" style="--color--primary-hue:6"',
    };

    render(<ComponentPreview componentListItem={component} />);

    const iframe = screen.getByTitle('Site branding') as HTMLIFrameElement;
    // jsdom never parses `srcdoc`, so seed the element the iframe's load
    // handler measures once the (never parsed) document "loads".
    iframe.contentDocument!.body.innerHTML =
      '<div id="component-wrapper"></div>';

    const srcDoc = iframe.getAttribute('srcdoc') ?? '';
    expect(srcDoc).toContain(
      '<html lang="en" dir="ltr" style="--color--primary-hue:6">',
    );

    // Re-adding only <html> after the <template> round trip must still parse
    // into the same document: assets in <head>, the component in <body>.
    const previewDocument = new DOMParser().parseFromString(
      srcDoc,
      'text/html',
    );
    expect(previewDocument.documentElement.getAttribute('style')).toBe(
      '--color--primary-hue:6',
    );
    expect(previewDocument.head.querySelector('base')).not.toBeNull();
    expect(
      previewDocument.body.querySelector('#component-wrapper')?.innerHTML,
    ).toContain('<div class="site-branding">Canvas</div>');
  });
});
