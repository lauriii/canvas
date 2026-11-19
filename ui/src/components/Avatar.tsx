import * as Avatar from '@radix-ui/react-avatar';

import styles from './Avatar.module.css';

const AvatarComponent = ({
  imageUrl,
  name,
  color,
}: {
  name: string;
  imageUrl?: string;
  /** A Radix Themes color name; the theme's accent color when omitted. */
  color?: string;
}) => {
  const initial = name.trim().charAt(0).toUpperCase();
  return (
    <Avatar.Root className={styles.root} data-accent-color={color}>
      {imageUrl && <Avatar.Image className={styles.image} src={imageUrl} />}
      {!imageUrl && (
        <Avatar.Fallback className={styles.fallback}>{initial}</Avatar.Fallback>
      )}
    </Avatar.Root>
  );
};

export default AvatarComponent;
