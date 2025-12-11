import { useState } from 'react';
import { Box, Flex } from '@radix-ui/themes';

import PermissionCheck from '@/components/PermissionCheck';
import LibraryToolbar from '@/components/sidePanel/LibraryToolbar';
import CodeComponentList from '@/features/code-editor/CodeComponentList';

// import styles from '@/components/sidePanel/Code.module.css';

const Code = () => {
  const [searchTerm, setSearchTerm] = useState('');

  return (
    <div className="flex flex-col h-full">
      <Flex py="2" width="100%">
        <PermissionCheck hasPermission="codeComponents">
          <Box width="100%" data-testid="canvas-code-panel-content">
            <LibraryToolbar
              type="js_component"
              searchTerm={searchTerm}
              onSearch={setSearchTerm}
              showNewMenu={true}
            />
            <CodeComponentList searchTerm={searchTerm} />
          </Box>
        </PermissionCheck>
      </Flex>
    </div>
  );
};

export default Code;
