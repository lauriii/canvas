import { Text } from '@radix-ui/themes';
import { a2p } from '@/local_packages/utils';
import type { PropsValues } from '@/types/Form';

// This renders `<xb-text>` components used in Twig templates.
const XbText = (props: PropsValues) => {
  const { children, ...remainingProps } = props;
  return <Text {...a2p(remainingProps)}>{children}</Text>;
};

export default XbText;
