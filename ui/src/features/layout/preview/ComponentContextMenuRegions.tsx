// cspell:ignore CCMR

import { ContextMenu } from '@radix-ui/themes';
import type React from 'react';

interface CCMRProps {}

const ComponentContextMenuRegions: React.FC<CCMRProps> = () => {
  return (
    <ContextMenu.Sub>
      <ContextMenu.SubTrigger>Move to global region</ContextMenu.SubTrigger>
      <ContextMenu.SubContent>
        <ContextMenu.Item
          onClick={() => alert('Todo in https://drupal.org/i/3494687')}
        >
          Header
        </ContextMenu.Item>
        <ContextMenu.Item
          onClick={() => alert('Todo in https://drupal.org/i/3494687')}
        >
          Content
        </ContextMenu.Item>
        <ContextMenu.Item
          onClick={() => alert('Todo in https://drupal.org/i/3494687')}
        >
          Footer
        </ContextMenu.Item>
      </ContextMenu.SubContent>
    </ContextMenu.Sub>
  );
};

export default ComponentContextMenuRegions;
