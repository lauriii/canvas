import { isValidElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

import Image from './next-image-standalone';

import type { ImageLoaderParams, ImageProps } from 'next-image-standalone';

const ALTERNATE_WIDTHS =
  '/sites/default/files/styles/canvas_parametrized_width--{width}/public/maple-street.jpg.webp?itok=Wp4lG4Wk';

const withAlternateWidths = (src: string, template = ALTERNATE_WIDTHS) =>
  `${src}?alternateWidths=${encodeURIComponent(template)}`;

/**
 * The props `Image` passes on to `next-image-standalone`.
 */
const renderedProps = (props: Parameters<typeof Image>[0]) => {
  const element = Image(props);
  if (!isValidElement(element)) {
    throw new Error('`Image` did not return a React element.');
  }
  return element.props as ImageProps & {
    loader: (params: ImageLoaderParams) => string;
    unoptimized?: boolean;
  };
};

describe('Image', () => {
  it('builds image candidates from the `alternateWidths` query string parameter', () => {
    const { loader, unoptimized } = renderedProps({
      src: withAlternateWidths('/sites/default/files/maple-street.jpg'),
      width: 1200,
      height: 800,
      alt: 'A maple street',
    });

    expect(unoptimized).toBeUndefined();
    expect(
      loader({ src: '', width: 640, imageProps: { src: '', alt: '' } }),
    ).toBe(
      '/sites/default/files/styles/canvas_parametrized_width--640/public/maple-street.jpg.webp?itok=Wp4lG4Wk',
    );
  });

  it('computes the height for image candidates that need one', () => {
    const { loader } = renderedProps({
      src: withAlternateWidths(
        'https://placehold.co/800x600',
        'https://placehold.co/{width}x{height}',
      ),
      width: 800,
      height: 600,
      alt: 'Example image placeholder',
    });

    expect(
      loader({
        src: '',
        width: 400,
        imageProps: { src: '', alt: '', width: 800, height: 600 },
      }),
    ).toBe('https://placehold.co/400x300');
  });

  it('falls back to `src` when a candidate needs a height it cannot compute', () => {
    const consoleError = vi
      .spyOn(console, 'error')
      .mockImplementation(() => {});
    const { loader } = renderedProps({
      src: withAlternateWidths(
        'https://placehold.co/800x600',
        'https://placehold.co/{width}x{height}',
      ),
      alt: 'Example image placeholder',
    });

    expect(
      loader({ src: '', width: 400, imageProps: { src: '', alt: '' } }),
    ).toBe(
      'https://placehold.co/800x600?alternateWidths=https%3A%2F%2Fplacehold.co%2F%7Bwidth%7Dx%7Bheight%7D',
    );
    expect(consoleError).toHaveBeenCalledOnce();
    consoleError.mockRestore();
  });

  // Drupal generates no derivative images for an image its image toolkit cannot
  // process, such as an SVG image, so there is nothing to build image
  // candidates from: the image must be rendered as-is, without a `srcset`.
  // @see \Drupal\canvas\TypedData\ImageDerivativeWithParametrizedWidth::computeValue()
  it.each([
    ['an SVG image', '/sites/default/files/drupal-logo.svg'],
    ['an image with other query string parameters', '/llama.jpg?itok=Wp4lG4Wk'],
  ])('renders %s unoptimized', (_label, src) => {
    const { unoptimized } = renderedProps({ src, alt: '' });

    expect(unoptimized).toBe(true);
  });

  it('leaves an explicitly provided loader alone', () => {
    const customLoader = ({ width }: ImageLoaderParams) =>
      `/llama-${width}.jpg`;
    const { loader, unoptimized } = renderedProps({
      src: '/sites/default/files/drupal-logo.svg',
      width: 100,
      height: 100,
      alt: '',
      loader: customLoader,
    });

    expect(unoptimized).toBeUndefined();
    expect(loader).toBe(customLoader);
  });
});
