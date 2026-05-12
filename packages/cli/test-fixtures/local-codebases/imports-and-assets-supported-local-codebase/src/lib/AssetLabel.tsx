import posterUrl from '@/assets/poster.webp';

interface AssetLabelProps {
  children: string;
}

export function AssetLabel({ children }: AssetLabelProps) {
  return <img alt={children.trim()} src={posterUrl} />;
}
