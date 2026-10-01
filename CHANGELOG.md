# Changelog

All notable changes to Kirby Media Hub are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [1.5.0] — 2026-10-01

### Added

- **Replace file** — a "Replace file" button in the Media Hub detail panel and in the picker's "Attachment details" pane. The new upload keeps the file's UUID, metadata and folder, so every page using the file shows the new version right away, like Kirby's own Replace. Available in Free and Pro; respects Kirby's `replace` permission (also for editors via the picker).
  - Replacing a `.webp` with a JPG/PNG converts the upload to WebP, so the filename stays the same. With Pro optimization enabled, JPG/PNG replacements are converted to WebP in general.
  - Other extension changes (e.g. PNG onto JPG) rename the file to the new extension; references still resolve via UUID.
  - The replacement must be the same kind of file (an image stays an image).

### Changed

- `MediaOptimizer::encodeWebp()` is now a reusable public helper (upload optimization unchanged).
- Upload errors that Kirby reports as `status: error` are now shown instead of being treated as success.

---

## [1.4.0] — 2026-10-01

### Added

- **Upload / drag-and-drop in the picker** — editors can drop files onto the `mediahubpicker` modal or use the new **Upload** button instead of detouring through the Media Hub area. Files land in the folder selected in the sidebar (or the Media Hub root), are preselected, and their details pane opens so alt text can be added right away. Uploads go through Kirby's files API, so role permissions, the upload whitelist and WebP optimization apply as usual. Files that don't match the field's `accept` type are skipped; single-file fields take only the first dropped file.

### Changed

- The main view's upload code and the picker share one upload helper.
- `GET media-hub/picker` now also returns the root slug (`root`).

### Fixed

- **Kirby menu sidebar on the Media Hub views** — the Media Hub and License views are now wrapped in Kirby's `k-panel-inside`, so the Panel menu (Site, Users, System, …) stays visible for navigation, and Kirby's notification toasts appear. On small screens Kirby's ☰ button opens the menu.
- Vue template error in the folder breadcrumb (`key` on a `<template>`).

---

## [1.3.0] — 2026-09-26

### Upgrade notes

- **Upload whitelist:** uploads to the Media Hub are now limited to common image, document, video, audio, archive and design formats (see README → Supported File Types). If your editors upload other types, set `kirbycode.media-hub.accept` in `config.php`.
- **Area access is enforced in the API:** roles with `access: media-hub: false` can no longer use the Media Hub API (the picker field keeps working). Roles without that setting are unaffected.
- **Stray `name.jpg.txt` files** created by earlier versions are removed automatically when their folder is deleted.

### Added

- **Picker opens as a modal** — the `mediahubpicker` field now opens the Media Hub in a WordPress-style overlay with folders/tags sidebar, larger grid, and an "Attachment details" pane. Uses a native `<dialog>` so it stacks correctly above Kirby block and structure drawers.
- **Edit metadata without leaving the page** — clicking a file in the picker shows its details; title, alt text, description, copyright, photographer, AI flag and tags can be edited and saved in place.
- **✎ Edit on selected files** — each file already selected in the field has an edit button that opens its details directly. Metadata edits do not mark the page as changed (the field only stores `file://` UUIDs).
- **Missing alt text badge** — images without alt text show an "ALT" badge in the picker grid and in the field.

### Changed

- **Metadata editing permission** — `PATCH media-hub/files/…/update` now allows any user whose Kirby role may update files (previously admins only). The update runs as the current user so Kirby's own file rules apply. Delete and bulk operations remain admin-only.
- Metadata values are type-checked before saving (strings only; `aigenerated` normalised to a boolean).

### Security

- **Folder creation respects Kirby permissions** — `POST media-hub/folders` was open to every Panel user. It now requires the current user to be allowed to create `media-hub-folder` pages (role permissions + blueprint options).
- **No more permission bypass via the `kirby` user** — folder delete, bulk move, bulk rename, bulk tag and global tag delete now check the real user's Kirby permissions (`delete`, `create`, `changeName`, `update`) per file/folder before running. Files the user may not change are reported in `errors` instead of being modified.
- **Upload hook scoped exactly to the Media Hub** — `file.create:after` used a prefix match, so uploads to pages like `media-hub-docs` were stamped and sent to the optimizer. Now uses an exact boundary check (same fix in the unused-files scan).
- **Media Hub area permission enforced in the API** — a role with `access: media-hub: false` could not open the Media Hub in the Panel, but could still list files, create folders and run other actions through `/api/media-hub/*`. All API routes now require access to the area, except the three the page picker field uses (`GET media-hub/picker`, `GET media-hub/files/…`, `PATCH media-hub/files/…/update`), which stay governed by Kirby's file permissions. New routes are protected by default.
- **Upload whitelist** — the `media-hub-asset` file blueprint now accepts only common image, document, video and audio extensions (scripts and markup such as `.js`/`.xml` are rejected). Override with the `kirbycode.media-hub.accept` option.

### Fixed

- **Folders needed two deletes after JPG/PNG uploads** — the upload hook converted the file to WebP but Kirby still answered the upload with the old `.jpg` object, which wrote an orphaned `name.jpg.txt` next to the `.webp`. Kirby then treated that orphan as the folder's content file, so the first delete only removed the orphan (and the files) and left the folder. The hook now returns the converted file (Kirby uses an after-hook's return value as the upload result), so no orphan is created and the upload response reports the `.webp` name. Folder delete also cleans up orphans left by earlier versions and only reports success once the folder is really gone; the Panel now shows the error if it is not.
- **Uploads failed when the Media Hub was opened on a folder URL** — on `/panel/media-hub/photos` uploads went to `media-hub+photos+photos` (and to the wrong parent after clicking another folder). The upload base is now always the Media Hub root.
- **Selected folder kept in the URL** — clicking a folder updates the address bar, so reloading or sharing the link opens the same folder. Subfolder URLs (`/panel/media-hub/events/2024`) are supported; unknown or invalid paths fall back to the main view.
- **Bulk rename** — rejects a pattern without `{n}` when renaming several files (every file got the same name, all but the first failed).
- **Global tag delete** — one failing file no longer aborts the whole request with a 500; failures are returned in `errors`.
- **Bulk move** — skips files whose name already exists in the target folder instead of failing mid-copy.

---

## [1.2.1] — 2026-09-09

### Fixed

- **Panel System page showed 1.0.0** — plugin version is now passed into `App::plugin()` from `UpdateChecker::CURRENT_VERSION` so Kirby reads the same number as Packagist after deploy

---

## [1.2.0] — 2026-09-09

### Fixed

- **JPG/PNG uploads leaving a duplicate original** — WebP conversion now replaces bytes on the same Kirby file and changes the extension in place (UUID unchanged). The old create-then-delete path could leave both `.jpg`/`.png` and `.webp` when delete failed.
- **Kirby 5 immutable storage blocking conversion** — `file.create:after` now uses the file instance returned from `update()` before running optimization.

---

## [3.0.0] — 2026-06-11

### Added

- **Auto WebP conversion on upload** — JPEG and PNG files are automatically converted to WebP by PHP GD when uploaded to the Media Hub; the original file and its `.txt` sidecar are replaced by a `.webp` file carrying the same UUID, so all `file://` references in content continue to resolve without any manual edits
- **PNG transparency preservation** — alpha channel is retained when converting PNG to WebP
- **In-place WebP compression** — existing WebP files are re-encoded at the configured quality level; the file is only replaced if the result is strictly smaller than the original
- **Re-optimize button** — manually trigger optimization on any image from the file detail panel; shows a success notification with bytes saved, percentage reduction, and whether a format conversion occurred
- **Upload progress indicator** — animated spinner and progress bar displayed in the drop zone while upload and server-side optimization run; label shows "Uploading & optimizing X of Y…"
- **Optimization badges** in the file grid — "→ WebP" badge on JPEG/PNG files (will be converted), "WebP ✓" badge on WebP files (already optimized format)
- **`kirbycode.media-hub.optimization` config key** — control optimization globally (`enabled`), set WebP output quality (`quality.webp`, default 82); all keys are optional with safe defaults
- GD availability check — if the GD extension is not loaded, upload always succeeds and optimization is silently skipped; the Re-optimize button reports "GD not available" instead of failing

### Fixed

- Re-optimize button showed "0 variants registered" and did nothing — root cause was use of Kirby's lazy `$file->thumb()` which does not generate files server-side; replaced with direct PHP GD calls
- JPEG was not converted to WebP on upload — conversion was never implemented in earlier optimization stub; now handled by `convertToWebP()` in `MediaOptimizer`
- `onFileOptimized()` threw `TypeError: Cannot read properties of undefined (reading 'find')` on re-optimize — root cause was reference to `this.localFiles` which does not exist; corrected to `this.files`
- `$panel.api.post()` called without a body argument could silently fail — all optimize POST calls now pass an explicit `{}` body

---

## [2.0.0] — 2026-06-09

### Added

- **2-level subfolder support** — nested folders in the sidebar with inline expand/collapse, breadcrumb navigation, and correct upload URLs for each subfolder level
- **Tags & Keywords** — tag any file with comma-separated keywords; tag cloud in the sidebar with click-to-filter; autocomplete from existing tags when editing metadata
- **Delete tag globally** — hover a tag in the sidebar to reveal a × button; clicking removes that tag from every file in the library (files are untouched)
- **Smart Filtering** — collapsible filter panel with upload date range, uploaded-by user dropdown, and file size range (min/max KB)
- **Bulk Delete** — select multiple files and delete them all in one action
- **Bulk Move** — move a selection of files to any folder while preserving UUIDs and metadata
- **Bulk Rename** — rename multiple files using a pattern with a `{n}` counter (e.g. `event-2024-{n}`)
- **Bulk Tag Assignment** — add, remove, or replace tags on a selection of files at once
- **Duplicate Detection** — scan the entire library for exact duplicates (MD5 hash) and similar-named files (suffix stripping); keep oldest / newest / shortest name per group
- **Improved mediahubpicker** — sidebar layout with scrollable folder tree (subfolders collapsible with ▸/▾) and tag filter; replaces flat tab row that became unwieldy with many folders or tags

### Changed

- Folder count in Dashboard stats now includes all subfolder levels (not just top-level folders)
- Picker API now accepts `tag` parameter for tag-based filtering
- Picker sidebar tag list is built from type-filtered files so only relevant tags appear when `accept` is set on a picker field

### Fixed

- Regex delimiter missing in duplicate-detection base-name extractor caused infinite loop and 500 error on scan
- Tag panel action selector stacked vertically instead of inline — replaced `<select>` with a button group (Add / Remove / Replace all)

---

## [1.0.0] — 2026-06-08

### Added

- Dedicated **Media Hub** area in the Kirby Panel sidebar
- Folder create and delete (subfolders as Kirby child pages)
- Drag-and-drop and button-triggered file upload to any folder
- **Full-text search** across filename, title, alt text, description, copyright, and photographer fields
- File metadata editing: title, alt text, description, copyright, photographer, upload date
- **`mediahubpicker`** custom field — inline expandable picker compatible with Kirby structure fields
- UUID-based file references (`file://uuid`) — same format as Kirby's native `files` field
- Folder filter tabs inside the picker
- **Usage tracking** — lists every page that references a given file
- **Dashboard statistics**: total files, unused files, file-type breakdown, recent uploads, largest files
- Auto-refresh stats after upload or delete
- Professional Panel UI with breadcrumb navigation back to the Kirby dashboard
- Auto-creates `content/media-hub/` on first load (no manual setup required)
- Configurable root slug via `kirbycode.media-hub.root-slug` config option
- Support for extended file types: ai, eps, psd
