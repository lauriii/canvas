# 27. Page folders can set the URL path of their pages

Date: 2026-11-21

## Status

Proposed

## Context

Pages can be grouped into folders (ADR 0026). A folder there only organizes: it
has no effect on a page's URL. Editors who group pages by section expect the
section to show in the URL, so that the pages in a "Campaigns" folder live under
`/campaigns`. Today they type that prefix into the URL alias of every page, and
nothing keeps it consistent.

A page's URL alias is a value of the page. The editor generates it from the
title until the page is published, or until the user types an alias, and it
changes only as an unpublished change that is reviewed and published.

Other systems solve this in three ways:

1. **The folder owns the first part of the URL.** A folder has a slug, and the
   URL of a page is the folder's slug followed by the page's own slug. Some of
   these systems let one page be the folder's landing page.
2. **A pattern generates the URL.** A pattern per content type builds the
   alias from tokens, and each page records whether its alias is generated or
   typed by hand.
3. **Folders only organize.** The URL is typed per page.

Two more questions follow from the first way:

- **Where the URL is resolved.** The folder's part can be stored in each page's
  alias, or added while a request is routed. Routing, sitemaps, redirects, and
  frontends that render pages outside Drupal all read the stored alias.
- **When a moved page's URL changes.** A URL that changes the moment a page is
  dragged breaks links without a review. A URL that changes when the page is
  published is reviewed like any other change.

## Decision

A page folder can have a URL path. The URL alias of every page in that folder
starts with it.

1. **The path is optional, and a new folder has none.** A folder without a path
   only organizes, as before. Setting a path is a choice made per folder.
2. **The folder owns the first part of the alias, the page owns the rest.** The
   alias of a page in a folder with the path `/campaigns` is `/campaigns`
   followed by the page's own part, for example `/campaigns/summer`. The page's
   own part is still generated from the title until the page is published.
3. **The path can have several segments.** `/products/shoes` is a valid folder
   path. Folders stay flat, and deep URLs do not need nested folders.
4. **The alias is stored, not computed per request.** The folder's path is
   written into the alias of each page. Nothing that reads an alias needs to
   know about folders.
5. **There is no opt-out per page.** A page in a folder with a path cannot be
   published with an alias outside that path. A page that needs a free URL
   belongs in a folder without a path, or in no folder.
6. **A move changes the alias as an unpublished change.** Moving a page into,
   out of, or between folders, and changing a folder's path, rewrite the
   folder's part of the alias of each affected page. The rewritten alias is an
   unpublished change of that page. The live URL changes when the page is
   published. A notification names each published page whose URL will change,
   with its old and its new URL.
7. **A page can be the landing page of its folder.** A page whose alias is
   exactly the folder's path is valid.
8. **A taken alias gets a numeric suffix.** When the rewritten alias is already
   the alias of another page, a number is appended.
9. **Importing config does not rewrite aliases.** A folder path that arrives
   through a config import applies to pages from their next move or edit.
10. **Canvas does not create redirects.** A site that creates a redirect
    whenever an alias changes gets one when the page is published.

Folder membership stays outside the page, as in ADR 0026. Only the alias is a
page change.

## Consequences

In order of importance, with the following markers:

- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+B` **URLs follow the site's structure.** A section's pages share a URL
   path without anybody typing it, and stay consistent when the section is
   renamed.
2. `+BO` **No live URL changes without a review.** A drag in the Pages panel
   cannot break a published link. The change waits with the page's other
   unpublished changes.
3. `-O` **Changing a folder's path touches every page in it.** Each page gets
   an unpublished change, and each must be published. A folder with many pages
   produces many changes to review.
4. `-B` **Membership and URL can disagree for a while.** Between a move and
   the publication of the page, the page is in its new folder and is still
   served at its old URL. Discarding the unpublished change leaves the page in
   the folder with an alias outside the folder's path, and the page cannot be
   published again until its alias is corrected.
5. `-B` **No exceptions inside a folder.** A campaign page that needs a short
   URL cannot stay in a folder whose path is `/campaigns`. A per-page override
   can be added later, and needs a stored value on each page.
6. `+T` **One source of truth for URLs.** Routing, caching, sitemaps, and
   frontends that render pages outside Drupal keep reading the alias.
7. `-T` **Config and content can drift.** A folder path changed by a config
   import, or by anything else that does not go through the editor, leaves
   aliases behind until each page is next moved or edited.
8. `-B` **Old URLs break unless the site creates redirects.** Canvas leaves
   redirects to the site.
9. `≃T` **Translations are not covered.** The folder's path is one value for
   every language. Whether it can be translated is left open.
