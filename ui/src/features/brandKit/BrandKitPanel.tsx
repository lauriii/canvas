import { Flex, Tabs } from '@radix-ui/themes';

import BrandKitColorsSection from '@/features/brandKit/BrandKitColorsSection';
import BrandKitFontsSection from '@/features/brandKit/BrandKitFontsSection';

import styles from './BrandKitPanel.module.css';

const BrandKitPanel = () => (
  <Tabs.Root defaultValue="colors">
    <Tabs.List justify="start" mt="-2" size="1">
      <Tabs.Trigger
        value="colors"
        data-testid="canvas-brand-kit-colors-tab-select"
      >
        {Drupal.t('Colors', {}, { context: 'Canvas brand kit' })}
      </Tabs.Trigger>
      <Tabs.Trigger
        value="fonts"
        data-testid="canvas-brand-kit-fonts-tab-select"
      >
        {Drupal.t('Fonts', {}, { context: 'Canvas brand kit' })}
      </Tabs.Trigger>
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
    </Flex>
  </Tabs.Root>
);

export default BrandKitPanel;
