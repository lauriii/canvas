import { Flex, Tabs } from '@radix-ui/themes';

import BrandKitColorsSection from '@/features/brandKit/BrandKitColorsSection';
import BrandKitFontsSection from '@/features/brandKit/BrandKitFontsSection';
import BrandKitStylesSection from '@/features/brandKit/BrandKitStylesSection';
import { useGetStylesQuery } from '@/services/styles';

import styles from './BrandKitPanel.module.css';

const BrandKitPanel = () => {
  // The Styles tab only exists while component styles are active.
  const { data: stylesData } = useGetStylesQuery();
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
        {stylesData?.active && (
          <Tabs.Trigger
            value="styles"
            data-testid="canvas-brand-kit-styles-tab-select"
          >
            Styles
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
        {stylesData?.active && (
          <Tabs.Content
            value="styles"
            className={styles.tabContent}
            data-testid="canvas-brand-kit-styles-tab-content"
          >
            <BrandKitStylesSection />
          </Tabs.Content>
        )}
      </Flex>
    </Tabs.Root>
  );
};

export default BrandKitPanel;
