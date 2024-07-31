import { isRouteErrorResponse, useRouteError } from 'react-router-dom';
import { Card, Heading, Text, Flex } from '@radix-ui/themes';
import type React from 'react';
import { ExclamationTriangleIcon } from '@radix-ui/react-icons';

function errorMessage(error: unknown): string {
  if (isRouteErrorResponse(error)) {
    return `${error.status} ${error.statusText}`;
  } else if (error instanceof Error) {
    return error.message;
  } else if (typeof error === 'string') {
    return error;
  } else {
    console.error(error);
    return 'Unknown error';
  }
}

const ErrorPage: React.FC = () => {
  const error = errorMessage(useRouteError());
  console.error(error);

  // @todo Awaiting design: This HTML is not final/approved, just hashed together quickly.

  return (
    <Flex align="center" justify="center" height="100vh" id="error-page">
      <Card id="error-page" variant="surface" size="4">
        <Heading as="h1" mb="2">
          <ExclamationTriangleIcon width="16" height="16" /> Oops!
        </Heading>
        <Text as="p">Sorry, an unexpected error has occurred.</Text>
        <Text as="p">{error}</Text>
      </Card>
    </Flex>
  );
};

export default ErrorPage;
