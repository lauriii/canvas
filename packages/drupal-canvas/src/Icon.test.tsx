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

  it('renders an <img> filling the same wrapper for a url-resolved icon', () => {
    const element = Icon({
      icon: { id: 'pack:home', url: '/files/home.svg' },
      className: 'size-6',
    });
    // The wrapper is the same element the svg form uses, so className and
    // style size both forms alike.
    expect(element?.type).toBe('span');
    expect((element?.props as { className: string }).className).toBe('size-6');
    const img = (element?.props as { children: React.ReactElement }).children;
    expect(img.type).toBe('img');
    const imgProps = img.props as {
      src: string;
      style: Record<string, string>;
    };
    expect(imgProps.src).toBe('/files/home.svg');
    expect(imgProps.style).toEqual({ width: '100%', height: '100%' });
  });

  it('renders nothing when the icon is unset or unresolved', () => {
    expect(Icon({ icon: null })).toBeNull();
    expect(Icon({ icon: undefined })).toBeNull();
    // Resolved to neither svg nor url (e.g. a missing icon).
    expect(Icon({ icon: { id: 'pack:missing' } })).toBeNull();
  });
});
