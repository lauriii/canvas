import { useEffect } from 'react';
import clsx from 'clsx';
import { Component1Icon, Cross2Icon } from '@radix-ui/react-icons';
import { Tooltip } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import { selectSelectedComponentUuid } from '@/features/ui/uiSlice';
import useGetComponentName from '@/hooks/useGetComponentName';
import { getDrupalSettings } from '@/utils/drupal-globals';

import { CHIP_INSET, CHIP_TOP } from './chatInputLayout';

import type { RefObject } from 'react';

import nameTagStyles from '@/features/layout/preview/NameTag.module.css';
import styles from './SelectedComponentChip.module.css';

// Over the top of deep-chat's input box, CHIP_INSET in from its corner. The
// maximum width keeps a long name from widening the chat panel.
const CHIP_POSITION = {
  top: CHIP_TOP,
  left: CHIP_INSET,
  maxWidth: `calc(100% - ${2 * CHIP_INSET}px)`,
} as const;

// The scope of a running turn, fixed when its message is sent.
export interface TurnScope {
  // The component that was selected when the message was sent, or null when
  // none was, or more than one.
  componentUuid: string | null;
}

interface SelectedComponentChipProps {
  // The running turn's scope, or null while no turn runs.
  turnScope: TurnScope | null;
  // The <deep-chat> element. Its auxiliary style moves the typed text below
  // the chip while the element carries the data-has-selection attribute.
  chatElementRef: RefObject<HTMLElement | null>;
}

// The selected component, shown over the top of the chat input box with a
// button that clears the selection. It looks like the name tag the preview
// draws above a selected component. Rendered outside deep-chat, and subscribed
// to the selection itself, so a selection change cannot re-render
// MemoDeepChat.
const SelectedComponentChip = ({
  turnScope,
  chatElementRef,
}: SelectedComponentChipProps) => {
  // While a turn runs, the chip shows the turn's scope, whatever component it
  // holds. The agent selects each component it places, so following the
  // selection would show those placements instead.
  const isLocked = turnScope !== null;
  const selectedUuid = useAppSelector(selectSelectedComponentUuid);
  const uuid = isLocked ? turnScope.componentUuid : selectedUuid;
  // Selects the node, not the layout, so a layout edit elsewhere (every
  // placement during a turn) does not re-render the chip.
  const node = useAppSelector((state) =>
    uuid ? findComponentByUuid(selectLayout(state), uuid) : null,
  );
  const name = useGetComponentName(node);
  const isShown = name !== '';

  useEffect(() => {
    const chatEl = chatElementRef.current;
    chatEl?.toggleAttribute('data-has-selection', isShown);
    return () => chatEl?.removeAttribute('data-has-selection');
  }, [chatElementRef, isShown]);

  if (!isShown) {
    return null;
  }

  const deselect = () => {
    // Read when clicked: useComponentSelection() replaces these utils on every
    // render, and their handlers navigate from the route of that render.
    getDrupalSettings().canvas.componentSelectionUtils.unsetSelectedComponent();
  };

  return (
    <div
      className={clsx(nameTagStyles.nameTag, styles.chip)}
      style={CHIP_POSITION}
      data-testid="canvas-ai-selected-component"
    >
      <Component1Icon width={10} height={10} className={styles.icon} />
      <Tooltip content={`Selected component: ${name}`}>
        <span className={styles.name}>
          <span className="visually-hidden">Selected component: </span>
          {name}
        </span>
      </Tooltip>
      <Tooltip content="Deselect">
        <button
          type="button"
          aria-label={`Deselect ${name}`}
          className={styles.deselectButton}
          onClick={deselect}
          disabled={isLocked}
        >
          <Cross2Icon width={10} height={10} />
        </button>
      </Tooltip>
    </div>
  );
};

export default SelectedComponentChip;
