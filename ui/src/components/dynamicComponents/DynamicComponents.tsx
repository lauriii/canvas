import { Flex, Heading, Box, ScrollArea, Spinner } from '@radix-ui/themes';
import type React from 'react';

import DynamicComponentsLibrary from '@/components/dynamicComponents/DynamicComponentsLibrary';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import ErrorCard from '@/components/error/ErrorCard';

interface DynamicComponentsGroupsPopoverProps {}

const DynamicComponents: React.FC<DynamicComponentsGroupsPopoverProps> = () => {
  const {
    data: dynamicComponents,
    isLoading,
    isError,
    isFetching,
    refetch,
  } = useGetComponentsQuery({
    mode: 'include',
    libraries: ['dynamic_components'],
  });

  if (isLoading || isFetching) {
    return (
      <Flex justify="center" align="center">
        <Spinner />
      </Flex>
    );
  }

  return (
    <>
      <Flex justify="between">
        <Heading as="h3" size="3" mb="4">
          Dynamic Components
        </Heading>
      </Flex>
      <Box mr="-4">
        <ScrollArea style={{ maxHeight: '380px', width: '100%' }} type="scroll">
          <Box pr="4" mt="3">
            {isError && (
              <ErrorCard
                title="Error fetching Dynamic component list."
                resetErrorBoundary={refetch}
              />
            )}
            {!isError && dynamicComponents && (
              <DynamicComponentsLibrary dynamicComponents={dynamicComponents} />
            )}
          </Box>
        </ScrollArea>
      </Box>
    </>
  );
};

export default DynamicComponents;
