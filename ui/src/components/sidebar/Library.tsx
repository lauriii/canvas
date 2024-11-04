import * as Accordion from '@radix-ui/react-accordion';
import { TriangleRightIcon } from '@radix-ui/react-icons';
import styles from '@/components/sidebar/PrimaryPanel.module.css';
import ComponentList from '@/components/list/ComponentList';
import SectionList from '@/components/list/SectionList';
import clsx from 'clsx';
import { Heading, Text } from '@radix-ui/themes';
import type { FC, ReactNode } from 'react';
import {
  selectOpenLayoutItems,
  setOpenLayoutItem,
  setCloseLayoutItem,
  LayoutItemType,
} from '@/features/ui/primaryPanelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';

const Library = () => {
  const openItems = useAppSelector(selectOpenLayoutItems);
  const dispatch = useAppDispatch();

  const AccordionTrigger: FC<{ children: ReactNode; nodeType: string }> = ({
    children,
    nodeType,
  }) => {
    const onClickHandler = () => {
      // If the item is already open, close it
      if (openItems.includes(nodeType)) {
        dispatch(setCloseLayoutItem(nodeType));
      } else {
        dispatch(setOpenLayoutItem(nodeType));
      }
    };

    return (
      <Accordion.Header className={styles.header} asChild={true}>
        <Heading as="h3" trim="start" my="2">
          <Accordion.Trigger
            onClick={onClickHandler}
            className={clsx(styles.trigger)}
          >
            <Text size="2">
              <TriangleRightIcon className={styles.chevron} aria-hidden />
              {children}
            </Text>
          </Accordion.Trigger>
        </Heading>
      </Accordion.Header>
    );
  };

  return (
    <div>
      <Accordion.Root
        type="multiple"
        value={openItems}
        onValueChange={() => setOpenLayoutItem}
      >
        <Accordion.Item value={LayoutItemType.SECTION}>
          <AccordionTrigger nodeType={LayoutItemType.SECTION}>
            Sections
          </AccordionTrigger>
          <Accordion.Content>
            <Text size="1">
              The section template listed below is hard coded and is a proof of
              concept. It should allow the user to add a hero with an image
              below it in a single action.
            </Text>
            <ErrorBoundary title="An unexpected error has occurred while fetching section templates.">
              <SectionList />
            </ErrorBoundary>
          </Accordion.Content>
        </Accordion.Item>
        <Accordion.Item value={LayoutItemType.COMPONENT}>
          <AccordionTrigger nodeType={LayoutItemType.COMPONENT}>
            Components
          </AccordionTrigger>
          <Accordion.Content>
            <ErrorBoundary title="An unexpected error has occurred while fetching components.">
              <ComponentList />
            </ErrorBoundary>
          </Accordion.Content>
        </Accordion.Item>
      </Accordion.Root>
    </div>
  );
};

export default Library;
