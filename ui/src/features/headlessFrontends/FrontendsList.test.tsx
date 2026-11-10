import { describe, expect, it, vi } from 'vitest';
import { Provider as TooltipProvider } from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import FrontendsList from './FrontendsList';

vi.mock('./checkFrontendConnection', () => ({
  checkFrontendConnection: vi.fn(() => new Promise(() => {})),
}));

describe('FrontendsList', () => {
  it('opens the setup guide from the setup-needed tooltip', async () => {
    const user = userEvent.setup();
    const onOpenSetupGuide = vi.fn();

    render(
      <Theme>
        <TooltipProvider delayDuration={0}>
          <FrontendsList
            frontends={[
              {
                id: 'https://frontend.example',
                url: 'https://frontend.example',
                status: 'setup-needed',
              },
            ]}
            onAdd={vi.fn()}
            onReorder={vi.fn()}
            onRemove={vi.fn()}
            onStatusChange={vi.fn()}
            onOpenSetupGuide={onOpenSetupGuide}
            disabled={false}
          />
        </TooltipProvider>
      </Theme>,
    );

    await user.hover(screen.getByText('Setup needed'));
    await user.click(
      await screen.findByRole('link', {
        name: 'setup guide',
      }),
    );

    expect(onOpenSetupGuide).toHaveBeenCalledOnce();
  });
});
