import { useState } from 'react';
import { PlusIcon } from '@radix-ui/react-icons';
import { Button, Flex } from '@radix-ui/themes';

import ErrorBoundary from '@/components/error/ErrorBoundary';
import {
  AccordionDetails,
  AccordionRoot,
} from '@/components/form/components/Accordion';
import TemplateList from '@/components/list/TemplateList';
import PermissionCheck from '@/components/PermissionCheck';

const Templates = () => {
  const [openEntityTypes, setOpenEntityTypes] = useState<string[]>([
    'content-types',
  ]);

  const onClickHandler = (categoryName: string) => {
    setOpenEntityTypes((state) => {
      if (!state.includes(categoryName)) {
        return [...state, categoryName];
      }
      return state.filter((stateName) => stateName !== categoryName);
    });
  };

  return (
    <>
      <PermissionCheck hasPermission="contentTemplates">
        <Flex direction="column" mb="4">
          <AddTemplateButton />
        </Flex>
      </PermissionCheck>
      <AccordionRoot value={openEntityTypes}>
        <AccordionDetails
          value="content-types"
          title="Content types"
          onTriggerClick={() => onClickHandler('content-types')}
        >
          <ErrorBoundary title="An unexpected error has occurred while fetching templates.">
            <TemplateList />
          </ErrorBoundary>
        </AccordionDetails>
      </AccordionRoot>
    </>
  );
};

const AddTemplateButton = () => {
  return (
    <Button variant="soft" size="1">
      <PlusIcon />
      Add new template
    </Button>
  );
};

export default Templates;
