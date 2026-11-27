import { Flex, Tabs } from '@radix-ui/themes';

import BrandKitColorsSection from '@/features/brandKit/BrandKitColorsSection';
import BrandKitFontsSection from '@/features/brandKit/BrandKitFontsSection';
import BrandKitStyleGuideSection from '@/features/brandKit/BrandKitStyleGuideSection';
import { BRAND_KIT_ID } from '@/features/brandKit/constants';
import { useGetBrandKitQuery } from '@/services/brandKit';

import styles from './BrandKitPanel.module.css';

const BrandKitPanel = () => {
  const { data: brandKit } = useGetBrandKitQuery(BRAND_KIT_ID);
  // Hidden on core versions without the design tokens API.
  const hasStyleGuide = !!brandKit?.designTokens?.available;
  return (
    <Tabs.Root defaultValue="colors">
      <Tabs.List justify="start" mt="-2" size="1">
        <Tabs.Trigger
          value="colors"
          data-testid="canvas-brand-kit-colors-tab-select"
        >
          Colors
        </Tabs.Trigger>
        <Tabs.Trigger
          value="fonts"
          data-testid="canvas-brand-kit-fonts-tab-select"
        >
          Fonts
        </Tabs.Trigger>
        {hasStyleGuide && (
          <Tabs.Trigger
            value="style-guide"
            data-testid="canvas-brand-kit-style-guide-tab-select"
          >
            Style guide
          </Tabs.Trigger>
        )}
      </Tabs.List>
      <Flex py="2" className={styles.tabWrapper}>
        <Tabs.Content
          value="colors"
          className={styles.tabContent}
          data-testid="canvas-brand-kit-colors-tab-content"
        >
          <BrandKitColorsSection />
        </Tabs.Content>
        <Tabs.Content
          value="fonts"
          className={styles.tabContent}
          data-testid="canvas-brand-kit-fonts-tab-content"
        >
          <BrandKitFontsSection />
        </Tabs.Content>
        {hasStyleGuide && (
          <Tabs.Content
            value="style-guide"
            className={styles.tabContent}
            data-testid="canvas-brand-kit-style-guide-tab-content"
          >
            <BrandKitStyleGuideSection />
          </Tabs.Content>
        )}
      </Flex>
    </Tabs.Root>
  );
};

export default BrandKitPanel;
