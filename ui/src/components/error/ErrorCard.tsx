import { Card, Heading, Text, Flex, Box, Button } from '@radix-ui/themes';
import { ExclamationTriangleIcon, ReloadIcon } from '@radix-ui/react-icons';

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
  <Box data-testid="error-card" maxWidth="520px" m="4">
    <Card role="alert" variant="surface">
      <Flex p="4" direction="column" gap="4" align="start">
        <Flex align="center" gap="3">
          <Flex flexShrink="0" flexGrow="0" align="center">
            <ExclamationTriangleIcon width="24" height="24" />
          </Flex>
          <Heading trim="both" size="4" weight="medium">
            {title}
          </Heading>
        </Flex>
        {error && <Text as="p">{error}</Text>}
        {resetErrorBoundary && (
          <Button data-testid="error-reset" onClick={resetErrorBoundary}>
            <ReloadIcon />
            {resetButtonText}
          </Button>
        )}
      </Flex>
    </Card>
  </Box>
);

export default ErrorCard;
