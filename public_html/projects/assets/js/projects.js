"use strict";

(() => {
  const API_URL = "/api.php";
  const DEFAULT_IMAGE = "assets/images/standard.png";

  const statusNames = {
    planned: "Planerat",
    ongoing: "Pågående",
    paused: "Pausat",
    completed: "Slutfört",
    archived: "Arkiverat",
  };

  const linkTypeNames = {
    website: "Webbsida",
    youtube: "YouTube",
    blog: "Blogg",
    github: "GitHub",
    documentation: "Dokumentation",
    download: "Nedladdning",
    other: "Övrigt",
  };

  const state = {
    rows: [],
    selectedId: null,
    publicPostOrder: "newest",
    currentPublicProjectId: null,
  };

  async function api(action, payload = {}) {
    const body = new FormData();
    body.append("action", action);

    Object.entries(payload).forEach(([key, value]) => {
      body.append(key, value ?? "");
    });

    const response = await fetch(API_URL, {
      method: "POST",
      body,
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });

    const text = await response.text();

    let data = null;

    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(
        `API ${action} returnerade ogiltig JSON (${response.status}). ${text}`,
      );
    }

    if (!response.ok || data.success !== true) {
      const error = new Error(
        data.message || `API ${action} misslyckades (${response.status})`,
      );
      error.status = response.status;
      error.data = data;
      throw error;
    }

    return data;
  }

  function createEmptyState(text) {
    const element = document.createElement("div");
    element.className = "empty-state";
    element.textContent = text;
    return element;
  }

  function getForm() {
    return document.getElementById("project-form");
  }

  function setCoverPreview(value) {
    const preview = document.getElementById("cover-preview");
    const imageUrl = String(value || DEFAULT_IMAGE).trim() || DEFAULT_IMAGE;

    preview.src = imageUrl;
    preview.onerror = () => {
      preview.onerror = null;
      preview.src = DEFAULT_IMAGE;
    };
  }


  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function normalizeUrl(value) {
    const raw = String(value ?? "").trim();

    if (!raw) {
      return "";
    }

    try {
      const parsed = new URL(raw);
      return ["http:", "https:"].includes(parsed.protocol)
        ? parsed.href
        : "";
    } catch {
      return "";
    }
  }

  function renderProjectLinks(rows) {
    const container = document.getElementById("project-links-list");
    const safeRows = Array.isArray(rows) ? rows : [];

    if (!safeRows.length) {
      container.innerHTML =
        '<div class="empty-inline">Inga länkar tillagda ännu.</div>';
      return;
    }

    container.innerHTML = safeRows
      .map((link) => {
        const safeUrl = normalizeUrl(link.url);

        return `
          <div class="project-link-row" data-link-id="${escapeHtml(link.id)}">
            <div class="project-link-main">
              <span class="project-link-type">
                ${escapeHtml(linkTypeNames[link.link_type] || link.link_type)}
              </span>
              <a
                href="${escapeHtml(safeUrl || "#")}"
                target="_blank"
                rel="noopener noreferrer"
                ${safeUrl ? "" : 'aria-disabled="true"'}
              >
                ${escapeHtml(link.title || link.url)}
              </a>
              <span class="project-link-url">${escapeHtml(link.url)}</span>
            </div>

            <button
              type="button"
              class="button small danger delete-project-link"
              data-link-id="${escapeHtml(link.id)}"
            >
              Ta bort
            </button>
          </div>
        `;
      })
      .join("");
  }

  async function loadProjectLinks(projectId) {
    const data = await api("PROJECT_LINK_LIST", {
      project_id: projectId,
    });

    renderProjectLinks(data.rows);
  }

  function openEditor() {
    const editor = document.getElementById("project-editor");
    editor.hidden = false;

    editor.scrollIntoView({
      behavior: "smooth",
      block: "start",
    });
  }

  function closeEditor() {
    const editor = document.getElementById("project-editor");
    editor.hidden = true;
  }

  function formatPostDate(value) {
    const raw = String(value || "").trim();
    return raw ? raw.slice(0, 10) : "";
  }

  function clearPostEditor() {
    document.getElementById("post-id").value = "";
    document.getElementById("post-title").value = "";
    document.getElementById("post-published-at").value =
      new Date().toISOString().slice(0, 10);
    document.getElementById("post-content").value = "";
    document.getElementById("post-publication-status").value = "published";
    document.getElementById("delete-post-button").hidden = true;
    document.getElementById("post-images-section").hidden = true;
    document.getElementById("post-images-list").replaceChildren();
    document.getElementById("post-image-files").value = "";
    document.getElementById("post-message").textContent = "";
  }

  function closePostEditor() {
    clearPostEditor();
    document.getElementById("project-post-editor").hidden = true;
  }

  function openNewPostEditor() {
    clearPostEditor();
    document.getElementById("project-post-editor").hidden = false;
  }

  function renderPostList(rows) {
    const container = document.getElementById("project-post-list");
    const safeRows = Array.isArray(rows) ? rows : [];

    if (!safeRows.length) {
      container.innerHTML =
        '<div class="empty-inline">Inga blogginlägg ännu.</div>';
      return;
    }

    container.innerHTML = safeRows
      .map((post) => `
        <button
          type="button"
          class="project-post-row"
          data-post-id="${escapeHtml(post.id)}"
        >
          <span>
            <strong>${escapeHtml(post.title)}</strong>
            <small>${escapeHtml(formatPostDate(post.published_at) || "Inget datum")}</small>
          </span>
          <span class="publication-badge">
            ${post.publication_status === "published"
              ? "Publicerad"
              : post.publication_status === "archived"
                ? "Arkiverad"
                : "Utkast"}
          </span>
        </button>
      `)
      .join("");
  }

  async function loadProjectPosts(projectId) {
    const data = await api("PROJECT_POST_LIST", {
      project_id: projectId,
    });

    renderPostList(data.rows);
  }

  function renderPostImages(rows) {
    const container = document.getElementById("post-images-list");
    const safeRows = Array.isArray(rows) ? rows : [];

    if (!safeRows.length) {
      container.innerHTML =
        '<div class="empty-inline">Inga bilder uppladdade ännu.</div>';
      return;
    }

    container.innerHTML = safeRows
      .map((image) => `
        <article class="post-image-card" data-media-id="${escapeHtml(image.id)}">
          <img
            src="${escapeHtml(image.file_path)}"
            alt="${escapeHtml(image.alt_text || image.caption || "")}"
          >

          <label>
            Bildtext
            <input
              type="text"
              class="post-image-caption"
              value="${escapeHtml(image.caption || "")}"
              maxlength="500"
            >
          </label>

          <div class="form-actions">
            <button
              type="button"
              class="button small secondary save-image-caption"
              data-media-id="${escapeHtml(image.id)}"
            >
              Spara bildtext
            </button>
            <button
              type="button"
              class="button small danger delete-post-image"
              data-media-id="${escapeHtml(image.id)}"
            >
              Ta bort bild
            </button>
          </div>
        </article>
      `)
      .join("");
  }

  async function loadPostImages(postId) {
    const data = await api("PROJECT_POST_GET", { id: postId });
    renderPostImages(data.images || []);
  }

  async function openPost(postId) {
    const data = await api("PROJECT_POST_GET", { id: postId });
    const post = data.row;

    document.getElementById("post-id").value = post.id;
    document.getElementById("post-title").value = post.title || "";
    document.getElementById("post-published-at").value =
      formatPostDate(post.published_at);
    document.getElementById("post-content").value = post.content || "";
    document.getElementById("post-publication-status").value =
      post.publication_status || "draft";
    document.getElementById("delete-post-button").hidden = false;
    document.getElementById("post-images-section").hidden = false;
    document.getElementById("project-post-editor").hidden = false;
    document.getElementById("post-message").textContent = "";

    renderPostImages(data.images || []);
  }

  async function uploadPostImages(projectId, postId, files) {
    const body = new FormData();
    body.append("action", "PROJECT_POST_IMAGE_UPLOAD");
    body.append("project_id", String(projectId));
    body.append("post_id", String(postId));

    Array.from(files).forEach((file) => {
      body.append("files[]", file);
    });

    const response = await fetch(API_URL, {
      method: "POST",
      body,
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });

    const text = await response.text();
    let data = null;

    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(
        `Bild-API returnerade ogiltig JSON (${response.status}). ${text}`,
      );
    }

    if (!response.ok || data.success !== true) {
      throw new Error(
        data.message || `Bilduppladdningen misslyckades (${response.status})`,
      );
    }

    return data;
  }

  function clearEditor() {
    const form = getForm();
    form.reset();
    form.elements.id.value = "";
    form.elements.status.value = "ongoing";
    form.elements.publication_status.value = "published";

    state.selectedId = null;

    document.getElementById("editor-title").textContent = "Nytt projekt";
    document.getElementById("delete-project-button").hidden = true;
    document.getElementById("form-message").textContent = "";

    document.getElementById("cover-file").value = "";
    setCoverPreview(DEFAULT_IMAGE);

    document.getElementById("project-links-section").hidden = true;
    document.getElementById("project-links-list").replaceChildren();
    document.getElementById("project-link-title").value = "";
    document.getElementById("project-link-url").value = "";
    document.getElementById("project-link-type").value = "website";

    document.getElementById("project-blog-section").hidden = true;
    document.getElementById("project-post-list").replaceChildren();
    closePostEditor();
  }

  function fillEditor(project) {
    const form = getForm();

    state.selectedId = Number(project.id);

    form.elements.id.value = project.id ?? "";
    form.elements.title.value = project.title ?? "";
    form.elements.summary.value = project.summary ?? "";
    form.elements.technology.value = project.technology ?? "";
    form.elements.description.value = project.description ?? "";
    form.elements.todo.value = project.todo ?? "";
    form.elements.project_year.value = project.project_year ?? "";
    form.elements.status.value = project.status ?? "ongoing";
    form.elements.publication_status.value =
      project.publication_status ?? "draft";

    document.getElementById("editor-title").textContent =
      `Redigera: ${project.title || "projekt"}`;

    document.getElementById("delete-project-button").hidden = false;
    document.getElementById("form-message").textContent = "";

    document.getElementById("cover-file").value = "";
    setCoverPreview(project.cover_image || DEFAULT_IMAGE);

    document.getElementById("project-links-section").hidden = false;
    void loadProjectLinks(project.id);

    document.getElementById("project-blog-section").hidden = false;
    closePostEditor();
    void loadProjectPosts(project.id);

    openEditor();
  }

  async function openProject(id) {
    try {
      const data = await api("PROJECT_GET", { id });
      fillEditor(data.row);
    } catch (error) {
      alert(error.message);
    }
  }

  function textToHtml(value) {
    const text = String(value || "").trim();

    if (!text) {
      return "";
    }

    return escapeHtml(text).replace(/\r?\n/g, "<br>");
  }

  function setInfoSection(sectionId, contentId, value) {
    const section = document.getElementById(sectionId);
    const content = document.getElementById(contentId);
    const html = textToHtml(value);

    section.hidden = !html;
    content.innerHTML = html;
  }



  function showProjectDetailView(viewName) {
    const logView = document.getElementById("project-detail-log-view");
    const infoView = document.getElementById("project-detail-info-view");
    const infoButton = document.getElementById("project-detail-info-button");
    const body = document.getElementById("project-detail-body");

    const showInfo = viewName === "info";

    logView.hidden = showInfo;
    infoView.hidden = !showInfo;
    infoButton.setAttribute("aria-pressed", showInfo ? "true" : "false");
    infoButton.setAttribute(
      "aria-label",
      showInfo ? "Visa projektlogg" : "Visa projektinformation",
    );
    infoButton.title = showInfo ? "Projektlogg" : "Projektinformation";

    body.scrollTop = 0;
  }

  function openProjectDetailModal() {
    const backdrop = document.getElementById("project-detail-backdrop");
    backdrop.hidden = false;
    document.body.classList.add("project-detail-open");
    showProjectDetailView("log");
  }

  function closeProjectDetailModal() {
    const backdrop = document.getElementById("project-detail-backdrop");
    backdrop.hidden = true;
    document.body.classList.remove("project-detail-open");
  }

  function renderPublicLinks(rows) {
    const section = document.getElementById("project-detail-links-section");
    const container = document.getElementById("project-detail-links");
    const safeRows = Array.isArray(rows) ? rows : [];

    section.hidden = safeRows.length === 0;

    container.innerHTML = safeRows
      .map((link) => {
        const safeUrl = normalizeUrl(link.url);

        if (!safeUrl) {
          return "";
        }

        return `
          <a
            href="${escapeHtml(safeUrl)}"
            target="_blank"
            rel="noopener noreferrer"
            class="project-detail-link"
          >
            <span>${escapeHtml(linkTypeNames[link.link_type] || "Länk")}</span>
            <strong>${escapeHtml(link.title || link.url)}</strong>
          </a>
        `;
      })
      .join("");
  }

  function renderPublicPost(post, images) {
    const imageRows = Array.isArray(images) ? images : [];
    const date = formatPostDate(post.published_at);

    const imagesHtml = imageRows.length
      ? `
        <div class="project-detail-post-images project-detail-post-images--${Math.min(imageRows.length, 3)}">
          ${imageRows
            .map((image) => `
              <figure>
                <img
                  class="project-detail-post-image"
                  src="${escapeHtml(image.file_path)}"
                  alt="${escapeHtml(image.alt_text || image.caption || "")}"
                  data-lightbox-src="${escapeHtml(image.file_path)}"
                  data-lightbox-caption="${escapeHtml(image.caption || "")}"
                  loading="lazy"
                  tabindex="0"
                  role="button"
                  aria-label="Visa bilden i helskärm"
                >
                ${image.caption
                  ? `<figcaption>${escapeHtml(image.caption)}</figcaption>`
                  : ""}
              </figure>
            `)
            .join("")}
        </div>
      `
      : "";

    return `
      <article class="project-detail-post">
        <div class="project-detail-post-heading">
          ${date ? `<time>${escapeHtml(date)}</time>` : ""}
          <h4>${escapeHtml(post.title || "")}</h4>
        </div>

        ${post.content
          ? `<div class="project-detail-post-content">${textToHtml(post.content)}</div>`
          : ""}

        ${imagesHtml}
      </article>
    `;
  }

  function updatePublicPostSortButton() {
    const button =
      document.getElementById("project-detail-sort-button");
    const newestFirst = state.publicPostOrder === "newest";

    button.textContent = newestFirst
      ? "Nyaste först"
      : "Äldsta först";

    button.dataset.order = state.publicPostOrder;

    const label = newestFirst
      ? "Visa äldsta inläggen först"
      : "Visa nyaste inläggen först";

    button.setAttribute("aria-label", label);
    button.title = label;
  }

  async function loadPublicPosts(projectId) {
    const postsContainer = document.getElementById("project-detail-posts");
    postsContainer.innerHTML =
      '<div class="empty-inline">Laddar projektloggen…</div>';

    const data = await api("PROJECT_POST_LIST", {
      project_id: projectId,
    });

    const rows = Array.isArray(data.rows)
      ? [...data.rows]
      : [];

    if (state.publicPostOrder === "oldest") {
      rows.reverse();
    }

    if (!rows.length) {
      postsContainer.innerHTML =
        '<div class="empty-inline">Inga publicerade blogginlägg ännu.</div>';
      return;
    }

    const rendered = [];

    for (const post of rows) {
      const detail = await api("PROJECT_POST_GET", { id: post.id });
      rendered.push(renderPublicPost(detail.row, detail.images || []));
    }

    postsContainer.innerHTML = rendered.join("");
  }

  function openImageLightbox(imageSrc, imageAlt = "", caption = "") {
    const lightbox = document.getElementById("image-lightbox");
    const image = document.getElementById("image-lightbox-image");
    const captionElement =
      document.getElementById("image-lightbox-caption");

    image.src = imageSrc;
    image.alt = imageAlt || caption || "Förstorad bild";

    captionElement.textContent = caption;
    captionElement.hidden = !caption;

    lightbox.hidden = false;
    document.body.classList.add("image-lightbox-open");
  }

  function closeImageLightbox() {
    const lightbox = document.getElementById("image-lightbox");
    const image = document.getElementById("image-lightbox-image");
    const caption = document.getElementById("image-lightbox-caption");

    lightbox.hidden = true;
    image.src = "";
    image.alt = "";
    caption.textContent = "";
    caption.hidden = true;

    document.body.classList.remove("image-lightbox-open");
  }

  async function openProjectDetail(projectId) {
    const image = document.getElementById("project-detail-image");
    const posts = document.getElementById("project-detail-posts");

    state.currentPublicProjectId = projectId;
    state.publicPostOrder = "newest";
    updatePublicPostSortButton();

    openProjectDetailModal();

    posts.innerHTML = '<div class="empty-inline">Laddar projekt…</div>';

    try {
      const [projectData, linksData] = await Promise.all([
        api("PROJECT_GET", { id: projectId }),
        api("PROJECT_LINK_LIST", { project_id: projectId }),
      ]);

      const project = projectData.row;

      image.src = project.cover_image || DEFAULT_IMAGE;
      image.alt = project.title
        ? `Projektbild för ${project.title}`
        : "Projektbild";
      image.onerror = () => {
        image.onerror = null;
        image.src = DEFAULT_IMAGE;
      };

      document.getElementById("project-detail-title").textContent =
        project.title || "";

      document.getElementById("project-detail-summary").textContent =
        project.summary || "";

      document.getElementById("project-detail-status").textContent =
        statusNames[project.status] || project.status || "";

      const year = document.getElementById("project-detail-year");
      year.textContent = project.project_year || "";
      year.hidden = !project.project_year;

      setInfoSection(
        "project-detail-technology-section",
        "project-detail-technology",
        project.technology,
      );
  
      setInfoSection(
        "project-detail-description-section",
        "project-detail-description",
        project.description,
      );

      setInfoSection(
        "project-detail-todo-section",
        "project-detail-todo",
        project.todo,
      );

      renderPublicLinks(linksData.rows || []);
      await loadPublicPosts(projectId);
    } catch (error) {
      posts.innerHTML =
        `<div class="empty-inline">Kunde inte läsa projektet: ${escapeHtml(error.message)}</div>`;
    }
  }

  function renderCard(project) {
    const template = document.getElementById("project-card-template");
    const card = template.content.firstElementChild.cloneNode(true);

    const image = card.querySelector(".project-card-image");
    image.src = String(project.cover_image || DEFAULT_IMAGE);
    image.alt = project.title ? `Projektbild för ${project.title}` : "Projektbild";
    image.addEventListener("error", () => {
      if (!image.src.endsWith(DEFAULT_IMAGE)) {
        image.src = DEFAULT_IMAGE;
      }
    });

    card.querySelector("h3").textContent = project.title;
    card.querySelector(".project-summary").textContent =
      project.summary || "Ingen sammanfattning ännu.";

    card.querySelector(".status-badge").textContent =
      statusNames[project.status] || project.status;

    const isAdmin =
      window.ProjectsAuth?.isAuthenticated() === true;

    const publication = card.querySelector(".publication-badge");
    publication.textContent =
      project.publication_status === "published"
        ? "Publicerad"
        : project.publication_status === "archived"
          ? "Arkiverad"
          : "Utkast";
    publication.hidden = !isAdmin;

    const date = card.querySelector("time");
    const year = project.project_year ? ` · ${project.project_year}` : "";

    date.textContent = project.updated_at
      ? `Uppdaterad ${project.updated_at.slice(0, 10)}${year}`
      : year.replace(" · ", "");

    card.addEventListener("click", () => {
      if (isAdmin) {
        void openProject(project.id);
        return;
      }

      void openProjectDetail(project.id);
    });

    return card;
  }

  function renderGroup(container, rows, emptyText) {
    container.replaceChildren();

    if (!rows.length) {
      container.appendChild(createEmptyState(emptyText));
      return;
    }

    rows.forEach((project) => {
      container.appendChild(renderCard(project));
    });
  }

  async function loadProjects() {
    const ongoing = document.getElementById("ongoing-projects");
    const completed = document.getElementById("completed-projects");

    try {
      const data = await api("PROJECT_LIST");
      const rows = Array.isArray(data.rows) ? data.rows : [];
      state.rows = rows;

      const activeRows = rows.filter((row) =>
        ["planned", "ongoing", "paused"].includes(row.status),
      );

      const completedRows = rows.filter((row) =>
        ["completed", "archived"].includes(row.status),
      );

      renderGroup(ongoing, activeRows, "Inga pågående projekt ännu.");
      renderGroup(completed, completedRows, "Inga slutförda projekt ännu.");

      document.getElementById("ongoing-count").textContent = activeRows.length;
      document.getElementById("completed-count").textContent =
        completedRows.length;
    } catch (error) {
      ongoing.replaceChildren(
        createEmptyState(`Kunde inte läsa projekt: ${error.message}`),
      );
      completed.replaceChildren();
    }
  }

  async function saveProject(form) {
    const values = new FormData(form);
    const id = Number(values.get("id") || 0);

    const payload = {
      title: values.get("title"),
      summary: values.get("summary"),
      technology: values.get("technology"),
      description: values.get("description"),
      todo: values.get("todo"),
      project_year: values.get("project_year"),
      status: values.get("status"),
      publication_status: values.get("publication_status"),
    };

    if (id > 0) {
      payload.id = id;
      return api("PROJECT_UPDATE", payload);
    }

    return api("PROJECT_ADD", payload);
  }

  async function uploadCover(projectId, file) {
    const body = new FormData();
    body.append("action", "PROJECT_COVER_UPLOAD");
    body.append("id", String(projectId));
    body.append("file", file);

    const response = await fetch(API_URL, {
      method: "POST",
      body,
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });

    const text = await response.text();

    let data = null;

    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(
        `Bild-API returnerade ogiltig JSON (${response.status}). ${text}`,
      );
    }

    if (!response.ok || data.success !== true) {
      throw new Error(
        data.message || `Bilduppladdningen misslyckades (${response.status})`,
      );
    }

    return data;
  }

  function bindForm() {
    const form = getForm();
    const message = document.getElementById("form-message");
    const deleteButton = document.getElementById("delete-project-button");
    const newButton = document.getElementById("new-project-button");
    const closeEditorButton = document.getElementById("close-editor-button");
    const coverFile = document.getElementById("cover-file");
    const linksList = document.getElementById("project-links-list");
    const addLinkButton = document.getElementById("add-project-link-button");
    const linkType = document.getElementById("project-link-type");
    const linkTitle = document.getElementById("project-link-title");
    const linkUrl = document.getElementById("project-link-url");
    const postList = document.getElementById("project-post-list");
    const postEditor = document.getElementById("project-post-editor");
    const newPostButton = document.getElementById("new-post-button");
    const savePostButton = document.getElementById("save-post-button");
    const deletePostButton = document.getElementById("delete-post-button");
    const closePostButton = document.getElementById("close-post-button");
    const postImagesList = document.getElementById("post-images-list");
    const postImageFiles = document.getElementById("post-image-files");
    const uploadPostImagesButton =
      document.getElementById("upload-post-images-button");
    const postMessage = document.getElementById("post-message");
    const detailBackdrop = document.getElementById("project-detail-backdrop");
    const detailClose = document.getElementById("project-detail-close");
    const detailSortButton =
      document.getElementById("project-detail-sort-button");
    const detailInfoButton =
      document.getElementById("project-detail-info-button");
    const detailBackButton =
      document.getElementById("project-detail-back-button");
    const detailPosts =
      document.getElementById("project-detail-posts");
    const imageLightbox =
      document.getElementById("image-lightbox");
    const imageLightboxClose =
      document.getElementById("image-lightbox-close");

    detailPosts.addEventListener("click", (event) => {
      const image = event.target.closest(".project-detail-post-image");

      if (!image) {
        return;
      }

      openImageLightbox(
        image.dataset.lightboxSrc || image.src,
        image.alt || "",
        image.dataset.lightboxCaption || "",
      );
    });

    detailPosts.addEventListener("keydown", (event) => {
      if (
        event.key !== "Enter"
        && event.key !== " "
      ) {
        return;
      }

      const image = event.target.closest(".project-detail-post-image");

      if (!image) {
        return;
      }

      event.preventDefault();

      openImageLightbox(
        image.dataset.lightboxSrc || image.src,
        image.alt || "",
        image.dataset.lightboxCaption || "",
      );
    });

    imageLightboxClose.addEventListener("click", closeImageLightbox);

    imageLightbox.addEventListener("click", (event) => {
      if (event.target === imageLightbox) {
        closeImageLightbox();
      }
    });

    detailClose.addEventListener("click", closeProjectDetailModal);

    detailSortButton.addEventListener("click", async () => {
      state.publicPostOrder =
        state.publicPostOrder === "newest"
          ? "oldest"
          : "newest";

      updatePublicPostSortButton();

      if (state.currentPublicProjectId) {
        await loadPublicPosts(state.currentPublicProjectId);
      }
    });

    detailInfoButton.addEventListener("click", () => {
      const isInfo =
        detailInfoButton.getAttribute("aria-pressed") === "true";
      showProjectDetailView(isInfo ? "log" : "info");
    });

    detailBackButton.addEventListener("click", () => {
      showProjectDetailView("log");
    });

    detailBackdrop.addEventListener("click", (event) => {
      if (event.target === detailBackdrop) {
        closeProjectDetailModal();
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") {
        return;
      }

      if (!imageLightbox.hidden) {
        closeImageLightbox();
        return;
      }

      if (!detailBackdrop.hidden) {
        closeProjectDetailModal();
      }
    });

    newButton.addEventListener("click", () => {
      clearEditor();
      openEditor();
    });

    closeEditorButton.addEventListener("click", () => {
      clearEditor();
      closeEditor();
    });

    coverFile.addEventListener("change", () => {
      const file = coverFile.files?.[0];

      if (!file) {
        return;
      }

      const objectUrl = URL.createObjectURL(file);
      setCoverPreview(objectUrl);

      const preview = document.getElementById("cover-preview");
      preview.addEventListener(
        "load",
        () => URL.revokeObjectURL(objectUrl),
        { once: true },
      );
    });


    addLinkButton.addEventListener("click", async () => {
      const projectId = Number(form.elements.id.value || 0);
      const title = linkTitle.value.trim();
      const url = linkUrl.value.trim();
      const type = linkType.value;

      if (projectId <= 0) {
        message.textContent = "Spara projektet innan du lägger till länkar.";
        return;
      }

      if (!title) {
        message.textContent = "Ange en rubrik för länken.";
        linkTitle.focus();
        return;
      }

      if (!normalizeUrl(url)) {
        message.textContent = "Ange en giltig http- eller https-adress.";
        linkUrl.focus();
        return;
      }

      addLinkButton.disabled = true;
      message.textContent = "Sparar länk…";

      try {
        await api("PROJECT_LINK_ADD", {
          project_id: projectId,
          title,
          url,
          link_type: type,
        });

        linkTitle.value = "";
        linkUrl.value = "";
        linkType.value = "website";

        await loadProjectLinks(projectId);
        message.textContent = "Länken lades till.";
      } catch (error) {
        message.textContent = error.message;
      } finally {
        addLinkButton.disabled = false;
      }
    });

    linksList.addEventListener("click", async (event) => {
      const button = event.target.closest(".delete-project-link");

      if (!button) {
        return;
      }

      const linkId = Number(button.dataset.linkId || 0);
      const projectId = Number(form.elements.id.value || 0);

      if (linkId <= 0 || projectId <= 0) {
        return;
      }

      if (!confirm("Ta bort länken?")) {
        return;
      }

      button.disabled = true;
      message.textContent = "Tar bort länk…";

      try {
        await api("PROJECT_LINK_DELETE", { id: linkId });
        await loadProjectLinks(projectId);
        message.textContent = "Länken togs bort.";
      } catch (error) {
        message.textContent = error.message;
        button.disabled = false;
      }
    });

    newPostButton.addEventListener("click", openNewPostEditor);
    closePostButton.addEventListener("click", closePostEditor);

    postList.addEventListener("click", (event) => {
      const row = event.target.closest("[data-post-id]");
      if (!row) return;

      const postId = Number(row.dataset.postId || 0);
      if (postId > 0) {
        void openPost(postId);
      }
    });

    savePostButton.addEventListener("click", async () => {
      const projectId = Number(form.elements.id.value || 0);
      const postId = Number(document.getElementById("post-id").value || 0);
      const title = document.getElementById("post-title").value.trim();
      const publishedAt =
        document.getElementById("post-published-at").value;
      const content = document.getElementById("post-content").value.trim();
      const publicationStatus =
        document.getElementById("post-publication-status").value;

      if (projectId <= 0) {
        postMessage.textContent = "Projekt-id saknas.";
        return;
      }

      if (!title) {
        postMessage.textContent = "Ange en rubrik.";
        return;
      }

      savePostButton.disabled = true;
      postMessage.textContent = "Sparar inlägg…";

      try {
        const action = postId > 0
          ? "PROJECT_POST_UPDATE"
          : "PROJECT_POST_ADD";

        const result = await api(action, {
          id: postId || "",
          project_id: projectId,
          title,
          content,
          published_at: publishedAt,
          publication_status: publicationStatus,
        });

        const savedId = Number(result.id || postId || 0);

        await loadProjectPosts(projectId);
        await openPost(savedId);
        postMessage.textContent = "Inlägget sparades.";
      } catch (error) {
        postMessage.textContent = error.message;
      } finally {
        savePostButton.disabled = false;
      }
    });

    deletePostButton.addEventListener("click", async () => {
      const projectId = Number(form.elements.id.value || 0);
      const postId = Number(document.getElementById("post-id").value || 0);

      if (postId <= 0 || !confirm("Ta bort blogginlägget?")) {
        return;
      }

      try {
        await api("PROJECT_POST_DELETE", { id: postId });
        closePostEditor();
        await loadProjectPosts(projectId);
      } catch (error) {
        postMessage.textContent = error.message;
      }
    });

    uploadPostImagesButton.addEventListener("click", async () => {
      const projectId = Number(form.elements.id.value || 0);
      const postId = Number(document.getElementById("post-id").value || 0);
      const files = postImageFiles.files;

      if (postId <= 0) {
        postMessage.textContent = "Spara inlägget innan bilder laddas upp.";
        return;
      }

      if (!files || files.length === 0) {
        postMessage.textContent = "Välj minst en bild.";
        return;
      }

      uploadPostImagesButton.disabled = true;
      postMessage.textContent = "Laddar upp bilder…";

      try {
        await uploadPostImages(projectId, postId, files);
        postImageFiles.value = "";
        await loadPostImages(postId);
        postMessage.textContent = "Bilderna laddades upp.";
      } catch (error) {
        postMessage.textContent = error.message;
      } finally {
        uploadPostImagesButton.disabled = false;
      }
    });

    postImagesList.addEventListener("click", async (event) => {
      const saveButton = event.target.closest(".save-image-caption");
      const deleteButton = event.target.closest(".delete-post-image");
      const postId = Number(document.getElementById("post-id").value || 0);

      if (saveButton) {
        const card = saveButton.closest(".post-image-card");
        const mediaId = Number(saveButton.dataset.mediaId || 0);
        const caption = card.querySelector(".post-image-caption").value.trim();

        try {
          await api("PROJECT_POST_IMAGE_CAPTION_UPDATE", {
            id: mediaId,
            caption,
          });
          postMessage.textContent = "Bildtexten sparades.";
        } catch (error) {
          postMessage.textContent = error.message;
        }
      }

      if (deleteButton) {
        const mediaId = Number(deleteButton.dataset.mediaId || 0);

        if (!confirm("Ta bort bilden?")) {
          return;
        }

        try {
          await api("PROJECT_POST_IMAGE_DELETE", { id: mediaId });
          await loadPostImages(postId);
          postMessage.textContent = "Bilden togs bort.";
        } catch (error) {
          postMessage.textContent = error.message;
        }
      }
    });

    form.addEventListener("submit", async (event) => {
      event.preventDefault();

      const pendingCover = coverFile.files?.[0] || null;
      message.textContent = pendingCover
        ? "Sparar projekt och bild…"
        : "Sparar projekt…";

      try {
        const result = await saveProject(form);
        const id = Number(result.id || form.elements.id.value || 0);

        if (id <= 0) {
          throw new Error("Projektet sparades men något projekt-id returnerades inte.");
        }

        if (pendingCover) {
          await uploadCover(id, pendingCover);
        }

        await loadProjects();
        await openProject(id);

        document.getElementById("form-message").textContent =
          pendingCover
            ? "Projektet och projektbilden sparades."
            : "Projektet sparades.";
      } catch (error) {
        message.textContent = error.message;
      }
    });

    deleteButton.addEventListener("click", async () => {
      const id = Number(form.elements.id.value || 0);
      if (id <= 0) return;

      if (!confirm("Ta bort projektet?")) return;

      try {
        await api("PROJECT_DELETE", { id });
        clearEditor();
        closeEditor();
        await loadProjects();
      } catch (error) {
        message.textContent = error.message;
      }
    });
  }

  document.addEventListener("projects:auth-ready", loadProjects);
  document.addEventListener("projects:auth-changed", () => {
    clearEditor();
    closeEditor();
    void loadProjects();
  });

  if (document.readyState === "loading") {
    document.addEventListener(
      "DOMContentLoaded",
      () => {
        bindForm();
        clearEditor();
        closeEditor();
      },
      { once: true },
    );
  } else {
    bindForm();
    clearEditor();
    closeEditor();
  }
})();
