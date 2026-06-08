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
          activeFolderSlug: this.currentFolder || null,
          files:            [],
          loading:          false,
          pagination:       { total: 0, page: 1, limit: 40 },
          searchQuery:      '',
          activeType:       '',
          showSearch:       false,
          showNewFolder:    false,
          newFolderName:    '',
          activeFile:       null,
          searchTimer:      null,
          statsRefreshKey:  0,
        };
      },

      created() {
        this.loadFiles();
      },

      computed: {
        panelUrl() {
          const path = window.location.pathname;
          const idx  = path.indexOf('/media-hub');
          return idx > -1 ? path.substring(0, idx) : '/panel';
        },

        currentUploadUrl() {
          // uploadApiBase is a relative path like 'pages/media-hub'.
          // $panel.api prepends the API base URL automatically.
          if (this.activeFolderSlug) {
            // 'pages/media-hub' + '+images' + '/files' = 'pages/media-hub+images/files'
            return this.uploadApiBase + '+' + this.activeFolderSlug + '/files';
          }
          return this.uploadApiBase + '/files';
        },
      },

      methods: {
        async loadFiles(page = 1) {
          this.loading = true;
          try {
            const params = new URLSearchParams({ page });
            if (this.activeFolderSlug) params.set('folder', this.activeFolderSlug);
            if (this.searchQuery)      params.set('q', this.searchQuery);
            if (this.activeType)       params.set('type', this.activeType);

            const res        = await this.$panel.api.get('media-hub/files?' + params.toString());
            this.files       = res.data       || [];
            this.pagination  = res.pagination || { total: 0, page: 1, limit: 40 };
          } catch (e) {
            this.$panel.notification.error('Could not load files: ' + (e.message || e));
          } finally {
            this.loading = false;
          }
        },

        selectFolder(slug) {
          this.activeFolderSlug = slug;
          this.activeFile       = null;
          this.pagination.page  = 1;
          this.loadFiles();
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

        onPaginate(page) {
          this.loadFiles(page);
        },

        async createFolder() {
          const title = this.newFolderName.trim();
          if (!title) return;
          try {
            const res = await this.$panel.api.post('media-hub/folders', { title });
            if (res.status === 'ok' && res.data) {
              this.localFolders.push(res.data);
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
          try {
            await this.$panel.api.delete('media-hub/folders/' + folder.slug);
            this.localFolders = this.localFolders.filter(f => f.slug !== folder.slug);
            this.statsRefreshKey++;
            if (this.activeFolderSlug === folder.slug) {
              this.activeFolderSlug = null;
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
            this.statsRefreshKey++;
          }
        },

        openFile(file) {
          this.activeFile = file;
        },

        closeDetail() {
          this.activeFile = null;
        },

        onFileUpdated(updated) {
          const idx = this.files.findIndex(f => f.id === updated.id);
          if (idx > -1) this.files.splice(idx, 1, updated);
          this.activeFile = updated;
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

        onFileDeleted(fileId) {
          this.files      = this.files.filter(f => f.id !== fileId);
          this.activeFile = null;
          this.statsRefreshKey++;
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
              <span class="k-media-hub-breadcrumb-current">Media Hub</span>
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
                  :class="{ active: !activeFolderSlug }"
                  @click="selectFolder(null)"
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
                  All Files
                </li>
                <li
                  v-for="folder in localFolders"
                  :key="folder.id"
                  :class="{ active: activeFolderSlug === folder.slug }"
                  @click="selectFolder(folder.slug)"
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                  <span class="k-media-hub-folder-name">{{ folder.title }}</span>
                  <span class="k-media-hub-folder-count">{{ folder.fileCount }}</span>
                  <button
                    class="k-media-hub-folder-delete"
                    @click.stop="deleteFolder(folder)"
                    title="Delete folder"
                  >×</button>
                </li>
              </ul>
            </nav>

            <!-- Main content -->
            <div class="k-media-hub-content">

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
                  @click="openFile(file)"
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

            </div>
          </div>

          <!-- File detail side panel -->
          <k-media-hub-file-detail
            v-if="activeFile"
            :file="activeFile"
            :api-url="apiUrl"
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
        file: { type: Object, required: true },
      },
      emits: ['click', 'delete'],
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
        <div class="k-media-hub-file-card" @click="$emit('click', file)">
          <div class="k-media-hub-file-preview">
            <img v-if="file.thumb" :src="file.thumb" :alt="file.alt || file.filename" loading="lazy" />
            <span v-else class="k-media-hub-file-icon" v-html="iconFor(file.type)"></span>
          </div>
          <div class="k-media-hub-file-info">
            <span class="k-media-hub-file-name" :title="file.filename">{{ file.filename }}</span>
            <span class="k-media-hub-file-meta">{{ file.niceSize }} &middot; {{ (file.extension || '').toUpperCase() }}</span>
          </div>
          <button
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
        file:   { type: Object, required: true },
        apiUrl: { type: String, required: true },
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
          },
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
          };
        },
      },

      computed: {
        encodedId() {
          return encodeURIComponent(this.file.id).replace(/%2F/g, '+');
        },
      },

      methods: {
        async saveMetadata() {
          this.saving = true;
          try {
            await this.$panel.api.patch(
              'media-hub/files/' + this.encodedId + '/update',
              this.form
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
          selected:         Array.isArray(this.value) ? [...this.value] : [],
          showPicker:       false,
          pickerItems:      [],
          pickerFolders:    [],
          pickerFolder:     '',
          pickerLoading:    false,
          pickerSearch:     '',
          pickerPagination: { total: 0, page: 1, limit: 30 },
          pickerTimer:      null,
          pending:          [],
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
          this.showPicker   = false;
          this.pickerSearch = '';
          this.pickerFolder = '';
          this.pickerItems  = [];
        },

        async loadPickerPage(page = 1) {
          this.pickerLoading = true;
          try {
            const params = new URLSearchParams({ page, q: this.pickerSearch });
            if (this.accept)       params.set('type',   this.accept);
            if (this.pickerFolder) params.set('folder', this.pickerFolder);
            const res             = await this.$panel.api.get('media-hub/picker?' + params.toString());
            this.pickerItems      = res.data    || [];
            this.pickerFolders    = res.folders || [];
            this.pickerPagination = res.pagination || { total: 0, page: 1, limit: 30 };
          } catch (e) {
            this.$panel.notification.error('Could not load Media Hub files');
          } finally {
            this.pickerLoading = false;
          }
        },

        setPickerFolder(slug) {
          this.pickerFolder = slug;
          this.loadPickerPage(1);
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

            <!-- Folder tabs -->
            <div v-if="pickerFolders.length" class="k-mediahubpicker-folders">
              <button
                type="button"
                :class="['k-mediahubpicker-folder-tab', { active: pickerFolder === '' }]"
                @click.stop="setPickerFolder('')"
              >All</button>
              <button
                v-for="f in pickerFolders"
                :key="f.slug"
                type="button"
                :class="['k-mediahubpicker-folder-tab', { active: pickerFolder === f.slug }]"
                @click.stop="setPickerFolder(f.slug)"
              >{{ f.title }}</button>
            </div>

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
              <div v-else class="k-media-hub-empty" style="padding:1rem;text-align:center">
                No files found.
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
