/**
 * Nitro's build-time mode flag, replaced statically when the server bundle
 * is built (`nuxt dev` → true, `nuxt build` → false). Declared here so the
 * runtime handlers type-check outside a Nitro build context.
 */
interface ImportMeta {
  readonly dev?: boolean;
}
