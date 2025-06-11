import { useEffect, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useGetFilteredComponentsQuery } from '@/hooks/useGetFilteredComponentsQuery';
import List from '@/components/list/List';
import {
  LayoutItemType,
  setOpenLayoutItem,
} from '@/features/ui/primaryPanelSlice';
import {
  AccordionDetails,
  AccordionRoot,
} from '@/components/form/components/Accordion';
import styles from '@/components/sidePanel/Library.module.css';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import clsx from 'clsx';
import { Skeleton } from '@radix-ui/themes';

const DynamicComponentList = () => {
  const {
    filteredComponents: dynamicComponents,
    isLoading,
    error,
  } = useGetFilteredComponentsQuery({
    mode: 'include',
    libraries: ['dynamic_components'],
  });
  const { showBoundary } = useErrorBoundary();
  const [openCategories, setOpenCategories] = useState<string[]>([]);

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  if (isLoading) {
    return <Skeleton height="1.2rem" width="100%" my="3" />;
  }

  if (!dynamicComponents) {
    return null;
  }

  const categoriesSet = new Set<string>();
  Object.values(dynamicComponents).forEach((component) => {
    categoriesSet.add(component.category);
  });

  const categories = Array.from(categoriesSet)
    .map((categoryName) => {
      return {
        name: categoryName.replace(/\w/, (c) => c.toUpperCase()),
        id: categoryName,
      };
    })
    .sort((a, b) => a.name.localeCompare(b.name));

  const onClickHandler = (categoryName: string) => {
    setOpenCategories((state) => {
      if (!state.includes(categoryName)) {
        return [...state, categoryName];
      }
      return state.filter((stateName) => stateName !== categoryName);
    });
  };

  return (
    <>
      <AccordionRoot
        value={openCategories}
        onValueChange={() => setOpenLayoutItem}
      >
        {categories.map((category) => (
          <AccordionDetails
            key={category.id}
            value={category.id}
            title={category.name}
            size="1"
            onTriggerClick={() => onClickHandler(category.id)}
            className={clsx(styles.accordionDetails, styles.subCategory)}
            triggerClassName={styles.accordionDetailsTrigger}
            summaryAttributes={{
              'aria-label': `${category.name} dynamic components`,
            }}
          >
            <ErrorBoundary
              title={`An unexpected error has occurred while fetching ${category.name}.`}
            >
              <List
                // filtered dynamicComponents that match the current category
                items={Object.fromEntries(
                  Object.entries(dynamicComponents).filter(
                    ([key, component]) => component.category === category.id,
                  ),
                )}
                isLoading={isLoading}
                type={LayoutItemType.DYNAMIC}
                label="Dynamic Components"
              />
            </ErrorBoundary>
          </AccordionDetails>
        ))}
      </AccordionRoot>
    </>
  );
};

export default DynamicComponentList;
