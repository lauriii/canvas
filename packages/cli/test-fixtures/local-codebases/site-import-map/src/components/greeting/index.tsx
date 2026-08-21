// `example_module/useGreeting` is not an npm package. A Drupal module adds it
// to the Canvas import map through hook_canvas_importmap_alter(), so the
// browser resolves it at runtime and the CLI must not try to bundle it.
import clsx from 'clsx';
import { useGreeting } from 'example_module/useGreeting';

export default function Greeting() {
  return <p className={clsx('greeting')}>{useGreeting()}</p>;
}
