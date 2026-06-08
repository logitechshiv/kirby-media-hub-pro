# Changelog

All notable changes to Kirby Media Hub are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
