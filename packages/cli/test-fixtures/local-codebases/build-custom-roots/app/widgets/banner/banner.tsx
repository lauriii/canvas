import Cta from '@/components/cta';
import { label } from '@/content/label';

import heroImage from './hero.webp';

export default function Banner() {
  return (
    <section>
      <img alt="" src={heroImage} />
      <span>{label}</span>
      <Cta />
    </section>
  );
}
