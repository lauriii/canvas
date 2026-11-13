import { getSiteData } from 'drupal-canvas';

import Button from '@/components/button';
import bannerImage from '@/lib/banner.jpg';
import { formatTitle } from '@/lib/format';
import { useCardLabel } from '@/lib/labels';

import heroImage from './hero.webp';

interface CardProps {
  title?: string;
}

export default function Card({ title = 'Hello world' }: CardProps) {
  const siteData = getSiteData();
  const formattedTitle = formatTitle(title);
  const label = useCardLabel();

  return (
    <article className={`card ${formattedTitle}`}>
      <img alt="" src={heroImage} />
      <img alt="" src={bannerImage} />
      <span>{siteData.branding?.name}</span>
      <span>{label}</span>
      <Button label={formattedTitle} />
    </article>
  );
}
