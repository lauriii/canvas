import type React from 'react';
import {
  AccordionRoot,
  AccordionDetails,
} from '@/components/form/components/Accordion';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import { setOpenLayoutItem } from '@/features/ui/primaryPanelSlice';
import styles from '@/components/sidebar/Library.module.css';
import { useState } from 'react';
import List from '@/components/list/List';
import type { ComponentsList } from '@/types/Component';

interface LibraryProps {
  dynamicComponents: ComponentsList;
}

const Library: React.FC<LibraryProps> = ({ dynamicComponents }) => {
  const [openCategories, setOpenCategories] = useState<string[]>([]);

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
            onTriggerClick={() => onClickHandler(category.id)}
            className={styles.accordionDetails}
            triggerClassName={styles.accordionDetailsTrigger}
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
                isLoading={false}
                type="component"
                label="Components"
              />
            </ErrorBoundary>
          </AccordionDetails>
        ))}
      </AccordionRoot>
    </>
  );
};

export default Library;
