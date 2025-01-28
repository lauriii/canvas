import {
  AccordionRoot,
  AccordionDetails,
} from '@/components/form/components/Accordion';
import ComponentList from '@/components/list/ComponentList';
import SectionList from '@/components/list/SectionList';
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
    <div>
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
      </AccordionRoot>
    </div>
  );
};

export default Library;
