import { Box } from '@radix-ui/themes';

/**
 * Shared page shell of the segment pages: a scrollable full-width surface
 * with the content centered at a readable width.
 */
export default function SegmentPanel({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <Box width="100%" height="100%" overflowY="auto">
      <Box maxWidth="960px" mx="auto" px="6" py="6">
        {children}
      </Box>
    </Box>
  );
}
