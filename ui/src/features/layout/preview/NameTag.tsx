import styles from './NameTag.module.css';
import clsx from 'clsx';
import { BoxModelIcon, Component1Icon, CubeIcon } from '@radix-ui/react-icons';

const VARIANTS = {
  component: <Component1Icon width={10} height={10} />,
  root: <CubeIcon width={10} height={10} />,
  slot: <BoxModelIcon width={10} height={10} />,
};

interface NameTagProps {
  name: string;
  componentUuid: string;
  selected: boolean;
  nodeType: string;
}

const NameTag: React.FC<NameTagProps> = (props) => {
  const { name, selected, nodeType, componentUuid } = props;

  return (
    <div
      className={clsx(styles.nameTag, {
        [styles.selected]: selected,
        [styles.slot]: nodeType === 'slot' || nodeType === 'region',
        [styles.root]: nodeType === 'root',
      })}
    >
      {VARIANTS[nodeType as keyof typeof VARIANTS]}
      <span id={`${componentUuid}-name`}>{name}</span>
    </div>
  );
};

export default NameTag;
