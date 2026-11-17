/**
 * Builds tailwind-merge's class map while a runner builds from the base.
 *
 * tailwind-merge builds the map on the first twMerge() call, not on import.
 * Calling it here moves that cost from the first island into the base, for
 * a runner that keeps the realm it built. The Deno runner doesn't: its
 * build's process is thrown away, so each request builds the map again.
 * The runner contract's spec says what a runner may keep from a base.
 * Output doesn't change. The call only leaves one item in twMerge's cache.
 *
 * @see docs/specs/semi-detailed/07-runner-contract/spec.md
 *
 * No render imports this. BaseModules adds it as a preload module.
 */
import { twMerge } from 'tailwind-merge';

twMerge('px-2 px-3');
