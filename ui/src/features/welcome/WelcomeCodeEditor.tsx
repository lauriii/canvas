import { InfoCircledIcon } from '@radix-ui/react-icons';
import { Callout, Flex } from '@radix-ui/themes';

import PermissionCheck from '@/components/PermissionCheck';

import type React from 'react';

const Welcome: React.FC<{ children?: React.ReactNode }> = () => {
  return (
    <Flex align="center" justify="center" width="100%">
      <Flex maxWidth="400px" width="">
        <Callout.Root color="blue">
          <Callout.Icon>
            <InfoCircledIcon />
          </Callout.Icon>
          <PermissionCheck
            hasPermission="codeComponents"
            denied={
              <Callout.Text>
                You do not have permission to access the code editor.
              </Callout.Text>
            }
          >
            <Callout.Text>
              Welcome to the Code Editor! To get started, select a component
              from the panel on the left or create something new from the
              dropdown at the top.
            </Callout.Text>
          </PermissionCheck>
        </Callout.Root>
      </Flex>
    </Flex>
  );
};

export default Welcome;
