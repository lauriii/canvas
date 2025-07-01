import { Box } from '@radix-ui/themes';
import { a2p } from '@/local_packages/utils';
import type { PropsValues } from '@/types/Form';

// This renders `<xb-box>` components used in Twig templates.
const XbBox = (props: PropsValues) => {
  const { children, ...remainingProps } = props;
  return <Box {...a2p(remainingProps)}>{children}</Box>;
};

export default XbBox;
