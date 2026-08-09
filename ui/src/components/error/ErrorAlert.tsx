import { ExclamationTriangleIcon, ReloadIcon } from '@radix-ui/react-icons';
import { AlertDialog, Box, Button, Flex } from '@radix-ui/themes';

const ErrorAlert: React.FC<{
  title?: string;
  error?: string;
  resetErrorBoundary?: () => void;
  resetButtonText?: string;
}> = ({
  title = Drupal.t('An unexpected error has occurred.'),
  error,
  resetErrorBoundary,
  resetButtonText = Drupal.t('Try again'),
}) => (
  <AlertDialog.Root defaultOpen>
    <AlertDialog.Content data-testid="canvas-error-alert" maxWidth="520px">
      <AlertDialog.Title>
        <Flex align="center" gap="3">
          <Flex flexShrink="0" flexGrow="0" align="center">
            <ExclamationTriangleIcon width="24" height="24" />
          </Flex>
          {title}
        </Flex>
      </AlertDialog.Title>
      {error && (
        <AlertDialog.Description size="2">{error}</AlertDialog.Description>
      )}
      {resetErrorBoundary && (
        <Box mt="4">
          <AlertDialog.Action>
            <Button
              data-testid="canvas-error-reset"
              variant="solid"
              onClick={resetErrorBoundary}
            >
              <ReloadIcon />
              {resetButtonText}
            </Button>
          </AlertDialog.Action>
        </Box>
      )}
    </AlertDialog.Content>
  </AlertDialog.Root>
);

export default ErrorAlert;
