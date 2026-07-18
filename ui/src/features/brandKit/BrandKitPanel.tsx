import { Flex } from '@radix-ui/themes';

import BrandKitFontsSection from '@/features/brandKit/BrandKitFontsSection';
import BrandKitIconsSection from '@/features/brandKit/BrandKitIconsSection';

const BrandKitPanel = () => (
  <Flex direction="column" gap="4">
    <BrandKitFontsSection />
    <BrandKitIconsSection />
  </Flex>
);

export default BrandKitPanel;
