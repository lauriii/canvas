import introVideo from '@/assets/intro.mp4';

import posterImage from '../../assets/poster.webp';
import heroImage from './hero.webp';

export default function Example() {
  return (
    <article>
      <img alt="" src={heroImage} />
      <img alt="" src={posterImage} />
      <video src={introVideo} />
    </article>
  );
}
