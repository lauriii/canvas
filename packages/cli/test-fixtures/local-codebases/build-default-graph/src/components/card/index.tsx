import { getSiteData } from 'drupal-canvas';

import Button from '@/components/button';
import bannerImage from '@/lib/banner.jpg';
import { formatTitle } from '@/lib/format';

import heroImage from './hero.webp';

interface CardProps {
  title?: string;
}

export default function Card({ title = 'Hello world' }: CardProps) {
  const siteData = getSiteData();
  const formattedTitle = formatTitle(title);

  return (
    <article className={`card ${formattedTitle}`}>
      <img alt="" src={heroImage} />
      <img alt="" src={bannerImage} />
      <span>{siteData.branding?.name}</span>
      <Button label={formattedTitle} />
    </article>
  );
}
