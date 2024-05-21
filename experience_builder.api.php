<?php

/**
 * @file
 * Documentation related to Experience Builder.
 */

/**
 * @defgroup experience_builder_architecture Experience Builder Architecture
 * @{
 *
 * @section prop_expressions Prop Expressions
 *
 * Since instantiated components in:
 * - content type templates
 * - content entities
 * must be able to map values from structured data (entity field props) into
 * component props, and many APIs and layers are involved in doing this:
 * - correctly
 * - securely
 * - performant
 * It seems sensible to use a strongly typed approach to representing these
 * expressions.
 *
 * Furthermore, the Experience Builder UX must make it easy to surface viable
 * matches from the structured data that can fit in the components, as well as
 * the other way around.
 *
 * Therefore a base expression interface is provided, which guarantees a
 * stringable representation (simplifying both debugging as well as storing
 * these expressions), *and* the conversion back.
 * In other words: every possible expression used by Experience Builder can
 * always be converted from string to PHP object and vice versa.
 *
 * String representations of prop expressions probing into:
 * - components will always start with the symbol `⿲`
 * - structured data will always start with the symbol `ℹ`
 *
 *
 * String and storage representation of expressions referencing field types,
 * field instances, fields aka field item lists, field deltas aka field items,
 * field item properties:
 * - `␟` is the field item VS property name separator, because a field property
 *   is the smallest unit
 * - `␞` then is the field item list vs field item separator
 * - `␝` then is the field item list vs field item separator
 *
 * @see https://github.com/SixArm/usv
 *
 * @}
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * @} End of "addtogroup hooks".
 */
