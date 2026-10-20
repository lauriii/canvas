import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import IconPreview from '@/components/icons/IconPreview';

describe('IconPreview', () => {
  it('renders server-produced SVG markup inline', () => {
    const { container } = render(
      <IconPreview
        icon={{
          id: 'pack:star',
          name: 'star',
          label: 'Star',
          svg: '<svg xmlns="http://www.w3.org/2000/svg"><path d="M1 1"/></svg>',
        }}
      />,
    );
    expect(container.querySelector('svg path')).toHaveAttribute('d', 'M1 1');
  });

  it('renders URL-backed icons as an image with the label as alt text', () => {
    render(
      <IconPreview
        icon={{
          id: 'pack:star',
          name: 'star',
          label: 'Star',
          url: '/files/star.svg',
        }}
      />,
    );
    expect(screen.getByRole('img', { name: 'Star' })).toHaveAttribute(
      'src',
      '/files/star.svg',
    );
  });

  it('falls back to a letter tile when nothing resolved', () => {
    render(
      <IconPreview icon={{ id: 'pack:star', name: 'star', label: 'Star' }} />,
    );
    expect(screen.getByTitle('star')).toHaveTextContent('S');
  });
});
