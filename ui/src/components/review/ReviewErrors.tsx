import {
  Box,
  Button,
  Callout,
  Code,
  Heading,
  Popover,
  Separator,
} from '@radix-ui/themes';
import { ExclamationTriangleIcon } from '@radix-ui/react-icons';
import type { ErrorResponse } from '@/services/pendingChangesApi';
interface ReviewErrorsProps {
  errorState: ErrorResponse | undefined;
}

const ReviewErrors: React.FC<ReviewErrorsProps> = ({ errorState }) => {
  if (errorState?.errors?.length) {
    return (
      <Box>
        <Separator my="3" size="4" />
        <Heading as="h4" size="2">
          Errors
        </Heading>
        {errorState.errors.map((error, ix) => (
          <Box key={ix} my="2">
            <Popover.Root>
              <Callout.Root color="red">
                <Callout.Icon>
                  <ExclamationTriangleIcon color="red" />
                </Callout.Icon>
                <Popover.Trigger>
                  <Callout.Text>
                    <Button variant="ghost">{error.detail}</Button>
                  </Callout.Text>
                </Popover.Trigger>
                <Popover.Content>
                  <pre>
                    <Code color="red">{JSON.stringify(error, null, 2)}</Code>
                  </pre>
                </Popover.Content>
              </Callout.Root>
            </Popover.Root>
          </Box>
        ))}
      </Box>
    );
  }
};

export default ReviewErrors;
