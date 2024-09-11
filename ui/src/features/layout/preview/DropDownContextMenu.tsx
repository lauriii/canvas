import { DropdownMenu } from '@radix-ui/themes';
import { useAppDispatch } from '@/app/hooks';
import {
  setIsContextMenuOpen,
  setSelectedComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import {
  deleteNode,
  duplicateNode,
  shiftNode,
} from '@/features/layout/layoutModelSlice';

export interface DropDownContextMenuProps {
  elementId: string;
  contextMenuPosition: {
    x: number;
    y: number;
  };
  contextMenuOpen: string | boolean;
}

const DropDownContextMenu: React.FC<DropDownContextMenuProps> = (props) => {
  const { elementId, contextMenuPosition, contextMenuOpen } = props;
  const dispatch = useAppDispatch();
  function handleDeleteClick() {
    dispatch(setIsContextMenuOpen(undefined));
    dispatch(unsetHoveredComponent());
    if (elementId) {
      dispatch(deleteNode(elementId));
    }
  }
  function handleSelectClick() {
    dispatch(unsetHoveredComponent());
    dispatch(setIsContextMenuOpen(undefined));
    if (elementId) {
      dispatch(setSelectedComponent(elementId));
    }
  }
  function handleDuplicateClick() {
    dispatch(setIsContextMenuOpen(undefined));
    dispatch(unsetHoveredComponent());

    if (elementId) {
      dispatch(duplicateNode({ uuid: elementId }));
    }
  }

  function handleMoveUpClick() {
    dispatch(setIsContextMenuOpen(undefined));
    dispatch(unsetHoveredComponent());

    dispatch(shiftNode({ uuid: elementId, direction: 'up' }));
  }

  function handleMoveDownClick() {
    dispatch(setIsContextMenuOpen(undefined));
    dispatch(unsetHoveredComponent());

    dispatch(shiftNode({ uuid: elementId, direction: 'down' }));
  }

  return (
    <>
      <DropdownMenu.Root open={!!contextMenuOpen}>
        <DropdownMenu.Content
          style={{
            top: contextMenuPosition.y,
            left: contextMenuPosition.x,
            position: 'absolute',
            pointerEvents: 'all',
          }}
        >
          <DropdownMenu.Item onClick={handleSelectClick}>
            Edit
          </DropdownMenu.Item>
          <DropdownMenu.Item onClick={handleDuplicateClick}>
            Duplicate
          </DropdownMenu.Item>
          <DropdownMenu.Separator />

          <DropdownMenu.Sub>
            <DropdownMenu.SubTrigger>Move</DropdownMenu.SubTrigger>
            <DropdownMenu.SubContent>
              <DropdownMenu.Item onClick={handleMoveUpClick}>
                Move up
              </DropdownMenu.Item>
              <DropdownMenu.Item onClick={handleMoveDownClick}>
                Move down
              </DropdownMenu.Item>

              <DropdownMenu.Separator />
              <DropdownMenu.Item onClick={() => alert('Todo')}>
                Move into
              </DropdownMenu.Item>
            </DropdownMenu.SubContent>
          </DropdownMenu.Sub>
          <DropdownMenu.Separator />
          <DropdownMenu.Item
            // shortcut="⌘ ⌫"
            color="red"
            onClick={handleDeleteClick}
          >
            Delete
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Root>
    </>
  );
};

export default DropDownContextMenu;
