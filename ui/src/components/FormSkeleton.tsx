import { Flex, Skeleton } from '@radix-ui/themes';

/**
 * Placeholder shaped like form fields, shown while a form loads.
 */
const FormSkeleton = () => (
  <Flex direction="column" gap="4" pt="5">
    {[40, 55, 30].map((labelWidth) => (
      <Flex key={labelWidth} direction="column" gap="2">
        <Skeleton height="0.8rem" width={`${labelWidth}%`} />
        <Skeleton height="2rem" width="100%" />
      </Flex>
    ))}
  </Flex>
);

export default FormSkeleton;
