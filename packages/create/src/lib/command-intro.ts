import chalk from 'chalk';
import * as p from '@clack/prompts';

const DRUPAL_BLUE = '#009CDE';
const DRUPAL_NAVY = '#12285F';
const DRUPAL_PURPLE = '#CCBAF4';

export function commandIntro(commandName: string): string {
  return `${chalk.bgHex(DRUPAL_BLUE).hex(DRUPAL_NAVY)(' Drupal Canvas ')}${chalk.bgHex(DRUPAL_PURPLE).hex(DRUPAL_NAVY)(` ${commandName} `)}`;
}

export function printCommandIntro(commandName: string): void {
  console.log();
  p.intro(commandIntro(commandName));
}
