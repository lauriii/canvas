import { ExclamationTriangleIcon, ReloadIcon } from '@radix-ui/react-icons';
import { Box, Button, Callout } from '@radix-ui/themes';

import type React from 'react';

const ErrorCard: React.FC<{
  title?: string;
  error?: string | React.ReactNode;
  resetErrorBoundary?: () => void;
  resetButtonText?: string;
  asChild?: boolean;
  children?: React.ReactNode;
}> = ({
  title = Drupal.t('An unexpected error has occurred.'),
  error,
  resetErrorBoundary,
  resetButtonText = Drupal.t('Try again'),
  asChild = false,
  children,
}) => (
  <Box data-testid="canvas-error-card" maxWidth="520px" mt="4">
    <Callout.Root color="red" role="alert">
      <Callout.Icon>
        <ExclamationTriangleIcon />
      </Callout.Icon>
      <Callout.Text>
        <strong>{title}</strong>
      </Callout.Text>
      <Box overflow="hidden">
        {asChild ? children : <Callout.Text>{error}</Callout.Text>}
      </Box>
      {resetErrorBoundary && (
        <Box mt="1">
          <Button data-testid="canvas-error-reset" onClick={resetErrorBoundary}>
            <ReloadIcon />
            {resetButtonText}
          </Button>
        </Box>
      )}
    </Callout.Root>
  </Box>
);

export default ErrorCard;
