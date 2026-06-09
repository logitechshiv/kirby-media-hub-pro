/**
 * Kirby Media Hub Pro — Panel Vue 3 Components
 * Registered via window.panel.plugin() — no build step required.
 */
window.panel.plugin('kirbycode/media-hub', {

  components: {

    // ── Main view: sidebar + file grid + detail panel ─────────────────────
    'k-media-hub-view': {
      props: {
        folders:       { type: Array,  default: () => [] },
        currentFolder: { type: String, default: null },
        apiUrl:        { type: String, required: true },
        uploadApiBase: { type: String, required: true },
      },

      data() {
        return {
          localFolders:     [...(this.folders || [])],
          activeFolderPath: this.currentFolder || null,
          expandedFolders:  [],
          files:            [],
          loading:          false,
          pagination:       { total: 0, page: 1, limit: 40 },
          searchQuery:      '',
          activeType:       '',
          activeTag:        '',
          availableTags:    [],
          uploaders:        [],
          smartFilter:      { dateFrom: '', dateTo: '', uploadedBy: '', minSize: '', maxSize: '' },
          smartFilterOpen:  false,
          smartFilterTimer: null,
          showSearch:       false,
          showNewFolder:    false,
          newFolderName:    '',
          activeFile:       null,
          searchTimer:      null,
          statsRefreshKey:  0,
          showDuplicates:   false,
          selectedFiles:    [],
          selectionMode:    false,
          bulkAction:       '',
          bulkMove:         { targetFolder: '' },
          bulkRename:       { pattern: 'file-{n}', startAt: 1 },
          bulkTag:          { tagInput: '', action: 'add' },
        };
      },

      created() {
        this.loadFiles();
        this.loadFolders();
        this.loadTags();
        this.loadUploaders();
      },

      computed: {
        panelUrl() {
          const path = window.location.pathname;
          const idx  = path.indexOf('/media-hub');
          return idx > -1 ? path.substring(0, idx) : '/panel';
        },

        currentUploadUrl() {
          if (this.activeFolderPath) {
            // e.g. 'events/2024' → 'pages/media-hub+events+2024/files'
            const encoded = this.activeFolderPath.split('/').join('+');
            return this.uploadApiBase + '+' + encoded + '/files';
          }
          return this.uploadApiBase + '/files';
        },

        allTagNames() {
          return this.availableTags.map(t => t.tag);
        },

        breadcrumbs() {
          if (!this.activeFolderPath) return [];
          const parts = this.activeFolderPath.split('/');
          return parts.map((_, i) => {
            const partPath = parts.slice(0, i + 1).join('/');
            let title = parts[i];
            if (i === 0) {
              const f = this.localFolders.find(f => f.path === parts[0]);
              if (f) title = f.title;
            } else {
              const parent = this.localFolders.find(f => f.path === parts[0]);
              if (parent && parent.children) {
                const sub = parent.children.find(c => c.path === partPath);
                if (sub) title = sub.title;
              }
            }
            return { path: partPath, title };
          });
        },

        parentForNewFolder() {
          if (this.activeFolderPath && !this.activeFolderPath.includes('/')) {
            const f = this.localFolders.find(f => f.path === this.activeFolderPath);
            return f ? f.title : this.activeFolderPath;
          }
          return '';
        },

        activeSmartFilterCount() {
          return Object.values(this.smartFilter).filter(v => v !== '' && v !== 0).length;
        },

        isAllSelected() {
          return this.files.length > 0 && this.selectedFiles.length === this.files.length;
        },

        allFolderPaths() {
          const paths = [{ path: '', label: 'Root (Media Hub)' }];
          for (const f of this.localFolders) {
            paths.push({ path: f.path, label: f.title });
            for (const c of (f.children || [])) {
              paths.push({ path: c.path, label: '   ' + f.title + ' / ' + c.title });
            }
          }
          return paths;
        },
      },

      methods: {
        async loadFiles(page = 1) {
          this.loading = true;
          try {
            const params = new URLSearchParams({ page });
            if (this.activeFolderPath)             params.set('folder',     this.activeFolderPath);
            if (this.searchQuery)                  params.set('q',          this.searchQuery);
            if (this.activeType)                   params.set('type',       this.activeType);
            if (this.activeTag)                    params.set('tag',        this.activeTag);
            if (this.smartFilter.dateFrom)         params.set('dateFrom',   this.smartFilter.dateFrom);
            if (this.smartFilter.dateTo)           params.set('dateTo',     this.smartFilter.dateTo);
            if (this.smartFilter.uploadedBy)       params.set('uploadedBy', this.smartFilter.uploadedBy);
            if (this.smartFilter.minSize)          params.set('minSize',    this.smartFilter.minSize);
            if (this.smartFilter.maxSize)          params.set('maxSize',    this.smartFilter.maxSize);

            const res        = await this.$panel.api.get('media-hub/files?' + params.toString());
            this.files       = res.data       || [];
            this.pagination  = res.pagination || { total: 0, page: 1, limit: 40 };
          } catch (e) {
            this.$panel.notification.error('Could not load files: ' + (e.message || e));
          } finally {
            this.loading = false;
          }
        },

        selectFolder(path) {
          this.activeFolderPath = path;
          this.activeFile       = null;
          this.pagination.page  = 1;
          if (path && path.includes('/')) {
            const parent = path.split('/')[0];
            if (!this.expandedFolders.includes(parent)) {
              this.expandedFolders.push(parent);
            }
          }
          this.loadFiles();
        },

        toggleExpand(path) {
          const i = this.expandedFolders.indexOf(path);
          if (i > -1) this.expandedFolders.splice(i, 1);
          else this.expandedFolders.push(path);
        },

        toggleSearch() {
          this.showSearch = !this.showSearch;
          if (!this.showSearch) {
            this.searchQuery = '';
            this.loadFiles();
          }
        },

        onSearchInput() {
          clearTimeout(this.searchTimer);
          this.searchTimer = setTimeout(() => this.loadFiles(), 400);
        },

        setType(type) {
          this.activeType = type;
          this.loadFiles();
        },

        setTag(tag) {
          this.activeTag = (this.activeTag === tag) ? '' : tag;
          this.pagination.page = 1;
          this.loadFiles();
        },

        async loadTags() {
          try {
            const res = await this.$panel.api.get('media-hub/tags');
            this.availableTags = res.data || [];
          } catch (e) {
            // non-critical
          }
        },

        async deleteTag(tag) {
          if (!confirm(`Remove tag "${tag}" from all files? Files will not be deleted.`)) return;
          try {
            await this.$panel.api.delete('media-hub/tags/' + encodeURIComponent(tag));
            if (this.activeTag === tag) {
              this.activeTag = '';
              this.loadFiles();
            }
            await this.loadTags();
            this.$panel.notification.success(`Tag "${tag}" removed from all files.`);
          } catch (e) {
            this.$panel.notification.error('Could not delete tag.');
          }
        },

        async loadUploaders() {
          try {
            const res = await this.$panel.api.get('media-hub/uploaders');
            this.uploaders = res.data || [];
          } catch (e) {
            // non-critical
          }
        },

        openDatePicker(event) {
          const wrap  = event.currentTarget.closest('.k-media-hub-date-wrap');
          const input = wrap && wrap.querySelector('input[type="date"]');
          if (!input) return;
          try {
            if (typeof input.showPicker === 'function') {
              input.showPicker();
            } else {
              input.focus();
              input.click();
            }
          } catch (e) {
            input.focus();
          }
        },

        onSmartFilterChange() {
          clearTimeout(this.smartFilterTimer);
          this.smartFilterTimer = setTimeout(() => this.loadFiles(), 500);
        },

        onSmartFilterSelect() {
          this.loadFiles();
        },

        clearSmartFilter() {
          this.smartFilter = { dateFrom: '', dateTo: '', uploadedBy: '', minSize: '', maxSize: '' };
          this.loadFiles();
        },

        onPaginate(page) {
          this.loadFiles(page);
        },

        async createFolder() {
          const title      = this.newFolderName.trim();
          if (!title) return;
          const parentPath = (this.activeFolderPath && !this.activeFolderPath.includes('/'))
            ? this.activeFolderPath
            : '';
          try {
            const res = await this.$panel.api.post('media-hub/folders', { title, parent: parentPath });
            if (res.status === 'ok') {
              await this.loadFolders();
              if (parentPath && !this.expandedFolders.includes(parentPath)) {
                this.expandedFolders.push(parentPath);
              }
              this.statsRefreshKey++;
              this.$panel.notification.success('Folder created');
            } else {
              this.$panel.notification.error(res.message || 'Could not create folder');
            }
          } catch (e) {
            this.$panel.notification.error('Could not create folder: ' + (e.message || e));
          }
          this.newFolderName = '';
          this.showNewFolder = false;
        },

        async deleteFolder(folder) {
          if (!confirm('Delete folder "' + folder.title + '" and all its files?')) return;
          const encodedPath = encodeURIComponent(folder.path).replace(/%2F/g, '+');
          try {
            await this.$panel.api.delete('media-hub/folders/' + encodedPath);
            await this.loadFolders();
            this.statsRefreshKey++;
            if (this.activeFolderPath === folder.path ||
                (this.activeFolderPath && this.activeFolderPath.startsWith(folder.path + '/'))) {
              this.activeFolderPath = null;
              this.loadFiles();
            }
            this.$panel.notification.success('Folder deleted');
          } catch (e) {
            this.$panel.notification.error('Could not delete folder: ' + (e.message || e));
          }
        },

        triggerUpload() {
          this.$refs.fileInput.click();
        },

        async handleFileInput(event) {
          const files = event.target.files;
          if (files && files.length) {
            await this.uploadFiles(files);
            event.target.value = '';
          }
        },

        async onDrop(event) {
          event.preventDefault();
          const files = event.dataTransfer.files;
          if (files && files.length) {
            await this.uploadFiles(files);
          }
          this.$refs.dropzone.classList.remove('is-over');
        },

        onDragOver(event) {
          event.preventDefault();
          this.$refs.dropzone.classList.add('is-over');
        },

        onDragLeave() {
          this.$refs.dropzone.classList.remove('is-over');
        },

        async uploadFiles(fileList) {
          // Derive the Kirby API base URL from the apiUrl prop.
          // apiUrl = 'http://host/api/media-hub' → base = 'http://host/api/'
          const apiBase = this.apiUrl.replace(/\/media-hub\/?$/, '/');
          // CSRF token: try Kirby 5 Panel store locations
          const csrf = this.$panel?.system?.csrf
                    || window.panel?.system?.csrf
                    || '';

          let uploaded = 0;
          for (const file of fileList) {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('filename', file.name);
            fd.append('template', 'media-hub-asset');
            try {
              // Use fetch() directly — $panel.api.post() may JSON-encode the body
              // which would corrupt the multipart upload. fetch() + FormData keeps
              // the browser-set Content-Type boundary intact.
              const uploadUrl = apiBase + this.currentUploadUrl;
              const headers   = {};
              if (csrf) headers['X-CSRF'] = csrf;

              const res = await fetch(uploadUrl, {
                method: 'POST',
                body: fd,
                credentials: 'include',
                headers,
              });

              if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                throw new Error(err.message || res.statusText || 'Upload error');
              }
              uploaded++;
            } catch (e) {
              this.$panel.notification.error('Upload failed: ' + file.name + (e.message ? ' — ' + e.message : ''));
            }
          }
          if (uploaded > 0) {
            this.$panel.notification.success(uploaded + ' file' + (uploaded > 1 ? 's' : '') + ' uploaded');
            this.loadFiles();
            this.loadFolders();
            this.loadTags();
            this.loadUploaders();
            this.statsRefreshKey++;
          }
        },

        toggleSelectionMode() {
          this.selectionMode = !this.selectionMode;
          if (!this.selectionMode) {
            this.selectedFiles = [];
            this.bulkAction    = '';
          }
        },

        toggleSelect(file) {
          const idx = this.selectedFiles.indexOf(file.id);
          if (idx > -1) this.selectedFiles.splice(idx, 1);
          else this.selectedFiles.push(file.id);
        },

        isFileSelected(file) {
          return this.selectedFiles.includes(file.id);
        },

        selectAll() {
          this.selectedFiles = this.files.map(f => f.id);
        },

        deselectAll() {
          this.selectedFiles = [];
          this.bulkAction    = '';
        },

        setBulkAction(action) {
          this.bulkAction = (this.bulkAction === action) ? '' : action;
        },

        async doBulkDelete() {
          if (!this.selectedFiles.length) return;
          if (!confirm('Delete ' + this.selectedFiles.length + ' file(s)? This cannot be undone.')) return;
          const ids = this.selectedFiles.map(id => encodeURIComponent(id).replace(/%2F/g, '+'));
          try {
            const res = await this.$panel.api.post('media-hub/bulk/delete', { ids });
            this.$panel.notification.success(res.deleted + ' file(s) deleted');
            if (res.errors && res.errors.length) this.$panel.notification.error(res.errors[0]);
            this.selectedFiles = []; this.bulkAction = '';
            this.loadFiles(); this.loadFolders(); this.loadTags(); this.loadUploaders();
            this.statsRefreshKey++;
          } catch (e) {
            this.$panel.notification.error('Bulk delete failed: ' + (e.message || e));
          }
        },

        async doBulkMove() {
          if (!this.selectedFiles.length) return;
          const ids = this.selectedFiles.map(id => encodeURIComponent(id).replace(/%2F/g, '+'));
          try {
            const res = await this.$panel.api.post('media-hub/bulk/move', {
              ids,
              targetFolder: this.bulkMove.targetFolder,
            });
            this.$panel.notification.success(res.moved + ' file(s) moved');
            if (res.errors && res.errors.length) this.$panel.notification.error(res.errors[0]);
            this.selectedFiles = []; this.bulkAction = '';
            this.loadFiles(); this.loadFolders(); this.statsRefreshKey++;
          } catch (e) {
            this.$panel.notification.error('Bulk move failed: ' + (e.message || e));
          }
        },

        async doBulkRename() {
          if (!this.selectedFiles.length || !this.bulkRename.pattern.trim()) return;
          const ids = this.selectedFiles.map(id => encodeURIComponent(id).replace(/%2F/g, '+'));
          try {
            const res = await this.$panel.api.post('media-hub/bulk/rename', {
              ids,
              pattern: this.bulkRename.pattern.trim(),
              startAt: this.bulkRename.startAt || 1,
            });
            this.$panel.notification.success(res.renamed + ' file(s) renamed');
            if (res.errors && res.errors.length) this.$panel.notification.error(res.errors[0]);
            this.selectedFiles = []; this.bulkAction = '';
            this.loadFiles();
          } catch (e) {
            this.$panel.notification.error('Bulk rename failed: ' + (e.message || e));
          }
        },

        async doBulkTag() {
          if (!this.selectedFiles.length || !this.bulkTag.tagInput.trim()) return;
          const tags = this.bulkTag.tagInput.split(',').map(t => t.trim()).filter(Boolean);
          if (!tags.length) { this.$panel.notification.error('Enter at least one tag'); return; }
          const ids = this.selectedFiles.map(id => encodeURIComponent(id).replace(/%2F/g, '+'));
          try {
            const res = await this.$panel.api.post('media-hub/bulk/tag', {
              ids,
              tags,
              action: this.bulkTag.action,
            });
            this.$panel.notification.success(res.updated + ' file(s) updated');
            if (res.errors && res.errors.length) this.$panel.notification.error(res.errors[0]);
            this.selectedFiles = []; this.bulkAction = '';
            this.loadFiles(); this.loadTags();
          } catch (e) {
            this.$panel.notification.error('Bulk tag failed: ' + (e.message || e));
          }
        },

        openFile(file) {
          if (this.selectionMode) { this.toggleSelect(file); return; }
          this.activeFile = file;
        },

        closeDetail() {
          this.activeFile = null;
        },

        onFileUpdated(updated) {
          const idx = this.files.findIndex(f => f.id === updated.id);
          if (idx > -1) this.files.splice(idx, 1, updated);
          this.activeFile = updated;
          this.loadTags();
        },

        async quickDeleteFile(file) {
          if (!confirm('Delete "' + file.filename + '"? This cannot be undone.')) return;
          const encodedId = encodeURIComponent(file.id).replace(/%2F/g, '+');
          try {
            await this.$panel.api.delete('media-hub/files/' + encodedId + '/delete');
            this.onFileDeleted(file.id);
            this.$panel.notification.success('File deleted');
          } catch (e) {
            this.$panel.notification.error('Delete failed: ' + (e.message || e));
          }
        },

        async loadFolders() {
          try {
            const res = await this.$panel.api.get('media-hub/folders');
            this.localFolders = res.data || [];
          } catch (e) {
            // non-critical
          }
        },

        onFileDeleted(fileId) {
          this.files      = this.files.filter(f => f.id !== fileId);
          this.activeFile = null;
          this.statsRefreshKey++;
          this.loadFolders();
          this.loadTags();
          this.loadUploaders();
        },
      },

      template: `
        <div class="k-media-hub-view" :class="{ 'has-detail': !!activeFile }">

          <!-- Top navigation bar -->
          <div class="k-media-hub-topbar">
            <div class="k-media-hub-breadcrumb">
              <a :href="panelUrl" title="Back to Panel">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Panel
              </a>
              <span class="k-media-hub-breadcrumb-sep">/</span>
              <span class="k-media-hub-breadcrumb-current k-media-hub-breadcrumb-link" @click="selectFolder(null)">Media Hub</span>
              <template v-if="activeFolderPath">
                <template v-for="crumb in breadcrumbs" :key="crumb.path">
                  <span class="k-media-hub-breadcrumb-sep">/</span>
                  <span class="k-media-hub-breadcrumb-current k-media-hub-breadcrumb-link" @click="selectFolder(crumb.path)">{{ crumb.title }}</span>
                </template>
              </template>
            </div>
            <div class="k-media-hub-topbar-actions">
              <button class="k-media-hub-btn" @click="toggleSearch" title="Search files">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Search
              </button>
              <button class="k-media-hub-btn" @click="showNewFolder = !showNewFolder">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                New Folder
              </button>
              <button
                :class="['k-media-hub-btn', { 'k-media-hub-btn--active': showDuplicates }]"
                @click="showDuplicates = !showDuplicates; if(showDuplicates){ selectionMode=false; selectedFiles=[]; }"
                title="Find duplicate files"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Duplicates
              </button>
              <button
                :class="['k-media-hub-btn', { 'k-media-hub-btn--active': selectionMode }]"
                @click="toggleSelectionMode"
                title="Toggle selection mode"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="5" height="5" rx="1"/><polyline points="9 5 11 7 15 3"/></svg>
                Select
              </button>
              <button class="k-media-hub-btn k-media-hub-btn--primary" @click="triggerUpload">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                Upload
              </button>
              <input ref="fileInput" type="file" multiple style="display:none" @change="handleFileInput" />
            </div>
          </div>

          <!-- Stats bar -->
          <k-media-hub-stats :key="statsRefreshKey" :api-url="apiUrl" />

          <!-- New folder form -->
          <div v-if="showNewFolder" class="k-media-hub-new-folder">
            <small v-if="parentForNewFolder" class="k-media-hub-new-folder-hint">Subfolder in: {{ parentForNewFolder }}</small>
            <input
              v-model="newFolderName"
              type="text"
              placeholder="Folder name…"
              class="k-media-hub-input"
              @keyup.enter="createFolder"
              @keyup.escape="showNewFolder = false"
              autofocus
            />
            <button class="k-media-hub-btn k-media-hub-btn--primary" @click="createFolder">Create</button>
            <button class="k-media-hub-btn" @click="showNewFolder = false">Cancel</button>
          </div>

          <div class="k-media-hub-layout">

            <!-- Sidebar -->
            <nav class="k-media-hub-sidebar">
              <div class="k-media-hub-sidebar-label">Folders</div>
              <ul class="k-media-hub-folder-list">
                <li
                  :class="{ active: !activeFolderPath }"
                  @click="selectFolder(null)"
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
                  All Files
                </li>
                <li
                  v-for="folder in localFolders"
                  :key="folder.path || folder.id"
                  class="k-media-hub-folder-entry"
                >
                  <div
                    :class="['k-media-hub-folder-row', { active: activeFolderPath === folder.path }]"
                    @click="selectFolder(folder.path)"
                  >
                    <button
                      v-if="folder.children && folder.children.length"
                      type="button"
                      class="k-media-hub-folder-expand"
                      @click.stop="toggleExpand(folder.path)"
                      :title="expandedFolders.includes(folder.path) ? 'Collapse' : 'Expand'"
                    >
                      <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                           :style="{ transform: expandedFolders.includes(folder.path) ? 'rotate(90deg)' : 'none', transition: 'transform 0.15s' }">
                        <polyline points="9 18 15 12 9 6"/>
                      </svg>
                    </button>
                    <span v-else class="k-media-hub-folder-expand-space"></span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    <span class="k-media-hub-folder-name">{{ folder.title }}</span>
                    <span class="k-media-hub-folder-count">{{ folder.fileCount }}</span>
                    <button
                      class="k-media-hub-folder-delete"
                      @click.stop="deleteFolder(folder)"
                      title="Delete folder"
                    >×</button>
                  </div>
                  <ul
                    v-if="folder.children && folder.children.length && expandedFolders.includes(folder.path)"
                    class="k-media-hub-subfolder-list"
                  >
                    <li
                      v-for="sub in folder.children"
                      :key="sub.path"
                      :class="['k-media-hub-subfolder-item', { active: activeFolderPath === sub.path }]"
                      @click="selectFolder(sub.path)"
                    >
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                      <span class="k-media-hub-folder-name">{{ sub.title }}</span>
                      <span class="k-media-hub-folder-count">{{ sub.fileCount }}</span>
                      <button
                        class="k-media-hub-folder-delete"
                        @click.stop="deleteFolder(sub)"
                        title="Delete folder"
                      >×</button>
                    </li>
                  </ul>
                </li>
              </ul>

              <!-- Tags filter -->
              <div v-if="availableTags.length" class="k-media-hub-sidebar-section">
                <div class="k-media-hub-sidebar-label">Tags</div>
                <div class="k-media-hub-tag-list">
                  <div
                    v-for="t in availableTags"
                    :key="t.tag"
                    :class="['k-media-hub-tag-btn', { active: activeTag === t.tag }]"
                    @click="setTag(t.tag)"
                  >
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                    <span class="k-media-hub-folder-name">{{ t.tag }}</span>
                    <span class="k-media-hub-tag-count">{{ t.count }}</span>
                    <button
                      type="button"
                      class="k-media-hub-tag-delete"
                      title="Delete tag"
                      @click.stop="deleteTag(t.tag)"
                    >×</button>
                  </div>
                </div>
              </div>

              <!-- Smart Filter -->
              <div class="k-media-hub-sidebar-section">
                <div class="k-media-hub-sf-header" @click="smartFilterOpen = !smartFilterOpen">
                  <span class="k-media-hub-sidebar-label k-media-hub-sf-label">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    Smart Filter
                    <span v-if="activeSmartFilterCount" class="k-media-hub-sf-badge">{{ activeSmartFilterCount }}</span>
                  </span>
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" :style="{ transform: smartFilterOpen ? 'rotate(180deg)' : 'none', transition: 'transform 0.15s' }"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <div v-if="smartFilterOpen" class="k-media-hub-sf-body">

                  <!-- Upload date range -->
                  <div class="k-media-hub-sf-group">
                    <label class="k-media-hub-sf-label-small">Date From</label>
                    <div class="k-media-hub-date-wrap">
                      <input type="date" v-model="smartFilter.dateFrom" class="k-media-hub-input k-media-hub-sf-input k-media-hub-date-input" @change="onSmartFilterSelect" />
                      <button type="button" class="k-media-hub-date-btn" @click="openDatePicker($event)" title="Pick date">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                      </button>
                    </div>
                  </div>
                  <div class="k-media-hub-sf-group">
                    <label class="k-media-hub-sf-label-small">Date To</label>
                    <div class="k-media-hub-date-wrap">
                      <input type="date" v-model="smartFilter.dateTo" class="k-media-hub-input k-media-hub-sf-input k-media-hub-date-input" @change="onSmartFilterSelect" />
                      <button type="button" class="k-media-hub-date-btn" @click="openDatePicker($event)" title="Pick date">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                      </button>
                    </div>
                  </div>

                  <!-- Uploaded by (only shown if more than one uploader) -->
                  <div v-if="uploaders.length > 0" class="k-media-hub-sf-group">
                    <label class="k-media-hub-sf-label-small">Uploaded By</label>
                    <select v-model="smartFilter.uploadedBy" class="k-media-hub-input k-media-hub-sf-input" @change="onSmartFilterSelect">
                      <option value="">All users</option>
                      <option v-for="u in uploaders" :key="u" :value="u">{{ u }}</option>
                    </select>
                  </div>

                  <!-- File size range in KB -->
                  <div class="k-media-hub-sf-group">
                    <label class="k-media-hub-sf-label-small">Size (KB)</label>
                    <div class="k-media-hub-sf-range">
                      <input type="number" v-model="smartFilter.minSize" min="0" class="k-media-hub-input k-media-hub-sf-input" placeholder="Min" @input="onSmartFilterChange" />
                      <span class="k-media-hub-sf-dash">–</span>
                      <input type="number" v-model="smartFilter.maxSize" min="0" class="k-media-hub-input k-media-hub-sf-input" placeholder="Max" @input="onSmartFilterChange" />
                    </div>
                  </div>

                  <button v-if="activeSmartFilterCount" type="button" class="k-media-hub-btn k-media-hub-sf-clear" @click="clearSmartFilter">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Clear {{ activeSmartFilterCount }} filter{{ activeSmartFilterCount > 1 ? 's' : '' }}
                  </button>
                </div>
              </div>
            </nav>

            <!-- Main content -->
            <div class="k-media-hub-content">

              <!-- Duplicates view (replaces grid when active) -->
              <k-media-hub-duplicates
                v-if="showDuplicates"
                :api-url="apiUrl"
                @changed="loadFiles(); loadFolders(); loadTags(); statsRefreshKey++"
              />

              <template v-if="!showDuplicates">

              <!-- Search + type filter -->
              <div v-if="showSearch" class="k-media-hub-filters">
                <input
                  v-model="searchQuery"
                  type="text"
                  placeholder="Search by filename, title, alt text…"
                  class="k-media-hub-input k-media-hub-search-input"
                  @input="onSearchInput"
                />
                <k-media-hub-type-filter :active="activeType" @change="setType" />
              </div>
              <div v-else class="k-media-hub-type-bar">
                <k-media-hub-type-filter :active="activeType" @change="setType" />
              </div>

              <!-- Active tag indicator -->
              <div v-if="activeTag" class="k-media-hub-active-tag-bar">
                <span class="k-media-hub-active-tag">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                  {{ activeTag }}
                  <button type="button" class="k-media-hub-tag-clear" @click="setTag(activeTag)" title="Clear tag filter">×</button>
                </span>
              </div>

              <!-- Bulk selection toolbar -->
              <div v-if="selectionMode" class="k-media-hub-bulk-bar">
                <div class="k-media-hub-bulk-info">
                  <label class="k-media-hub-bulk-check-all" title="Select all">
                    <input type="checkbox" :checked="isAllSelected" @change="isAllSelected ? deselectAll() : selectAll()" />
                  </label>
                  <span class="k-media-hub-bulk-count">
                    {{ selectedFiles.length ? selectedFiles.length + ' selected' : 'None selected' }}
                  </span>
                  <button v-if="selectedFiles.length" type="button" class="k-media-hub-bulk-clear" @click="deselectAll">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </div>
                <div v-if="selectedFiles.length" class="k-media-hub-bulk-actions">
                  <button type="button" class="k-media-hub-btn k-media-hub-btn--danger-outline" @click="doBulkDelete">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    Delete
                  </button>
                  <button type="button" :class="['k-media-hub-btn', { 'k-media-hub-btn--active': bulkAction === 'move' }]" @click="setBulkAction('move')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                    Move
                  </button>
                  <button type="button" :class="['k-media-hub-btn', { 'k-media-hub-btn--active': bulkAction === 'rename' }]" @click="setBulkAction('rename')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Rename
                  </button>
                  <button type="button" :class="['k-media-hub-btn', { 'k-media-hub-btn--active': bulkAction === 'tag' }]" @click="setBulkAction('tag')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                    Tag
                  </button>
                </div>
              </div>

              <!-- Bulk action panels -->
              <div v-if="selectionMode && selectedFiles.length && bulkAction" class="k-media-hub-bulk-panel">

                <!-- Move panel -->
                <div v-if="bulkAction === 'move'" class="k-media-hub-bulk-form">
                  <label class="k-media-hub-bulk-form-label">Move {{ selectedFiles.length }} file(s) to:</label>
                  <select v-model="bulkMove.targetFolder" class="k-media-hub-input k-media-hub-bulk-select">
                    <option v-for="f in allFolderPaths" :key="f.path" :value="f.path">{{ f.label }}</option>
                  </select>
                  <button type="button" class="k-media-hub-btn k-media-hub-btn--primary" @click="doBulkMove">Move</button>
                  <button type="button" class="k-media-hub-btn" @click="bulkAction = ''">Cancel</button>
                </div>

                <!-- Rename panel -->
                <div v-if="bulkAction === 'rename'" class="k-media-hub-bulk-form">
                  <label class="k-media-hub-bulk-form-label">Rename {{ selectedFiles.length }} file(s) with pattern:</label>
                  <div class="k-media-hub-bulk-rename-row">
                    <input
                      v-model="bulkRename.pattern"
                      type="text"
                      class="k-media-hub-input"
                      placeholder="photo-{n}"
                      style="flex:1"
                    />
                    <span class="k-media-hub-bulk-rename-sep">starting at</span>
                    <input
                      v-model.number="bulkRename.startAt"
                      type="number"
                      min="1"
                      class="k-media-hub-input"
                      style="width:64px"
                    />
                  </div>
                  <small class="k-media-hub-bulk-hint">Use <code>{n}</code> for the counter. Extension is kept automatically.</small>
                  <div class="k-media-hub-bulk-form-actions">
                    <button type="button" class="k-media-hub-btn k-media-hub-btn--primary" @click="doBulkRename">Rename</button>
                    <button type="button" class="k-media-hub-btn" @click="bulkAction = ''">Cancel</button>
                  </div>
                </div>

                <!-- Tag panel -->
                <div v-if="bulkAction === 'tag'" class="k-media-hub-bulk-form">
                  <label class="k-media-hub-bulk-form-label">Tag {{ selectedFiles.length }} file(s):</label>
                  <div class="k-media-hub-type-filter k-media-hub-bulk-tag-btns">
                    <button type="button" :class="['k-media-hub-type-btn', { active: bulkTag.action === 'add' }]"    @click="bulkTag.action = 'add'">Add tags</button>
                    <button type="button" :class="['k-media-hub-type-btn', { active: bulkTag.action === 'remove' }]" @click="bulkTag.action = 'remove'">Remove tags</button>
                    <button type="button" :class="['k-media-hub-type-btn', { active: bulkTag.action === 'set' }]"    @click="bulkTag.action = 'set'">Replace all</button>
                  </div>
                  <input
                    v-model="bulkTag.tagInput"
                    type="text"
                    class="k-media-hub-input"
                    placeholder="tag1, tag2, tag3  (comma separated)"
                    list="k-media-hub-bulk-tag-suggestions"
                  />
                  <datalist id="k-media-hub-bulk-tag-suggestions">
                    <option v-for="t in allTagNames" :key="t" :value="t" />
                  </datalist>
                  <div class="k-media-hub-bulk-form-actions">
                    <button type="button" class="k-media-hub-btn k-media-hub-btn--primary" @click="doBulkTag">Apply</button>
                    <button type="button" class="k-media-hub-btn" @click="bulkAction = ''">Cancel</button>
                  </div>
                </div>

              </div>

              <!-- Drop zone -->
              <div
                ref="dropzone"
                class="k-media-hub-dropzone"
                @drop="onDrop"
                @dragover="onDragOver"
                @dragleave="onDragLeave"
                @click="triggerUpload"
              >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                Drop files here or click to upload
              </div>

              <!-- Loading -->
              <div v-if="loading" class="k-media-hub-loading">
                <div class="k-media-hub-spinner"></div>
              </div>

              <!-- File grid -->
              <div v-else-if="files.length" class="k-media-hub-grid">
                <k-media-hub-file-card
                  v-for="file in files"
                  :key="file.id"
                  :file="file"
                  :selection-mode="selectionMode"
                  :selected="isFileSelected(file)"
                  @click="openFile(file)"
                  @select="toggleSelect(file)"
                  @delete="quickDeleteFile(file)"
                />
              </div>

              <!-- Empty state -->
              <div v-else class="k-media-hub-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <p>No files here yet. Upload something!</p>
              </div>

              <!-- Pagination -->
              <div v-if="pagination.total > pagination.limit" class="k-media-hub-pagination">
                <button
                  class="k-media-hub-btn"
                  :disabled="pagination.page <= 1"
                  @click="onPaginate(pagination.page - 1)"
                >← Prev</button>
                <span>Page {{ pagination.page }} of {{ Math.ceil(pagination.total / pagination.limit) }}</span>
                <button
                  class="k-media-hub-btn"
                  :disabled="pagination.page >= Math.ceil(pagination.total / pagination.limit)"
                  @click="onPaginate(pagination.page + 1)"
                >Next →</button>
              </div>

              </template>

            </div>
          </div>

          <!-- File detail side panel -->
          <k-media-hub-file-detail
            v-if="activeFile"
            :file="activeFile"
            :api-url="apiUrl"
            :all-tags="allTagNames"
            @close="closeDetail"
            @updated="onFileUpdated"
            @deleted="onFileDeleted"
          />

        </div>
      `,
    },

    // ── Stats bar ──────────────────────────────────────────────────────────
    'k-media-hub-stats': {
      props: {
        apiUrl: { type: String, required: true },
      },
      data() {
        return { stats: null, loading: false };
      },
      async created() {
        this.loading = true;
        try {
          this.stats = await this.$panel.api.get('media-hub/stats');
        } catch (e) {
          // Non-critical; silently ignore
        } finally {
          this.loading = false;
        }
      },
      template: `
        <div class="k-media-hub-stats" v-if="stats">
          <div class="k-media-hub-stat">
            <span class="k-media-hub-stat-value">{{ stats.total }}</span>
            <span class="k-media-hub-stat-label">Total Files</span>
          </div>
          <div class="k-media-hub-stat">
            <span class="k-media-hub-stat-value">{{ stats.unused }}</span>
            <span class="k-media-hub-stat-label">Unused</span>
          </div>
          <div class="k-media-hub-stat">
            <span class="k-media-hub-stat-value">{{ stats.folders }}</span>
            <span class="k-media-hub-stat-label">Folders</span>
          </div>
          <div class="k-media-hub-stat" v-for="(count, type) in stats.byType" :key="type">
            <span class="k-media-hub-stat-value">{{ count }}</span>
            <span class="k-media-hub-stat-label">{{ type.charAt(0).toUpperCase() + type.slice(1) + 's' }}</span>
          </div>
        </div>
      `,
    },

    // ── File card in the grid ──────────────────────────────────────────────
    'k-media-hub-file-card': {
      props: {
        file:          { type: Object,  required: true },
        selectionMode: { type: Boolean, default: false },
        selected:      { type: Boolean, default: false },
      },
      emits: ['click', 'select', 'delete'],
      methods: {
        iconFor(type) {
          const m = {
            image: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>`,
            document: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>`,
            video: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>`,
            audio: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>`,
          };
          return m[type] || m.document;
        },
      },
      template: `
        <div
          :class="['k-media-hub-file-card', { 'is-selected': selected, 'is-selection-mode': selectionMode }]"
          @click="selectionMode ? $emit('select', file) : $emit('click', file)"
        >
          <div class="k-media-hub-card-checkbox" v-if="selectionMode || selected" @click.stop="$emit('select', file)">
            <svg v-if="selected" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="2" y="2" width="20" height="20" rx="4" fill="var(--color-focus,#2563eb)"/><polyline points="6 12 10 16 18 8" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/></svg>
            <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="4"/></svg>
          </div>
          <div class="k-media-hub-file-preview">
            <img v-if="file.thumb" :src="file.thumb" :alt="file.alt || file.filename" loading="lazy" />
            <span v-else class="k-media-hub-file-icon" v-html="iconFor(file.type)"></span>
          </div>
          <div class="k-media-hub-file-info">
            <span class="k-media-hub-file-name" :title="file.filename">{{ file.filename }}</span>
            <span class="k-media-hub-file-meta">{{ file.niceSize }} &middot; {{ (file.extension || '').toUpperCase() }}</span>
          </div>
          <button
            v-if="!selectionMode"
            class="k-media-hub-card-delete"
            @click.stop="$emit('delete', file.id)"
            title="Delete file"
          >×</button>
        </div>
      `,
    },

    // ── Type filter tabs ───────────────────────────────────────────────────
    'k-media-hub-type-filter': {
      props: {
        active: { type: String, default: '' },
      },
      emits: ['change'],
      data() {
        return {
          types: [
            { value: '',         label: 'All'       },
            { value: 'image',    label: 'Images'    },
            { value: 'document', label: 'Documents' },
            { value: 'video',    label: 'Videos'    },
            { value: 'audio',    label: 'Audio'     },
          ],
        };
      },
      template: `
        <div class="k-media-hub-type-filter">
          <button
            v-for="t in types"
            :key="t.value"
            :class="['k-media-hub-type-btn', { active: active === t.value }]"
            @click="$emit('change', t.value)"
          >{{ t.label }}</button>
        </div>
      `,
    },

    // ── File detail side panel ─────────────────────────────────────────────
    'k-media-hub-file-detail': {
      props: {
        file:    { type: Object, required: true },
        apiUrl:  { type: String, required: true },
        allTags: { type: Array,  default: () => [] },
      },
      emits: ['close', 'updated', 'deleted'],

      data() {
        return {
          form: {
            title:        this.file.title        || '',
            alt:          this.file.alt          || '',
            description:  this.file.description  || '',
            copyright:    this.file.copyright    || '',
            photographer: this.file.photographer || '',
            tags:         [...(this.file.tags    || [])],
          },
          tagInput: '',
          saving:   false,
          deleting: false,
        };
      },

      watch: {
        file(newFile) {
          this.form = {
            title:        newFile.title        || '',
            alt:          newFile.alt          || '',
            description:  newFile.description  || '',
            copyright:    newFile.copyright    || '',
            photographer: newFile.photographer || '',
            tags:         [...(newFile.tags    || [])],
          };
          this.tagInput = '';
        },
      },

      computed: {
        encodedId() {
          return encodeURIComponent(this.file.id).replace(/%2F/g, '+');
        },
      },

      methods: {
        addTag() {
          const tag = this.tagInput.trim().replace(/,\s*$/, '').trim();
          if (tag && !this.form.tags.includes(tag)) {
            this.form.tags.push(tag);
          }
          this.tagInput = '';
        },

        removeTag(idx) {
          this.form.tags.splice(idx, 1);
        },

        async saveMetadata() {
          // Auto-flush any text currently in the tag input before saving
          if (this.tagInput.trim()) {
            this.addTag();
          }
          this.saving = true;
          try {
            const payload = { ...this.form, tags: this.form.tags.join(', ') };
            await this.$panel.api.patch(
              'media-hub/files/' + this.encodedId + '/update',
              payload
            );
            this.$panel.notification.success('Saved');
            this.$emit('updated', { ...this.file, ...this.form });
          } catch (e) {
            this.$panel.notification.error('Save failed: ' + (e.message || e));
          } finally {
            this.saving = false;
          }
        },

        async deleteFile() {
          if (!confirm('Delete "' + this.file.filename + '"? This cannot be undone.')) return;
          this.deleting = true;
          try {
            await this.$panel.api.delete('media-hub/files/' + this.encodedId + '/delete');
            this.$panel.notification.success('File deleted');
            this.$emit('deleted', this.file.id);
          } catch (e) {
            this.$panel.notification.error('Delete failed: ' + (e.message || e));
            this.deleting = false;
          }
        },
      },

      template: `
        <div class="k-media-hub-detail">
          <div class="k-media-hub-detail-header">
            <span class="k-media-hub-detail-filename" :title="file.filename">{{ file.filename }}</span>
            <button class="k-media-hub-btn" @click="$emit('close')" title="Close">×</button>
          </div>

          <div class="k-media-hub-detail-preview">
            <img v-if="file.type === 'image' && file.url" :src="file.url" :alt="form.alt" />
            <div v-else class="k-media-hub-detail-icon">
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
          </div>

          <div class="k-media-hub-detail-meta">
            <div><strong>Size:</strong> {{ file.niceSize }}</div>
            <div><strong>Type:</strong> {{ (file.extension || '').toUpperCase() }}</div>
            <div><strong>Modified:</strong> {{ file.modified }}</div>
          </div>

          <div class="k-media-hub-detail-copy">
            <input
              :value="file.url"
              readonly
              class="k-media-hub-input k-media-hub-url-input"
              @click="$event.target.select()"
              title="File URL — click to select"
            />
          </div>

          <form class="k-media-hub-detail-form" @submit.prevent="saveMetadata">
            <label class="k-media-hub-field">
              <span>Title</span>
              <input v-model="form.title" type="text" class="k-media-hub-input" placeholder="File title…" />
            </label>
            <label class="k-media-hub-field">
              <span>Alt Text</span>
              <input v-model="form.alt" type="text" class="k-media-hub-input" placeholder="Describe the image…" />
            </label>
            <label class="k-media-hub-field">
              <span>Description</span>
              <textarea v-model="form.description" class="k-media-hub-input k-media-hub-textarea" rows="3" placeholder="Optional description…"></textarea>
            </label>
            <div class="k-media-hub-field-row">
              <label class="k-media-hub-field">
                <span>Copyright</span>
                <input v-model="form.copyright" type="text" class="k-media-hub-input" placeholder="© …" />
              </label>
              <label class="k-media-hub-field">
                <span>Photographer</span>
                <input v-model="form.photographer" type="text" class="k-media-hub-input" placeholder="Name…" />
              </label>
            </div>
            <div class="k-media-hub-field">
              <span>Tags</span>
              <div class="k-media-hub-tags-editor">
                <div v-if="form.tags.length" class="k-media-hub-tag-chips">
                  <span v-for="(tag, i) in form.tags" :key="i" class="k-media-hub-tag-chip">
                    {{ tag }}
                    <button type="button" @click.prevent="removeTag(i)" title="Remove tag">×</button>
                  </span>
                </div>
                <input
                  v-model="tagInput"
                  type="text"
                  class="k-media-hub-input k-media-hub-tag-add-input"
                  placeholder="Add tag and press Enter…"
                  list="k-media-hub-tag-suggestions"
                  @keydown.enter.prevent="addTag"
                />
                <datalist id="k-media-hub-tag-suggestions">
                  <option v-for="t in allTags" :key="t" :value="t" />
                </datalist>
              </div>
            </div>
            <button
              type="submit"
              class="k-media-hub-btn k-media-hub-btn--primary k-media-hub-btn--full"
              :disabled="saving"
            >{{ saving ? 'Saving…' : 'Save Metadata' }}</button>
          </form>

          <!-- Usage tracking -->
          <k-media-hub-usage
            :uuid="file.uuid"
            :api-url="apiUrl"
          />

          <div class="k-media-hub-detail-danger">
            <button
              class="k-media-hub-btn k-media-hub-btn--danger k-media-hub-btn--full"
              :disabled="deleting"
              @click="deleteFile"
            >{{ deleting ? 'Deleting…' : 'Delete File' }}</button>
          </div>
        </div>
      `,
    },

    // ── Duplicate detection view ───────────────────────────────────────────
    'k-media-hub-duplicates': {
      props: {
        apiUrl: { type: String, required: true },
      },
      emits: ['changed'],

      data() {
        return {
          loading:  true,
          exact:    [],
          similar:  [],
          stats:    {},
          working:  false,
        };
      },

      async created() {
        await this.scan();
      },

      methods: {
        async scan() {
          this.loading = true;
          try {
            const res    = await this.$panel.api.get('media-hub/duplicates');
            this.exact   = res.exact   || [];
            this.similar = res.similar || [];
            this.stats   = res.stats   || {};
          } catch (e) {
            this.$panel.notification.error('Scan failed: ' + (e.message || e));
          } finally {
            this.loading = false;
          }
        },

        async keepFile(keepId, groupFiles) {
          this.working = true;
          const toDelete = groupFiles.filter(f => f.id !== keepId);
          let deleted = 0;
          for (const f of toDelete) {
            try {
              const enc = encodeURIComponent(f.id).replace(/%2F/g, '+');
              await this.$panel.api.delete('media-hub/files/' + enc + '/delete');
              deleted++;
            } catch (e) {
              this.$panel.notification.error('Could not delete ' + f.filename);
            }
          }
          if (deleted) {
            this.$panel.notification.success(deleted + ' file(s) deleted');
            this.$emit('changed');
          }
          this.working = false;
          await this.scan();
        },

        async deleteSingle(file) {
          this.working = true;
          try {
            const enc = encodeURIComponent(file.id).replace(/%2F/g, '+');
            await this.$panel.api.delete('media-hub/files/' + enc + '/delete');
            this.$panel.notification.success('Deleted ' + file.filename);
            this.$emit('changed');
            await this.scan();
          } catch (e) {
            this.$panel.notification.error('Could not delete ' + file.filename);
          } finally {
            this.working = false;
          }
        },

        keepOldest(groupFiles) {
          const sorted = [...groupFiles].sort((a, b) => (a.modified || '').localeCompare(b.modified || ''));
          this.keepFile(sorted[0].id, groupFiles);
        },

        keepNewest(groupFiles) {
          const sorted = [...groupFiles].sort((a, b) => (b.modified || '').localeCompare(a.modified || ''));
          this.keepFile(sorted[0].id, groupFiles);
        },

        keepShortest(groupFiles) {
          const sorted = [...groupFiles].sort((a, b) => a.filename.length - b.filename.length);
          this.keepFile(sorted[0].id, groupFiles);
        },

        thumbOrIcon(file) {
          if (file.thumb) return '<img src="' + file.thumb + '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;border-radius:4px" />';
          return '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
        },
      },

      template: `
        <div class="k-media-hub-dupes">

          <!-- Header -->
          <div class="k-media-hub-dupes-header">
            <div class="k-media-hub-dupes-title">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              Duplicate Detection
            </div>
            <button type="button" class="k-media-hub-btn" @click="scan" :disabled="loading || working">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
              Re-scan
            </button>
          </div>

          <!-- Loading -->
          <div v-if="loading" class="k-media-hub-dupes-scanning">
            <div class="k-media-hub-spinner"></div>
            <span>Scanning for duplicates…</span>
          </div>

          <!-- All clean -->
          <div v-else-if="!exact.length && !similar.length" class="k-media-hub-dupes-clean">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <p>No duplicates found. Your library looks clean!</p>
          </div>

          <template v-else>

            <!-- Summary banner -->
            <div class="k-media-hub-dupes-summary">
              <span v-if="stats.exactGroups">
                <strong>{{ stats.exactGroups }}</strong> group{{ stats.exactGroups > 1 ? 's' : '' }} of exact copies
                (<strong>{{ stats.exactWasted }}</strong> redundant file{{ stats.exactWasted > 1 ? 's' : '' }})
              </span>
              <span v-if="stats.exactGroups && stats.similarGroups"> &bull; </span>
              <span v-if="stats.similarGroups">
                <strong>{{ stats.similarGroups }}</strong> similar-name group{{ stats.similarGroups > 1 ? 's' : '' }}
              </span>
            </div>

            <!-- Exact duplicates -->
            <div v-if="exact.length" class="k-media-hub-dupes-section">
              <div class="k-media-hub-dupes-section-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Exact Copies
                <span class="k-media-hub-dupes-section-hint">— identical file content, safe to delete any copy</span>
              </div>
              <div v-for="group in exact" :key="group.hash" class="k-media-hub-dupes-group">
                <div class="k-media-hub-dupes-group-actions">
                  <span class="k-media-hub-dupes-group-count">{{ group.files.length }} files</span>
                  <button type="button" class="k-media-hub-btn" :disabled="working" @click="keepShortest(group.files)" title="Keep the file with the shortest/cleanest name">Keep shortest name</button>
                  <button type="button" class="k-media-hub-btn" :disabled="working" @click="keepOldest(group.files)"   title="Keep the oldest file, delete the rest">Keep oldest</button>
                  <button type="button" class="k-media-hub-btn" :disabled="working" @click="keepNewest(group.files)"   title="Keep the newest file, delete the rest">Keep newest</button>
                </div>
                <div class="k-media-hub-dupes-cards">
                  <div v-for="file in group.files" :key="file.id" class="k-media-hub-dupes-card">
                    <div class="k-media-hub-dupes-card-thumb" v-html="thumbOrIcon(file)"></div>
                    <div class="k-media-hub-dupes-card-info">
                      <span class="k-media-hub-dupes-card-name" :title="file.filename">{{ file.filename }}</span>
                      <span class="k-media-hub-dupes-card-meta">{{ file.niceSize }} &bull; {{ file.modified }}</span>
                    </div>
                    <div class="k-media-hub-dupes-card-btns">
                      <button type="button" class="k-media-hub-btn k-media-hub-btn--primary" :disabled="working" @click="keepFile(file.id, group.files)">Keep this</button>
                      <button type="button" class="k-media-hub-btn k-media-hub-btn--danger-outline" :disabled="working" @click="deleteSingle(file)">Delete</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Similar names -->
            <div v-if="similar.length" class="k-media-hub-dupes-section">
              <div class="k-media-hub-dupes-section-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Similar Names
                <span class="k-media-hub-dupes-section-hint">— same base name, possibly different versions</span>
              </div>
              <div v-for="group in similar" :key="group.baseName" class="k-media-hub-dupes-group">
                <div class="k-media-hub-dupes-group-actions">
                  <span class="k-media-hub-dupes-group-label">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                    {{ group.baseName }}
                  </span>
                  <span class="k-media-hub-dupes-group-count">{{ group.files.length }} files</span>
                </div>
                <div class="k-media-hub-dupes-cards">
                  <div v-for="file in group.files" :key="file.id" class="k-media-hub-dupes-card">
                    <div class="k-media-hub-dupes-card-thumb" v-html="thumbOrIcon(file)"></div>
                    <div class="k-media-hub-dupes-card-info">
                      <span class="k-media-hub-dupes-card-name" :title="file.filename">{{ file.filename }}</span>
                      <span class="k-media-hub-dupes-card-meta">{{ file.niceSize }} &bull; {{ file.modified }}</span>
                    </div>
                    <div class="k-media-hub-dupes-card-btns">
                      <button type="button" class="k-media-hub-btn k-media-hub-btn--danger-outline" :disabled="working" @click="deleteSingle(file)">Delete</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </template>

        </div>
      `,
    },

    // ── Usage tracker ──────────────────────────────────────────────────────
    'k-media-hub-usage': {
      props: {
        uuid:   { type: String, required: true },
        apiUrl: { type: String, required: true },
      },
      data() {
        return {
          open:    false,
          usages:  [],
          count:   '?',
          loading: false,
          loaded:  false,
        };
      },
      methods: {
        async toggle() {
          this.open = !this.open;
          if (this.open && !this.loaded) {
            await this.loadUsages();
          }
        },
        async loadUsages() {
          this.loading = true;
          try {
            const res   = await this.$panel.api.get('media-hub/usage/' + encodeURIComponent(this.uuid));
            this.usages = res.usages || [];
            this.count  = res.count  || 0;
            this.loaded = true;
          } catch (e) {
            // non-critical
          } finally {
            this.loading = false;
          }
        },
      },
      template: `
        <div class="k-media-hub-usage">
          <button class="k-media-hub-usage-toggle" @click="toggle">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            Used on {{ count }} page{{ count !== 1 ? 's' : '' }}
            <span>{{ open ? '▲' : '▼' }}</span>
          </button>
          <div v-if="open">
            <div v-if="loading" class="k-media-hub-usage-loading">Checking…</div>
            <ul v-else-if="usages.length" class="k-media-hub-usage-list">
              <li v-for="u in usages" :key="u.pageId">
                <a :href="u.url" target="_blank">{{ u.title || u.pageId }}</a>
                <small>({{ u.field }})</small>
              </li>
            </ul>
            <p v-else class="k-media-hub-usage-empty">Not used on any page.</p>
          </div>
        </div>
      `,
    },

    // ── Media Hub Picker field component ───────────────────────────────────
    // Uses an INLINE expandable picker (no modal overlay) so it works safely
    // inside Kirby structure dialogs without triggering their outside-click handler.
    'k-mediahubpicker-field': {
      props: {
        label:    { type: String,  default: 'Media Hub Files' },
        help:     { type: String,  default: null },
        disabled: { type: Boolean, default: false },
        value:    { type: Array,   default: () => [] },
        multiple: { type: Boolean, default: true },
        accept:   { type: String,  default: '' },
      },
      emits: ['input'],

      data() {
        return {
          selected:              Array.isArray(this.value) ? [...this.value] : [],
          showPicker:            false,
          pickerItems:           [],
          pickerFolders:         [],
          pickerTags:            [],
          pickerFolder:          '',
          pickerTag:             '',
          expandedPickerFolders: [],
          pickerLoading:         false,
          pickerSearch:          '',
          pickerPagination:      { total: 0, page: 1, limit: 30 },
          pickerTimer:           null,
          pending:               [],
        };
      },

      computed: {
        canAdd() {
          return !this.disabled && (this.multiple || this.selected.length === 0);
        },
      },

      methods: {
        openPicker() {
          this.pending    = [...this.selected];
          this.showPicker = true;
          this.loadPickerPage(1);
        },

        closePicker() {
          this.showPicker            = false;
          this.pickerSearch          = '';
          this.pickerFolder          = '';
          this.pickerTag             = '';
          this.pickerItems           = [];
          this.pickerTags            = [];
          this.pickerFolders         = [];
          this.expandedPickerFolders = [];
        },

        async loadPickerPage(page = 1) {
          this.pickerLoading = true;
          try {
            const params = new URLSearchParams({ page, q: this.pickerSearch });
            if (this.accept)       params.set('type',   this.accept);
            if (this.pickerFolder) params.set('folder', this.pickerFolder);
            if (this.pickerTag)    params.set('tag',    this.pickerTag);
            const res              = await this.$panel.api.get('media-hub/picker?' + params.toString());
            this.pickerItems       = res.data       || [];
            this.pickerFolders     = res.folderTree  || [];
            this.pickerTags        = res.tags        || [];
            this.pickerPagination  = res.pagination  || { total: 0, page: 1, limit: 30 };
          } catch (e) {
            this.$panel.notification.error('Could not load Media Hub files');
          } finally {
            this.pickerLoading = false;
          }
        },

        setPickerFolder(path) {
          this.pickerFolder = path;
          this.pickerTag    = '';
          this.loadPickerPage(1);
        },

        setPickerTag(tag) {
          this.pickerTag    = (this.pickerTag === tag) ? '' : tag;
          this.pickerFolder = '';
          this.loadPickerPage(1);
        },

        togglePickerExpand(path) {
          const i = this.expandedPickerFolders.indexOf(path);
          if (i > -1) this.expandedPickerFolders.splice(i, 1);
          else this.expandedPickerFolders.push(path);
        },

        onPickerSearch() {
          clearTimeout(this.pickerTimer);
          this.pickerTimer = setTimeout(() => this.loadPickerPage(1), 400);
        },

        isSelected(item) {
          return this.pending.some(s => s.uuid === item.uuid);
        },

        toggleItem(item) {
          if (this.multiple) {
            const idx = this.pending.findIndex(s => s.uuid === item.uuid);
            if (idx > -1) this.pending.splice(idx, 1);
            else this.pending.push(item);
          } else {
            this.pending = [item];
          }
        },

        confirmPicker() {
          this.selected = [...this.pending];
          this.$emit('input', this.selected);
          this.closePicker();
        },

        removeItem(idx) {
          this.selected.splice(idx, 1);
          this.$emit('input', this.selected);
        },
      },

      template: `
        <div class="k-mediahubpicker-field">
          <label class="k-mediahubpicker-label">{{ label }}</label>

          <!-- Selected items list -->
          <div v-if="selected.length" class="k-mediahubpicker-selected">
            <div v-for="(item, idx) in selected" :key="item.uuid" class="k-mediahubpicker-item-selected">
              <img v-if="item.type === 'image' && item.thumb" :src="item.thumb" :alt="item.title" />
              <svg v-else width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <span>{{ item.title || item.filename }}</span>
              <button type="button" @click.stop="removeItem(idx)" title="Remove">×</button>
            </div>
          </div>

          <!-- Toggle button -->
          <button
            v-if="canAdd && !showPicker"
            type="button"
            class="k-media-hub-btn k-media-hub-btn--primary"
            @click.stop="openPicker"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            {{ selected.length ? (multiple ? 'Add more' : 'Change') : 'Select from Media Hub' }}
          </button>

          <!-- Inline expandable picker (no overlay — works inside Kirby structure dialogs) -->
          <div v-if="showPicker" class="k-mediahubpicker-inline">

            <div class="k-mediahubpicker-inline-header">
              <input
                v-model="pickerSearch"
                type="text"
                placeholder="Search by filename, alt, description…"
                class="k-media-hub-input"
                @input="onPickerSearch"
              />
              <button type="button" class="k-media-hub-btn" @click.stop="closePicker" title="Close">×</button>
            </div>

            <!-- Sidebar + grid -->
            <div class="k-mediahubpicker-content">

              <!-- Left sidebar: folders + tags -->
              <div class="k-mediahubpicker-sidebar">

                <!-- Folders -->
                <div class="k-mediahubpicker-sb-section">
                  <div class="k-mediahubpicker-sb-label">Folders</div>
                  <button
                    type="button"
                    :class="['k-mediahubpicker-sb-item', { active: pickerFolder === '' && pickerTag === '' }]"
                    @click.stop="setPickerFolder('')"
                  >
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                    All Files
                  </button>
                  <template v-for="f in pickerFolders" :key="f.path">
                    <div class="k-mediahubpicker-sb-folder-row">
                      <button
                        v-if="f.children && f.children.length"
                        type="button"
                        class="k-mediahubpicker-sb-expand"
                        @click.stop="togglePickerExpand(f.path)"
                      >{{ expandedPickerFolders.includes(f.path) ? '▾' : '▸' }}</button>
                      <span v-else class="k-mediahubpicker-sb-expand"></span>
                      <button
                        type="button"
                        :class="['k-mediahubpicker-sb-item k-mediahubpicker-sb-item--folder', { active: pickerFolder === f.path }]"
                        @click.stop="setPickerFolder(f.path)"
                      >{{ f.title }}</button>
                    </div>
                    <template v-if="f.children && f.children.length && expandedPickerFolders.includes(f.path)">
                      <button
                        v-for="c in f.children"
                        :key="c.path"
                        type="button"
                        :class="['k-mediahubpicker-sb-item k-mediahubpicker-sb-item--sub', { active: pickerFolder === c.path }]"
                        @click.stop="setPickerFolder(c.path)"
                      >{{ c.title }}</button>
                    </template>
                  </template>
                </div>

                <!-- Tags -->
                <div v-if="pickerTags.length" class="k-mediahubpicker-sb-section">
                  <div class="k-mediahubpicker-sb-divider"></div>
                  <div class="k-mediahubpicker-sb-label">Tags</div>
                  <button
                    v-for="t in pickerTags"
                    :key="t.tag"
                    type="button"
                    :class="['k-mediahubpicker-sb-item k-mediahubpicker-sb-item--tag', { active: pickerTag === t.tag }]"
                    @click.stop="setPickerTag(t.tag)"
                  >
                    <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                    <span>{{ t.tag }}</span>
                    <span class="k-mediahubpicker-sb-count">{{ t.count }}</span>
                  </button>
                </div>

              </div>

              <!-- File grid -->
              <div class="k-mediahubpicker-inline-body">
                <div v-if="pickerLoading" class="k-media-hub-loading">
                  <div class="k-media-hub-spinner"></div>
                </div>
                <div v-else-if="pickerItems.length" class="k-mediahubpicker-inline-grid">
                  <div
                    v-for="item in pickerItems"
                    :key="item.uuid"
                    :class="['k-mediahubpicker-pick', { selected: isSelected(item) }]"
                    @click.stop="toggleItem(item)"
                  >
                    <div class="k-mediahubpicker-pick-preview">
                      <img v-if="item.thumb" :src="item.thumb" :alt="item.title" loading="lazy" />
                      <svg v-else width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <span class="k-mediahubpicker-pick-name">{{ item.title || item.filename }}</span>
                    <span v-if="isSelected(item)" class="k-mediahubpicker-pick-check">✓</span>
                  </div>
                </div>
                <div v-else class="k-media-hub-empty" style="padding:1.5rem;text-align:center;color:var(--color-text-dimmed,#888)">
                  No files found.
                </div>
              </div>

            </div>

            <div class="k-mediahubpicker-inline-footer">
              <div v-if="pickerPagination.total > pickerPagination.limit" class="k-mediahubpicker-pagination">
                <button
                  type="button"
                  class="k-media-hub-btn"
                  :disabled="pickerPagination.page <= 1"
                  @click.stop="loadPickerPage(pickerPagination.page - 1)"
                >←</button>
                <span>{{ pickerPagination.page }} / {{ Math.ceil(pickerPagination.total / pickerPagination.limit) }}</span>
                <button
                  type="button"
                  class="k-media-hub-btn"
                  :disabled="pickerPagination.page >= Math.ceil(pickerPagination.total / pickerPagination.limit)"
                  @click.stop="loadPickerPage(pickerPagination.page + 1)"
                >→</button>
              </div>
              <div class="k-mediahubpicker-inline-actions">
                <button type="button" class="k-media-hub-btn" @click.stop="closePicker">Cancel</button>
                <button type="button" class="k-media-hub-btn k-media-hub-btn--primary" @click.stop="confirmPicker">
                  Confirm{{ pending.length ? ' (' + pending.length + ')' : '' }}
                </button>
              </div>
            </div>

          </div>

          <p v-if="help" class="k-mediahubpicker-help">{{ help }}</p>
        </div>
      `,
    },

  }, // end components

}); // end window.panel.plugin
