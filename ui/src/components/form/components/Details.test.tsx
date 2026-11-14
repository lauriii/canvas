import { describe, expect, it } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import Details from './Details';

import type { ReactNode } from 'react';

/**
 * Renders a section of the component instance form the way the server sends
 * it: a details element that contains the wrapper of each of its props.
 */
const renderSection = (
  children: ReactNode,
  attributes: Record<string, string> = { 'data-canvas-prop-section': 'true' },
) =>
  render(
    <Theme>
      <form data-tab="content">
        <style>
          {'[data-tab="content"] [data-tab-of="style"] { display: none; }'}
        </style>
        <Details
          title="Advanced"
          attributes={attributes}
          // As core sets them for a summary element.
          summaryAttributes={{ role: 'button', 'aria-expanded': 'false' }}
        >
          {children}
        </Details>
      </form>
    </Theme>,
  );

const section = (container: HTMLElement) =>
  container.querySelector('[data-canvas-prop-section]') as HTMLElement;

describe('Details', () => {
  it('toggles with one button and keeps its content mounted', () => {
    renderSection(<input aria-label="Eyebrow" defaultValue="New" />);
    // Only the trigger is a button: the title in it has no role or state.
    const trigger = screen.getByRole('button', { name: 'Advanced' });
    expect(trigger.tagName).toBe('BUTTON');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    // The collapsed section still holds its form elements.
    expect(screen.getByLabelText<HTMLInputElement>('Eyebrow').value).toBe(
      'New',
    );

    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
  });

  it('starts open with the open attribute', () => {
    renderSection(<input />, { open: 'open' });
    expect(
      screen
        .getByRole('button', { name: 'Advanced' })
        .getAttribute('aria-expanded'),
    ).toBe('true');
  });

  it('hides a section of props while none of its props shows', async () => {
    const { container } = renderSection(
      <>
        <div data-canvas-prop="eyebrow" hidden>
          <input />
        </div>
        <div data-tab-of="style">
          <input />
        </div>
      </>,
    );
    expect(section(container).hidden).toBe(true);

    // A prop whose condition passes.
    const eyebrow = container.querySelector(
      '[data-canvas-prop="eyebrow"]',
    ) as HTMLElement;
    eyebrow.hidden = false;
    await waitFor(() => expect(section(container).hidden).toBe(false));
    eyebrow.hidden = true;
    await waitFor(() => expect(section(container).hidden).toBe(true));

    // A prop that an attribute of the form no longer hides.
    container.querySelector('form')?.setAttribute('data-tab', 'style');
    await waitFor(() => expect(section(container).hidden).toBe(false));
  });

  it('does not hide other details', () => {
    const { container } = renderSection(
      <div hidden>
        <input />
      </div>,
      { id: 'seo' },
    );
    expect((container.querySelector('#seo') as HTMLElement).hidden).toBe(false);
  });

  it('opens a section of props that has an invalid value', async () => {
    renderSection(<input aria-label="Maximum width" />);
    const trigger = screen.getByRole('button', { name: 'Advanced' });
    const input = screen.getByLabelText('Maximum width');

    input.setAttribute('data-invalid-prop-value', 'true');
    await waitFor(() =>
      expect(trigger.getAttribute('aria-expanded')).toBe('true'),
    );
    // It cannot be collapsed while the value is invalid.
    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');

    // It stays open once the value is valid, until the user collapses it.
    input.removeAttribute('data-invalid-prop-value');
    await new Promise((resolve) => setTimeout(resolve));
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
  });
});
