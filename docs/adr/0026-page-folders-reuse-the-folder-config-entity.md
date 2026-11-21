# 26. Page folders reuse the folder config entity

Date: 2026-11-20

## Status

Proposed

## Context

The editor lists every page in one flat list, newest first. A site with many
pages has no way to group them, so finding a page means searching for it by
title.

Canvas already has folders. A `folder` config entity groups the items of one
type (components, patterns, code components, or colors) and lists their IDs.
The editor shows those folders in the Library and lets a user create, rename,
delete, and reorder them, and drag items between them. Every one of those item
types is a config entity. A page is a content entity.

Three places could hold "this page is in that folder":

1. **The existing `folder` config entity**, with a new type for pages. The
   HTTP API, the validation, and the editor's folder components are reused. The
   cost is that a config entity then names content.
2. **A field on the page**, pointing at a folder. Canvas keeps a draft of every
   stored page field and writes it back when the page is published. Moving a
   page would become a change to review and publish, and would appear in the
   page's revisions.
3. **A new content entity for folders**, with membership stored beside it.
   Nothing in config would name a page, but folders would need a second API, a
   second set of editor components, and their own access rules.

## Decision

Page folders are `folder` config entities whose type is `canvas_page`.

1. **Items are page UUIDs.** A folder of this type lists the UUIDs of the pages
   in it. The UUID is used, not the numeric ID, because it is the same page
   identifier in every environment.
2. **Folders are flat, and a page is in at most one.** A folder cannot contain
   a folder. The rule that an item belongs to one folder of its type applies to
   pages as it does to components.
3. **A folder only organizes.** It does not change a page's access,
   rendering, or publication. It changes a page's URL only when the folder has
   a URL path (ADR 0027). Folder membership is not part of the page: moving a
   page creates no revision and no undo step.
4. **Items are checked as UUIDs, not for existence.** A folder of a config type
   still requires each item to exist. A folder of pages requires each item to
   be a valid UUID. Config is deployed between environments that do not hold
   the same pages, and a folder that named a missing page would otherwise
   refuse every later change, including a rename.
5. **A folder depends on its pages, and forgets a deleted one.** Each page is a
   content dependency of the folder. Deleting a page removes it from its
   folder.
6. **One permission covers folders of every type.** Creating, renaming,
   deleting, and reordering page folders, and moving a page between them,
   require the permission that already governs folders. Everyone who can use
   the editor sees the folders.
7. **The editor lists folders above the pages that have none.** A folder can be
   collapsed. A page is moved by dragging it onto a folder or by choosing a
   folder from the page's menu. A search shows a flat list of matching pages,
   without folders.

## Consequences

In order of importance, with the following markers:

- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+B` **Editors can group pages.** A site with many pages can be organized
   by section, campaign, or owner, in the same way the Library is.
2. `+T` **One folder concept.** Pages, components, patterns, code components,
   and colors share one entity type, one HTTP API, one set of validation rules,
   and one set of editor components.
3. `-O` **Config names content.** Page folders are created by editors on the
   live site, and are config. A deployment that imports config replaces the
   target site's page folders with the source site's, unless the site excludes
   `canvas.folder.*` entities of this type from deployment. This must be
   documented for site owners.
4. `-T` **A folder can name a page that does not exist.** After config is
   deployed to an environment with different pages, or a page is deleted while
   config is being imported, a folder can list an unknown UUID. The editor
   ignores such an item, and it stays in config until the folder is next
   changed by moving pages.
5. `+TO` **Moving a page is not a content change.** Between folders that have
   no URL path, it needs no review and no publish, and it cannot conflict with
   somebody editing the page.
6. `-B` **Folder management needs an administrative permission.** An editor who
   can create and edit pages, but cannot administer folders, can see page
   folders and cannot change them. A separate permission for page folders can
   be added later without changing the storage.
7. `≃T` **Access is unchanged.** A folder does not restrict who can view or
   edit a page. A module that restricts pages per folder can attach its
   settings to these folders, as modules already do for component folders.
8. `-T` **The folder type property is misnamed.** The stored property that
   holds a folder's type is named for config entity types, and now also holds a
   content entity type. Renaming it would require an update path for every
   existing folder, so the name stays.
