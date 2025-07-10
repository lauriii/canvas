import { Flex } from '@radix-ui/themes';
import {
  AccordionRoot,
  AccordionDetails,
} from '@/components/form/components/Accordion';
import ComponentList from '@/components/list/ComponentList';
import PatternList from '@/components/list/PatternList';
import CodeComponentList from '@/features/code-editor/CodeComponentList';
import AddCodeComponentButton from '@/features/code-editor/AddCodeComponentButton';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import {
  selectOpenLayoutItems,
  setOpenLayoutItem,
  setCloseLayoutItem,
  LayoutItemType,
} from '@/features/ui/primaryPanelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import styles from './Library.module.css';
import DynamicComponentList from '@/components/list/DynamicComponentList';
import PermissionCheck from '@/components/PermissionCheck';

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
        <PermissionCheck hasPermission="codeComponents">
          <AccordionDetails
            value={LayoutItemType.CODE}
            title="Code"
            onTriggerClick={() => onClickHandler(LayoutItemType.CODE)}
            className={styles.accordionDetails}
            triggerClassName={styles.accordionDetailsTrigger}
          >
            <ErrorBoundary title="An unexpected error has occurred while fetching code components.">
              <CodeComponentList />
            </ErrorBoundary>
          </AccordionDetails>
        </PermissionCheck>
        <AccordionDetails
          value={LayoutItemType.DYNAMIC}
          title="Dynamic Components"
          onTriggerClick={() => onClickHandler(LayoutItemType.DYNAMIC)}
          className={styles.accordionDetails}
          triggerClassName={styles.accordionDetailsTrigger}
        >
          <ErrorBoundary title="An unexpected error has occurred while fetching dynamic components.">
            <DynamicComponentList />
          </ErrorBoundary>
        </AccordionDetails>
      </AccordionRoot>
    </>
  );
};

export default Library;
