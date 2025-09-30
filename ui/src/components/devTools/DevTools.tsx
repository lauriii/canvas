import { useState } from 'react';
import { JSONTree } from 'react-json-tree';
import { CommitIcon } from '@radix-ui/react-icons';
import { Box, Button } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';

const theme = {
  scheme: 'github-light-custom',
  author: 'canvas',
  base00: 'transparent',
  base01: '#f6f8fa',
  base02: '#e1e4e8',
  base03: '#d1d5da',
  base04: '#6a737d',
  base05: '#24292e',
  base06: '#6e7781',
  base07: '#116329',
  base08: '#d73a49',
  base09: '#e36209',
  base0A: '#005cc5',
  base0B: '#6f42c1',
  base0C: '#22863a',
  base0D: '#0366d6',
  base0E: '#032f62',
  base0F: '#b31d28',
};

const DevTools = () => {
  const [isOpen, setIsOpen] = useState(false);
  const state = useAppSelector((state) => state);

  const filteredState = Object.fromEntries(
    Object.entries(state).filter(([key]) => !key.endsWith('Api')),
  );

  const toggleDialog = () => {
    setIsOpen(!isOpen);
  };

  return (
    <>
      <Box position="absolute" bottom="var(--space-2)" right="var(--space-3)">
        <Button variant="ghost" size="1" color="indigo" onClick={toggleDialog}>
          <CommitIcon />
        </Button>
      </Box>
      <Dialog
        open={isOpen}
        onOpenChange={setIsOpen}
        title="Redux state"
        modal={false}
        headerClose={true}
        footer={{ hidden: true }}
      >
        <JSONTree
          data={filteredState}
          theme={{
            extend: theme,
            tree: {
              fontSize: 'var(--font-size-1)',
            },
          }}
          hideRoot={true}
        />
      </Dialog>
    </>
  );
};

export default DevTools;
