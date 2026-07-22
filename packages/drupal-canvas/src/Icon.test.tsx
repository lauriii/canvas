import { isValidElement } from 'react';
import { describe, expect, it } from 'vitest';

import Icon from './Icon';

describe('Icon', () => {
  it('renders inline SVG in a span for an svg-resolved icon', () => {
    const element = Icon({ icon: { id: 'pack:star', svg: '<svg/>' } });
    expect(isValidElement(element)).toBe(true);
    expect(element?.type).toBe('span');
    expect(
      (element?.props as { dangerouslySetInnerHTML: { __html: string } })
        .dangerouslySetInnerHTML,
    ).toEqual({ __html: '<svg/>' });
  });

  it('honors `as` and forwards className', () => {
    const element = Icon({
      icon: { id: 'pack:star', svg: '<svg/>' },
      as: 'div',
      className: 'size-6',
    });
    expect(element?.type).toBe('div');
    expect((element?.props as { className: string }).className).toBe('size-6');
  });

  it('renders an <img> for a url-resolved icon', () => {
    const element = Icon({ icon: { id: 'pack:home', url: '/files/home.svg' } });
    expect(element?.type).toBe('img');
    expect((element?.props as { src: string }).src).toBe('/files/home.svg');
  });

  it('renders nothing when the icon is unset or unresolved', () => {
    expect(Icon({ icon: null })).toBeNull();
    expect(Icon({ icon: undefined })).toBeNull();
    // Resolved to neither svg nor url (e.g. a missing icon).
    expect(Icon({ icon: { id: 'pack:missing' } })).toBeNull();
  });
});
