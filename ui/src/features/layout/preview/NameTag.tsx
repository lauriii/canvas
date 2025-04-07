import styles from './NameTag.module.css';
import clsx from 'clsx';
import { BoxModelIcon, Component1Icon, CubeIcon } from '@radix-ui/react-icons';
import { useAppSelector } from '@/app/hooks';
import {
  selectDragging,
  selectIsComponentHovered,
  selectNoComponentIsHovered,
  selectTargetSlot,
} from '@/features/ui/uiSlice';
import useXbParams from '@/hooks/useXbParams';

const VARIANTS = {
  component: <Component1Icon width={10} height={10} />,
  root: <CubeIcon width={10} height={10} />,
  slot: <BoxModelIcon width={10} height={10} />,
};

interface NameTagProps {
  name: string;
  id: string;
  nodeType: string;
}

const NameTag: React.FC<NameTagProps> = (props) => {
  const { name, nodeType, id } = props;
  const { componentId: selectedComponent } = useXbParams();
  const { isDragging } = useAppSelector(selectDragging);
  const isSelected = id === selectedComponent;
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, id);
  });
  const noComponentIsHovered = useAppSelector(selectNoComponentIsHovered);
  const targetSlot = useAppSelector(selectTargetSlot);
  const isTarget = targetSlot === id;

  // Show the name of the hovered component or selected component when nothing else is hovered.
  // Desired result is that only one NameTag is shown at a time - either the selected or the hovered component.
  // Hide NameTags while dragging but show the NameTag of the target slot
  const showName =
    isTarget ||
    (!targetSlot && isSelected && noComponentIsHovered) ||
    (!targetSlot && isHovered && !isDragging);

  if (!showName) {
    return null;
  }

  return (
    <div
      data-testid="xb-name-tag"
      className={clsx(styles.nameTag, {
        [styles.slot]: nodeType === 'slot' || nodeType === 'region',
        [styles.root]: nodeType === 'root',
      })}
    >
      {VARIANTS[nodeType as keyof typeof VARIANTS]}
      <span id={`${id}-name`}>{name}</span>
    </div>
  );
};

export default NameTag;
