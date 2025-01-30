import { Flex } from '@radix-ui/themes';
import {
  AccordionRoot,
  AccordionDetails,
} from '@/components/form/components/Accordion';
import ComponentList from '@/components/list/ComponentList';
import SectionList from '@/components/list/SectionList';
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
      <Flex direction="column" mb="4">
        <AddCodeComponentButton />
      </Flex>
      <AccordionRoot value={openItems} onValueChange={() => setOpenLayoutItem}>
        <AccordionDetails
          value={LayoutItemType.SECTION}
          title="Sections"
          onTriggerClick={() => onClickHandler(LayoutItemType.SECTION)}
          className={styles.accordionDetails}
          triggerClassName={styles.accordionDetailsTrigger}
        >
          <ErrorBoundary title="An unexpected error has occurred while fetching sections.">
            <SectionList />
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
        <AccordionDetails
          value="code"
          title="Code"
          onTriggerClick={() => onClickHandler('code')}
          className={styles.accordionDetails}
          triggerClassName={styles.accordionDetailsTrigger}
        >
          <ErrorBoundary title="An unexpected error has occurred while fetching code components.">
            <CodeComponentList />
          </ErrorBoundary>
        </AccordionDetails>
      </AccordionRoot>
    </>
  );
};

export default Library;
