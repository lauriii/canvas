/**
 * Geometry of the dev chat's input box, and of the selected component chip
 * that sits over its top edge.
 *
 * AiWizardDev sizes deep-chat's input box from these values, and
 * SelectedComponentChip positions itself from them, so the input box and the
 * chip cannot drift apart. The "Page builder agent turns" Playwright test
 * checks that the chip sits inside the input box, above the typed text, in a
 * tall window and in a short one.
 */

// The input box's height, and its bottom padding, which holds the buttons.
export const TEXT_INPUT_HEIGHT = 167;
export const TEXT_INPUT_BOTTOM_PADDING = 40;

// Set by deep-chat itself: the input box's 1px border, and its 0.8em top and
// bottom margins at deep-chat's 14.4px font size.
const TEXT_INPUT_BORDER = 1;
const TEXT_INPUT_MARGIN = 11.5;

// The chip's height is the preview name tag's (.nameTag in
// NameTag.module.css). It sits CHIP_INSET in from the input box's top-left
// corner.
const CHIP_HEIGHT = 20;
export const CHIP_INSET = 8;

// The distance from the bottom of deep-chat to the top of the input box.
const TEXT_INPUT_TOP_FROM_BOTTOM =
  TEXT_INPUT_MARGIN +
  2 * TEXT_INPUT_BORDER +
  TEXT_INPUT_HEIGHT +
  TEXT_INPUT_BOTTOM_PADDING;

// The chip's top, relative to deep-chat's top. The input box sits at the
// bottom of deep-chat. When deep-chat is shorter than the input box and its
// margins, for example in a short window, the box stays one margin below
// deep-chat's top and its bottom is cut off, so the chip stays there too.
export const CHIP_TOP = `max(${TEXT_INPUT_MARGIN + CHIP_INSET}px, calc(100% - ${TEXT_INPUT_TOP_FROM_BOTTOM - CHIP_INSET}px))`;

// The typed text's top padding while the chip is shown: below the chip, with
// the same gap as the inset.
export const TEXT_PADDING_TOP_WITH_CHIP = CHIP_INSET + CHIP_HEIGHT + CHIP_INSET;
