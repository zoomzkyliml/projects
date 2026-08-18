<?php
declare(strict_types=1);
?><!doctype html>
<html lang="sv" class="auth-checking">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mina projekt</title>
  <link rel="stylesheet" href="assets/css/projects.css?v=3">
</head>
<body>
  <header class="site-header">
    <div>
      <p class="eyebrow">PROJEKTPORTFÖLJ</p>
      <h1>Mina projekt</h1>
      <p class="intro">Pågående och slutförda projekt, dokumenterade med uppdateringar, bilder och länkar.</p>
    </div>

    <div class="auth-panel">
      <span class="mode-badge" data-auth-status>Kontrollerar läge…</span>
      <button
        type="button"
        class="auth-icon-button"
        data-auth-toggle
        aria-label="Öppna adminläge"
        title="Öppna adminläge"
      >
        <svg class="auth-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M7 10V8a5 5 0 0 1 10 0v2"></path>
          <rect x="5" y="10" width="14" height="10" rx="2"></rect>
          <path d="M12 14v2"></path>
        </svg>
        <span class="sr-only" data-auth-toggle-label>Adminläge</span>
      </button>
    </div>
  </header>

  <main class="page-shell">
    <section class="admin-toolbar" data-auth-only hidden>
      <div>
        <p class="eyebrow">ADMINLÄGE</p>
        <strong>Projektadministration</strong>
      </div>

      <button type="button" id="new-project-button" class="button">
        Nytt projekt
      </button>
    </section>

    <section id="project-editor" class="admin-card" hidden>
      <div class="section-heading">
        <div>
          <p class="eyebrow">PROJEKTEDITOR</p>
          <h2 id="editor-title">Nytt projekt</h2>
        </div>

        <button type="button" id="close-editor-button" class="button secondary">
          Stäng
        </button>
      </div>

      <form id="project-form" class="project-form">
        <input type="hidden" name="id" value="">

        <label>
          Projektnamn
          <input type="text" name="title" maxlength="180" required>
        </label>

        <label>
          Kort sammanfattning
          <textarea name="summary" rows="3" maxlength="500"></textarea>
        </label>

        <label>
          Teknik
          <textarea name="technology" rows="4"></textarea>
        </label>

        <label>
          Beskrivning
          <textarea name="description" rows="7"></textarea>
        </label>

        <label>
          Kvar att göra
          <textarea name="todo" rows="4"></textarea>
        </label>

        <div class="form-row">
          <label>
            Årtal
            <input type="number" name="project_year" min="1900" max="2200" step="1">
          </label>

          <label>
            Status
            <select name="status">
              <option value="ongoing">Pågående</option>
              <option value="planned">Planerat</option>
              <option value="paused">Pausat</option>
              <option value="completed">Slutfört</option>
              <option value="archived">Arkiverat</option>
            </select>
          </label>
        </div>

        <label>
          Publicering
          <select name="publication_status">
            <option value="published">Publicerad</option>
            <option value="draft">Utkast</option>
            <option value="archived">Arkiverad</option>
          </select>
        </label>

        <section class="cover-editor">
          <div>
            <span class="field-label">Projektbild</span>
            <img
              id="cover-preview"
              class="cover-preview"
              src="assets/images/standard.png"
              alt="Förhandsvisning av projektbild"
            >
          </div>

          <div class="cover-controls">
            <label class="cover-file-label" for="cover-file">
              Välj eller byt projektbild
            </label>
            <input
              id="cover-file"
              type="file"
              accept="image/jpeg,image/png,image/webp,image/gif"
            >
            <small id="cover-help" class="muted">
              Bilden laddas upp automatiskt när du sparar projektet.
              JPG, PNG, WebP eller GIF, max 8 MB.
            </small>
          </div>
        </section>

        <section id="project-links-section" class="project-links-editor" hidden>
          <div class="section-heading compact">
            <div>
              <p class="eyebrow">LÄNKAR</p>
              <h3>Projektlänkar</h3>
            </div>
          </div>

          <div id="project-links-list" class="project-links-list"></div>

          <div class="project-link-form">
            <label>
              Typ
              <select id="project-link-type">
                <option value="website">Webbsida</option>
                <option value="youtube">YouTube</option>
                <option value="blog">Blogg</option>
                <option value="github">GitHub</option>
                <option value="documentation">Dokumentation</option>
                <option value="download">Nedladdning</option>
                <option value="other">Övrigt</option>
              </select>
            </label>

            <label>
              Rubrik
              <input id="project-link-title" type="text" maxlength="180">
            </label>

            <label>
              URL
              <input id="project-link-url" type="url" placeholder="https://">
            </label>

            <button type="button" id="add-project-link-button" class="button secondary">
              Lägg till länk
            </button>
          </div>
        </section>

        <section id="project-blog-section" class="project-blog-editor" hidden>
          <div class="section-heading compact">
            <div>
              <p class="eyebrow">PROJEKTLOGG</p>
              <h3>Blogginlägg</h3>
            </div>

            <button type="button" id="new-post-button" class="button secondary">
              Nytt inlägg
            </button>
          </div>

          <div class="project-blog-layout">
            <div id="project-post-list" class="project-post-list"></div>

            <div id="project-post-editor" class="project-post-editor" hidden>
              <input type="hidden" id="post-id" value="">

              <label>
                Rubrik
                <input id="post-title" type="text" maxlength="180">
              </label>

              <label>
                Datum
                <input id="post-published-at" type="date">
              </label>

              <label>
                Text
                <textarea id="post-content" rows="10"></textarea>
              </label>

              <label>
                Publicering
                <select id="post-publication-status">
                  <option value="published">Publicerad</option>
                  <option value="draft">Utkast</option>
                  <option value="archived">Arkiverad</option>
                </select>
              </label>

              <div class="form-actions">
                <button type="button" id="save-post-button" class="button">
                  Spara inlägg
                </button>
                <button type="button" id="delete-post-button" class="button danger" hidden>
                  Ta bort inlägg
                </button>
                <button type="button" id="close-post-button" class="button secondary">
                  Stäng inlägg
                </button>
              </div>

              <p id="post-message" class="form-message" aria-live="polite"></p>

              <section id="post-images-section" class="post-images-section" hidden>
                <div class="section-heading compact">
                  <div>
                    <p class="eyebrow">BILDER</p>
                    <h4>Inläggets bilder</h4>
                  </div>
                </div>

                <div class="post-image-upload">
                  <input
                    id="post-image-files"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    multiple
                  >
                  <button type="button" id="upload-post-images-button" class="button secondary">
                    Ladda upp bilder
                  </button>
                </div>

                <div id="post-images-list" class="post-images-list"></div>
              </section>
            </div>
          </div>
        </section>

        <div class="form-actions">
          <button type="submit" class="button">Spara projekt</button>
          <button type="button" id="delete-project-button" class="button danger" hidden>
            Ta bort
          </button>
        </div>

        <p id="form-message" class="form-message" aria-live="polite"></p>
      </form>
    </section>

    <section>
      <div class="section-heading">
        <div>
          <p class="eyebrow">AKTUELLT</p>
          <h2>Pågående projekt</h2>
        </div>
        <span id="ongoing-count" class="count-badge">0</span>
      </div>
      <div id="ongoing-projects" class="project-grid"></div>
    </section>

    <section>
      <div class="section-heading">
        <div>
          <p class="eyebrow">ARKIV</p>
          <h2>Slutförda projekt</h2>
        </div>
        <span id="completed-count" class="count-badge">0</span>
      </div>
      <div id="completed-projects" class="project-grid"></div>
    </section>
  </main>

  <div id="project-detail-backdrop" class="project-detail-backdrop" hidden>
    <section
      id="project-detail"
      class="project-detail"
      role="dialog"
      aria-modal="true"
      aria-labelledby="project-detail-title"
    >
      <div class="project-detail-header-actions">
        <button
          type="button"
          id="project-detail-info-button"
          class="project-detail-icon-button"
          aria-label="Visa projektinformation"
          title="Projektinformation"
          aria-pressed="false"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 11v6"></path>
            <path d="M12 7h.01"></path>
          </svg>
        </button>

        <button
          type="button"
          id="project-detail-close"
          class="project-detail-close"
          aria-label="Stäng projekt"
          title="Stäng"
        >
          ×
        </button>
      </div>

      <header class="project-detail-hero">
        <img
          id="project-detail-image"
          class="project-detail-image"
          src="assets/images/standard.png"
          alt=""
        >

        <div class="project-detail-intro">
          <div class="project-detail-meta">
            <span id="project-detail-status" class="status-badge"></span>
            <span id="project-detail-year" class="project-detail-year"></span>
          </div>

          <h2 id="project-detail-title"></h2>
          <p id="project-detail-summary" class="project-detail-summary"></p>
        </div>
      </header>

      <div id="project-detail-body" class="project-detail-body">
        <main
          id="project-detail-log-view"
          class="project-detail-view project-detail-log-view"
        >
          <div class="section-heading compact">
            <div>
              <p class="eyebrow">PROJEKTLOGG</p>
              <h3>Senaste uppdateringarna</h3>
            </div>
          </div>

          <div id="project-detail-posts" class="project-detail-posts"></div>
        </main>

        <main
          id="project-detail-info-view"
          class="project-detail-view project-detail-info-view"
          hidden
        >
          <div class="project-detail-info-heading">
            <button
              type="button"
              id="project-detail-back-button"
              class="project-detail-back-button"
            >
              <span aria-hidden="true">←</span>
              Projektlogg
            </button>

            <div>
              <p class="eyebrow">PROJEKTINFORMATION</p>
              <h3>Om projektet</h3>
            </div>
          </div>

          <div class="project-detail-info-grid">
            <section id="project-detail-technology-section" class="project-detail-info">
              <h3>Teknik</h3>
              <div id="project-detail-technology"></div>
            </section>

            <section id="project-detail-description-section" class="project-detail-info">
              <h3>Beskrivning</h3>
              <div id="project-detail-description"></div>
            </section>

            <section id="project-detail-todo-section" class="project-detail-info">
              <h3>Kvar att göra</h3>
              <div id="project-detail-todo"></div>
            </section>

            <section id="project-detail-links-section" class="project-detail-info">
              <h3>Länkar</h3>
              <div id="project-detail-links" class="project-detail-links"></div>
            </section>
          </div>
        </main>
      </div>
    </section>
  </div>

  <div
    id="image-lightbox"
    class="image-lightbox"
    role="dialog"
    aria-modal="true"
    aria-label="Förstorad bild"
    hidden
  >
    <button
      type="button"
      id="image-lightbox-close"
      class="image-lightbox-close"
      aria-label="Stäng bild"
      title="Stäng"
    >
      ×
    </button>

    <figure class="image-lightbox-content">
      <img id="image-lightbox-image" src="" alt="">
      <figcaption id="image-lightbox-caption" hidden></figcaption>
    </figure>
  </div>

  <template id="project-card-template">
    <article class="project-card">
      <img class="project-card-image" alt="">
      <div class="project-card-body">
        <div class="project-card-meta">
          <span class="status-badge"></span>
          <span class="publication-badge" data-auth-only hidden></span>
        </div>
        <h3></h3>
        <p class="project-summary"></p>
        <div class="project-card-footer">
          <time></time>
        </div>
      </div>
    </article>
  </template>

  <script src="assets/js/auth.js?v=3"></script>
  <script src="assets/js/projects.js?v=3"></script>
</body>
</html>
