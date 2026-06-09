# Changelog

All notable changes to Kirby Media Hub are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
