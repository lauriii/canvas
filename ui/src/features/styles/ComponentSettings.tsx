import { useCallback, useState } from 'react';
import { useParams } from 'react-router';
import { Badge, Button, Flex, Tabs, Text } from '@radix-ui/themes';

import ComponentInstanceForm from '@/components/ComponentInstanceForm';
import { useStyleDecorations } from '@/features/styles/styleDecorations';
import { useStylePartFocus } from '@/features/styles/stylePartFocus';
import StyleTab from '@/features/styles/StyleTab';
import { useComponentStyles } from '@/features/styles/useComponentStyles';

import styles from './StyleTab.module.css';

/**
 * The tab the editor used last. The first selection opens on Content; later
 * selections keep the last-used tab for the session, so a styling pass
 * across many instances stays on Style.
 */
let lastTab: 'content' | 'style' = 'content';

/**
 * A component instance's settings: Content (the instance form) and, when
 * styles are active, Style.
 */
const ComponentSettings = () => {
  const { componentId: uuid } = useParams();
  const { active, selection, component } = useComponentStyles(uuid);
  const { header } = useStyleDecorations(uuid);
  const [tab, setTab] = useState(lastTab);
  // A part chosen from the preview opens on Style.
  useStylePartFocus(
    uuid,
    useCallback(() => {
      lastTab = 'style';
      setTab('style');
    }, []),
  );

  if (!active || !uuid || !component) {
    return <ComponentInstanceForm />;
  }

  const setCount = Object.keys(selection).length;

  return (
    <Flex direction="column" data-testid="canvas-component-settings">
      {header && (
        <Flex
          direction="column"
          gap="1"
          mt="3"
          data-testid="canvas-component-settings-header"
        >
          <Flex justify="between" align="center" gap="2">
            <Text size="2" weight="bold">
              {header.title}
            </Text>
            {header.action && (
              <Button size="1" variant="soft" onClick={header.action.onSelect}>
                {header.action.label}
              </Button>
            )}
          </Flex>
          {header.description && (
            <Text size="1" color="gray">
              {header.description}
            </Text>
          )}
        </Flex>
      )}
      <Tabs.Root
        value={tab}
        onValueChange={(next) => {
          lastTab = next as typeof lastTab;
          setTab(lastTab);
        }}
      >
        <Tabs.List size="1" mt="2">
          <Tabs.Trigger
            value="content"
            data-testid="canvas-settings-tab-content"
          >
            Content
          </Tabs.Trigger>
          <Tabs.Trigger value="style" data-testid="canvas-settings-tab-style">
            Style
            {setCount > 0 && (
              <Badge
                size="1"
                radius="full"
                className={styles.tabBadge}
                aria-label={`${setCount} set`}
              >
                {setCount}
              </Badge>
            )}
          </Tabs.Trigger>
        </Tabs.List>
        {/* Keep the form mounted so its state survives tab switches. */}
        <Tabs.Content value="content" forceMount hidden={tab !== 'content'}>
          <ComponentInstanceForm />
        </Tabs.Content>
        <Tabs.Content value="style">
          <StyleTab uuid={uuid} />
        </Tabs.Content>
      </Tabs.Root>
    </Flex>
  );
};

export default ComponentSettings;
