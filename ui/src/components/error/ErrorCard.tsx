import { Box, Button, Callout } from '@radix-ui/themes';
import { ExclamationTriangleIcon, ReloadIcon } from '@radix-ui/react-icons';
import type React from 'react';

const DEFAULT_TITLE = 'An unexpected error has occurred.';
const DEFAULT_RESET_BUTTON_TEXT = 'Try again';

const ErrorCard: React.FC<{
  title?: string;
  error?: string;
  resetErrorBoundary?: () => void;
  resetButtonText?: string;
}> = ({
  title = DEFAULT_TITLE,
  error,
  resetErrorBoundary,
  resetButtonText = DEFAULT_RESET_BUTTON_TEXT,
}) => (
  <Box data-testid="xb-error-card" maxWidth="520px" mt="4">
    <Callout.Root color="red" role="alert">
      <Callout.Icon>
        <ExclamationTriangleIcon />
      </Callout.Icon>
      <Callout.Text>
        <strong>{title}</strong>
      </Callout.Text>
      <Box overflow="hidden">
        <Callout.Text>{error}</Callout.Text>
      </Box>
      {resetErrorBoundary && (
        <Box mt="1">
          <Button data-testid="xb-error-reset" onClick={resetErrorBoundary}>
            <ReloadIcon />
            {resetButtonText}
          </Button>
        </Box>
      )}
    </Callout.Root>
  </Box>
);

export default ErrorCard;
