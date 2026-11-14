import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import TextField from './TextField';

describe('TextField', () => {
  it('shows a unit and announces it as the description of the input', () => {
    render(
      <>
        <TextField
          attributes={{
            type: 'number',
            'aria-label': 'Width',
            'aria-describedby': 'width-description',
            'data-canvas-unit': 'px',
            value: '100',
          }}
        />
        <div id="width-description">The width of the card.</div>
      </>,
    );
    const input = screen.getByRole('spinbutton', { name: 'Width' });
    expect(screen.getByText('px')).toBeVisible();
    // The unit follows the description that the input already had.
    expect(input).toHaveAccessibleDescription('The width of the card. px');
    // The value stays the bare number.
    expect(input).toHaveValue(100);
  });

  it('renders only the input without a unit', () => {
    const { container } = render(
      <TextField attributes={{ type: 'number', 'aria-label': 'Width' }} />,
    );
    const input = screen.getByRole('spinbutton', { name: 'Width' });
    expect(input).not.toHaveAttribute('aria-describedby');
    expect(input).not.toHaveAttribute('style');
    expect(container.querySelectorAll('span')).toHaveLength(0);
  });
});
