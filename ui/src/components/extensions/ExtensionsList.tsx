import { Flex, Heading, Link, Grid, Spinner } from '@radix-ui/themes';
import { ExternalLinkIcon } from '@radix-ui/react-icons';
import ExtensionButton from '@/components/extensions/ExtensionButton';
import { handleNonWorkingBtn } from '@/utils/function-utils';
import type React from 'react';
import { useGetExtensionsQuery } from '@/services/extensions';
import ErrorCard from '@/components/error/ErrorCard';

interface ExtensionsPopoverProps {}

const ExtensionsList: React.FC<ExtensionsPopoverProps> = () => {
  const {
    data: extensions,
    isError,
    isLoading,
    refetch,
  } = useGetExtensionsQuery();

  return (
    <ExtensionsListDisplay
      extensions={extensions || []}
      isLoading={isLoading}
      isError={isError}
      refetch={refetch}
    />
  );
};

interface ExtensionsListDisplayProps {
  extensions: Array<any>;
  isLoading: boolean;
  isError: boolean;
  refetch: () => void;
}

const ExtensionsListDisplay: React.FC<ExtensionsListDisplayProps> = ({
  extensions,
  isLoading,
  isError,
  refetch,
}) => {
  return (
    <>
      <Flex justify="between">
        <Heading as="h3" size="3" mb="4">
          Extensions
        </Heading>

        <Flex justify="end" asChild>
          <Link
            size="1"
            href=""
            target="_blank"
            onClick={(e: React.MouseEvent<HTMLAnchorElement>) => {
              e.preventDefault();
              handleNonWorkingBtn();
            }}
          >
            Browse extensions&nbsp; <ExternalLinkIcon />
          </Link>
        </Flex>
      </Flex>
      {isError && (
        <ErrorCard
          error="Cannot display extensions, please try again."
          resetErrorBoundary={refetch}
          resetButtonText="Try again"
          title="Error loading extensions"
        />
      )}
      {isLoading && (
        <Flex justify="center">
          <Spinner />
        </Flex>
      )}
      {!isError && extensions && (
        <Grid columns="3" gap="3">
          {extensions.map((extension) => (
            <ExtensionButton extension={extension} key={extension.id} />
          ))}
        </Grid>
      )}
    </>
  );
};

export { ExtensionsListDisplay };

export default ExtensionsList;
