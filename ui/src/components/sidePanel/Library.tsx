import { Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import {
  AccordionDetails,
  AccordionRoot,
} from '@/components/form/components/Accordion';
import ComponentList from '@/components/list/ComponentList';
import PatternList from '@/components/list/PatternList';
import PermissionCheck from '@/components/PermissionCheck';
import AddCodeComponentButton from '@/features/code-editor/AddCodeComponentButton';
import {
  LayoutItemType,
  selectOpenLayoutItems,
  setCloseLayoutItem,
  setOpenLayoutItem,
} from '@/features/ui/primaryPanelSlice';

import styles from './Library.module.css';

const Library = () => {
  const openItems = useAppSelector(selectOpenLayoutItems);
  const dispatch = useAppDispatch();

  const onClickHandler = (nodeType: string) => {
    // If the item is already open, close it
    if (openItems.includes(nodeType)) {
      dispatch(setCloseLayoutItem(nodeType));
    } else {
      dispatch(setOpenLayoutItem(nodeType));
    }
  };

  return (
    <>
      <PermissionCheck hasPermission="codeComponents">
        <Flex direction="column" mb="4">
          <AddCodeComponentButton />
        </Flex>
      </PermissionCheck>
      <AccordionRoot value={openItems} onValueChange={() => setOpenLayoutItem}>
        <AccordionDetails
          value={LayoutItemType.PATTERN}
          title="Patterns"
          onTriggerClick={() => onClickHandler(LayoutItemType.PATTERN)}
          className={styles.accordionDetails}
          triggerClassName={styles.accordionDetailsTrigger}
        >
          <ErrorBoundary title="An unexpected error has occurred while fetching patterns.">
            <PatternList />
          </ErrorBoundary>
        </AccordionDetails>
        <AccordionDetails
          value={LayoutItemType.COMPONENT}
          title="Components"
          onTriggerClick={() => onClickHandler(LayoutItemType.COMPONENT)}
          className={styles.accordionDetails}
          triggerClassName={styles.accordionDetailsTrigger}
        >
          <ErrorBoundary title="An unexpected error has occurred while fetching components.">
            <ComponentList />
          </ErrorBoundary>
        </AccordionDetails>
      </AccordionRoot>
    </>
  );
};

export default Library;
