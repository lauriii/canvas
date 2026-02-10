// Set a timezone to get deterministic results in all time zones they run in (e.g. CI vs. local).
// @see https://github.com/vitest-dev/vitest/issues/1575#issuecomment-1439286286
//
// The time chosen here is during daylight savings for the timezone
// that core uses for testing (Australia/Sydney). This is by design so
// that we can test oddities like daylight savings.
// @see \canvas_test_article_fields_install().
// @see bootstrap.php
export const setup = () => {
  process.env.TZ = 'Australia/Sydney';
};
