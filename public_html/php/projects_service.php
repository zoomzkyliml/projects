<?php

declare(strict_types=1);

$SERVICE_VERSION = 'projects_service.php v0.7.0';
header('X-Service-Version: ' . $SERVICE_VERSION);

error_reporting(E_ALL);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', __DIR__ . '/projects_error.log');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$ALLOWED_ORIGINS = [
    'http://projects.local',
    'https://projects.liml.se',
    'https://liml.se',
];

if (in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

ob_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/../../secure/projects_db_config.php';
require_once __DIR__ . '/projects_auth_common.php';

function projects_safe_json(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function projects_ok(array $data = []): void
{
    projects_safe_json(
        array_merge(['success' => true], $data),
        200
    );
}

function projects_fail(string $message, int $status = 400): void
{
    projects_safe_json([
        'success' => false,
        'message' => $message,
    ], $status);
}

try {
    $mysqli = new mysqli(
        $servername,
        $username,
        $password,
        $dbname
    );
} catch (mysqli_sql_exception $e) {
    projects_safe_json([
        'success' => false,
        'error' => 'db_connect',
        'message' => $e->getMessage(),
    ], 500);
}

$mysqli->set_charset('utf8mb4');
$mysqli->query(
    "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$__is_admin = projects_auth_is_logged_in();

header(
    'X-Admin-Guard: '
    . ($__is_admin ? 'admin' : 'guest')
);

$WRITE_ACTIONS = [
    'PROJECT_ADD',
    'PROJECT_UPDATE',
    'PROJECT_COVER_UPLOAD',
    'PROJECT_LINK_ADD',
    'PROJECT_LINK_DELETE',
    'PROJECT_POST_ADD',
    'PROJECT_POST_UPDATE',
    'PROJECT_POST_DELETE',
    'PROJECT_POST_IMAGE_UPLOAD',
    'PROJECT_POST_IMAGE_CAPTION_UPDATE',
    'PROJECT_POST_IMAGE_DELETE',
    'PROJECT_UPDATE_STATUS',
    'PROJECT_DELETE',
];

if (
    in_array($action, $WRITE_ACTIONS, true)
    && !$__is_admin
) {
    projects_safe_json([
        'success' => false,
        'error' => 'forbidden',
        'reason' => 'admin_required',
        'message' => 'Saknar behörighet.',
    ], 403);
}

function projects_validate_status(string $status): void
{
    $allowed = [
        'planned',
        'ongoing',
        'paused',
        'completed',
        'archived',
    ];

    if (!in_array($status, $allowed, true)) {
        projects_fail('Ogiltig projektstatus.');
    }
}

function projects_validate_publication_status(
    string $status
): void {
    $allowed = [
        'draft',
        'published',
        'archived',
    ];

    if (!in_array($status, $allowed, true)) {
        projects_fail('Ogiltig publiceringsstatus.');
    }
}

function projects_nullable_text($value): ?string
{
    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function projects_nullable_year($value): ?int
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $year = (int)$value;

    if ($year < 1900 || $year > 2200) {
        projects_fail('Årtalet måste vara mellan 1900 och 2200.');
    }

    return $year;
}

function projects_slugify(string $title): string
{
    $slug = mb_strtolower($title, 'UTF-8');

    $slug = str_replace(
        ['å', 'ä', 'ö'],
        ['a', 'a', 'o'],
        $slug
    );

    $slug = preg_replace(
        '/[^a-z0-9]+/u',
        '-',
        $slug
    );

    $slug = trim((string)$slug, '-');

    return $slug !== ''
        ? $slug
        : 'projekt-' . time();
}

function projects_unique_slug(
    mysqli $mysqli,
    string $title,
    ?int $excludeId = null
): string {
    $baseSlug = projects_slugify($title);
    $slug = $baseSlug;
    $suffix = 2;

    while (true) {
        if ($excludeId !== null) {
            $stmt = $mysqli->prepare(
                "SELECT 1
                 FROM projects
                 WHERE slug = ?
                   AND id <> ?
                 LIMIT 1"
            );

            if (!$stmt) {
                projects_fail(
                    'Slugkontrollen kunde inte förberedas.',
                    500
                );
            }

            $stmt->bind_param('si', $slug, $excludeId);
        } else {
            $stmt = $mysqli->prepare(
                "SELECT 1
                 FROM projects
                 WHERE slug = ?
                 LIMIT 1"
            );

            if (!$stmt) {
                projects_fail(
                    'Slugkontrollen kunde inte förberedas.',
                    500
                );
            }

            $stmt->bind_param('s', $slug);
        }

        $stmt->execute();
        $stmt->store_result();

        $exists = $stmt->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    }
}

if ($action === 'AM_I_ADMIN') {
    projects_ok([
        'is_admin' => $__is_admin,
    ]);
}

if ($action === 'PROJECT_LIST') {
    $where = $__is_admin
        ? ''
        : "WHERE p.publication_status = 'published'";

    $sql = "
        SELECT
            p.id,
            p.title,
            p.slug,
            p.summary,
            p.technology,
            p.description,
            p.todo,
            p.project_year,
            p.status,
            p.publication_status,
            p.cover_image,
            p.started_at,
            p.completed_at,
            p.created_at,
            p.updated_at,
            c.name AS category_name
        FROM projects p
        LEFT JOIN project_categories c
            ON c.id = p.category_id
        $where
        ORDER BY
            FIELD(
                p.status,
                'ongoing',
                'planned',
                'paused',
                'completed',
                'archived'
            ),
            p.sort_order ASC,
            p.updated_at DESC,
            p.id DESC
    ";

    $result = $mysqli->query($sql);

    if (!$result) {
        projects_fail(
            'Projektlistan kunde inte läsas: '
            . $mysqli->error,
            500
        );
    }

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['project_year'] = $row['project_year'] === null
            ? null
            : (int)$row['project_year'];

        $rows[] = $row;
    }

    projects_ok([
        'rows' => $rows,
        'count' => count($rows),
        'is_admin' => $__is_admin,
    ]);
}

if ($action === 'PROJECT_GET') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

    if ($id <= 0) {
        projects_fail('Projekt-id saknas.');
    }

    $sql = "
        SELECT
            p.*,
            c.name AS category_name
        FROM projects p
        LEFT JOIN project_categories c
            ON c.id = p.category_id
        WHERE p.id = ?
    ";

    if (!$__is_admin) {
        $sql .= " AND p.publication_status = 'published'";
    }

    $sql .= " LIMIT 1";

    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        projects_fail(
            'Projektet kunde inte förberedas.',
            500
        );
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        projects_fail(
            'Projektet kunde inte läsas.',
            500
        );
    }

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        projects_fail('Projektet hittades inte.', 404);
    }

    $row['id'] = (int)$row['id'];
    $row['project_year'] = $row['project_year'] === null
        ? null
        : (int)$row['project_year'];

    projects_ok([
        'row' => $row,
        'is_admin' => $__is_admin,
    ]);
}

if ($action === 'PROJECT_ADD') {
    $title = trim((string)($_POST['title'] ?? ''));

    if ($title === '') {
        projects_fail('Projektnamn saknas.');
    }

    $summary = projects_nullable_text($_POST['summary'] ?? '');
    $technology = projects_nullable_text($_POST['technology'] ?? '');
    $description = projects_nullable_text(
        $_POST['description'] ?? ''
    );
    $todo = projects_nullable_text(
        $_POST['todo'] ?? ''
    );
    $projectYear = projects_nullable_year(
        $_POST['project_year'] ?? ''
    );

    $status = trim(
        (string)($_POST['status'] ?? 'ongoing')
    );
    $publicationStatus = trim(
        (string)(
            $_POST['publication_status']
            ?? 'draft'
        )
    );

    projects_validate_status($status);
    projects_validate_publication_status(
        $publicationStatus
    );

    $slug = projects_unique_slug($mysqli, $title);

    $startedAt = $status === 'completed'
        ? null
        : date('Y-m-d');

    $completedAt = $status === 'completed'
        ? date('Y-m-d')
        : null;

    $stmt = $mysqli->prepare("
        INSERT INTO projects (
            title,
            slug,
            summary,
            technology,
            description,
            todo,
            project_year,
            status,
            publication_status,
            started_at,
            completed_at
        )
        VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");

    if (!$stmt) {
        projects_fail(
            'Projektet kunde inte förberedas: '
            . $mysqli->error,
            500
        );
    }

    $stmt->bind_param(
        'ssssssissss',
        $title,
        $slug,
        $summary,
        $technology,
        $description,
        $todo,
        $projectYear,
        $status,
        $publicationStatus,
        $startedAt,
        $completedAt
    );

    if (!$stmt->execute()) {
        projects_fail(
            'Projektet kunde inte sparas: '
            . $stmt->error,
            500
        );
    }

    projects_ok([
        'id' => (int)$stmt->insert_id,
        'slug' => $slug,
    ]);
}

if ($action === 'PROJECT_UPDATE') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));

    if ($id <= 0) {
        projects_fail('Projekt-id saknas.');
    }

    if ($title === '') {
        projects_fail('Projektnamn saknas.');
    }

    $summary = projects_nullable_text($_POST['summary'] ?? '');
    $technology = projects_nullable_text($_POST['technology'] ?? '');
    $description = projects_nullable_text(
        $_POST['description'] ?? ''
    );
    $todo = projects_nullable_text(
        $_POST['todo'] ?? ''
    );
    $projectYear = projects_nullable_year(
        $_POST['project_year'] ?? ''
    );

    $status = trim(
        (string)($_POST['status'] ?? 'ongoing')
    );
    $publicationStatus = trim(
        (string)(
            $_POST['publication_status']
            ?? 'draft'
        )
    );

    projects_validate_status($status);
    projects_validate_publication_status(
        $publicationStatus
    );

    $slug = projects_unique_slug(
        $mysqli,
        $title,
        $id
    );

    $completedAt = $status === 'completed'
        ? date('Y-m-d')
        : null;

    $stmt = $mysqli->prepare("
        UPDATE projects
        SET
            title = ?,
            slug = ?,
            summary = ?,
            technology = ?,
            description = ?,
            todo = ?,
            project_year = ?,
            status = ?,
            publication_status = ?,
            completed_at = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    if (!$stmt) {
        projects_fail(
            'Projektet kunde inte förberedas: '
            . $mysqli->error,
            500
        );
    }

    $stmt->bind_param(
        'ssssssisssi',
        $title,
        $slug,
        $summary,
        $technology,
        $description,
        $todo,
        $projectYear,
        $status,
        $publicationStatus,
        $completedAt,
        $id
    );

    if (!$stmt->execute()) {
        projects_fail(
            'Projektet kunde inte uppdateras: '
            . $stmt->error,
            500
        );
    }

    projects_ok([
        'id' => $id,
        'slug' => $slug,
        'affected' => $stmt->affected_rows,
    ]);
}



if ($action === 'PROJECT_LINK_LIST') {
    $projectId = (int)(
        $_POST['project_id']
        ?? $_GET['project_id']
        ?? 0
    );

    if ($projectId <= 0) {
        projects_fail('Projekt-id saknas.');
    }

    if (!$__is_admin) {
        $stmt = $mysqli->prepare(
            "SELECT 1
             FROM projects
             WHERE id = ?
               AND publication_status = 'published'
             LIMIT 1"
        );

        if (!$stmt) {
            projects_fail(
                'Projektet kunde inte kontrolleras.',
                500
            );
        }

        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $stmt->store_result();

        $projectVisible = $stmt->num_rows === 1;
        $stmt->close();

        if (!$projectVisible) {
            projects_fail('Projektet hittades inte.', 404);
        }
    }

    $stmt = $mysqli->prepare("
        SELECT
            id,
            project_id,
            title,
            url,
            link_type,
            sort_order,
            created_at
        FROM project_links
        WHERE project_id = ?
        ORDER BY
            sort_order ASC,
            id ASC
    ");

    if (!$stmt) {
        projects_fail(
            'Länkarna kunde inte förberedas: '
            . $mysqli->error,
            500
        );
    }

    $stmt->bind_param('i', $projectId);

    if (!$stmt->execute()) {
        projects_fail(
            'Länkarna kunde inte läsas: '
            . $stmt->error,
            500
        );
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['project_id'] = (int)$row['project_id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $rows[] = $row;
    }

    $stmt->close();

    projects_ok([
        'rows' => $rows,
        'count' => count($rows),
    ]);
}

if ($action === 'PROJECT_LINK_ADD') {
    $projectId = (int)($_POST['project_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    $linkType = trim(
        (string)($_POST['link_type'] ?? 'other')
    );

    if ($projectId <= 0) {
        projects_fail('Projekt-id saknas.');
    }

    if ($title === '') {
        projects_fail('Länkrubrik saknas.');
    }

    if (
        filter_var($url, FILTER_VALIDATE_URL) === false
        || !preg_match('#^https?://#i', $url)
    ) {
        projects_fail(
            'URL måste vara en giltig http- eller https-adress.'
        );
    }

    $allowedTypes = [
        'blog',
        'youtube',
        'github',
        'website',
        'documentation',
        'download',
        'other',
    ];

    if (!in_array($linkType, $allowedTypes, true)) {
        projects_fail('Ogiltig länktyp.');
    }

    $stmt = $mysqli->prepare(
        "SELECT 1
         FROM projects
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        projects_fail(
            'Projektet kunde inte kontrolleras.',
            500
        );
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $stmt->store_result();

    $projectExists = $stmt->num_rows === 1;
    $stmt->close();

    if (!$projectExists) {
        projects_fail('Projektet hittades inte.', 404);
    }

    $stmt = $mysqli->prepare("
        SELECT COALESCE(MAX(sort_order), 0) + 10
        FROM project_links
        WHERE project_id = ?
    ");

    if (!$stmt) {
        projects_fail(
            'Länkordningen kunde inte förberedas.',
            500
        );
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $stmt->bind_result($sortOrder);
    $stmt->fetch();
    $stmt->close();

    $sortOrder = (int)$sortOrder;

    $stmt = $mysqli->prepare("
        INSERT INTO project_links (
            project_id,
            title,
            url,
            link_type,
            sort_order
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        projects_fail(
            'Länken kunde inte förberedas: '
            . $mysqli->error,
            500
        );
    }

    $stmt->bind_param(
        'isssi',
        $projectId,
        $title,
        $url,
        $linkType,
        $sortOrder
    );

    if (!$stmt->execute()) {
        projects_fail(
            'Länken kunde inte sparas: '
            . $stmt->error,
            500
        );
    }

    projects_ok([
        'id' => (int)$stmt->insert_id,
        'project_id' => $projectId,
    ]);
}

if ($action === 'PROJECT_LINK_DELETE') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        projects_fail('Länk-id saknas.');
    }

    $stmt = $mysqli->prepare(
        "DELETE FROM project_links
         WHERE id = ?"
    );

    if (!$stmt) {
        projects_fail(
            'Länken kunde inte förberedas för borttagning.',
            500
        );
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        projects_fail(
            'Länken kunde inte tas bort: '
            . $stmt->error,
            500
        );
    }

    projects_ok([
        'affected' => $stmt->affected_rows,
    ]);
}


function projects_post_slug(
    mysqli $mysqli,
    int $projectId,
    string $title,
    ?int $excludeId = null
): string {
    $base = projects_slugify($title);
    $slug = $base;
    $suffix = 2;

    while (true) {
        if ($excludeId !== null) {
            $stmt = $mysqli->prepare(
                "SELECT 1
                 FROM project_posts
                 WHERE project_id = ?
                   AND slug = ?
                   AND id <> ?
                 LIMIT 1"
            );

            if (!$stmt) {
                projects_fail('Slugkontrollen misslyckades.', 500);
            }

            $stmt->bind_param('isi', $projectId, $slug, $excludeId);
        } else {
            $stmt = $mysqli->prepare(
                "SELECT 1
                 FROM project_posts
                 WHERE project_id = ?
                   AND slug = ?
                 LIMIT 1"
            );

            if (!$stmt) {
                projects_fail('Slugkontrollen misslyckades.', 500);
            }

            $stmt->bind_param('is', $projectId, $slug);
        }

        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            return $slug;
        }

        $slug = $base . '-' . $suffix;
        $suffix++;
    }
}

if ($action === 'PROJECT_POST_LIST') {
    $projectId = (int)($_POST['project_id'] ?? $_GET['project_id'] ?? 0);

    if ($projectId <= 0) {
        projects_fail('Projekt-id saknas.');
    }

    $where = "WHERE pp.project_id = ?";

    if (!$__is_admin) {
        $where .= " AND pp.publication_status = 'published'";
    }

    $stmt = $mysqli->prepare("
        SELECT
            pp.id,
            pp.project_id,
            pp.title,
            pp.slug,
            pp.post_type,
            pp.publication_status,
            pp.published_at,
            pp.created_at,
            pp.updated_at,
            (
                SELECT COUNT(*)
                FROM project_media pm
                WHERE pm.post_id = pp.id
                  AND pm.media_type = 'image'
            ) AS image_count
        FROM project_posts pp
        $where
        ORDER BY
            COALESCE(pp.published_at, pp.created_at) DESC,
            pp.id DESC
    ");

    if (!$stmt) {
        projects_fail('Inläggen kunde inte förberedas.', 500);
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['project_id'] = (int)$row['project_id'];
        $row['image_count'] = (int)$row['image_count'];
        $rows[] = $row;
    }

    $stmt->close();

    projects_ok([
        'rows' => $rows,
        'count' => count($rows),
    ]);
}

if ($action === 'PROJECT_POST_GET') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

    if ($id <= 0) {
        projects_fail('Inläggs-id saknas.');
    }

    $sql = "
        SELECT pp.*
        FROM project_posts pp
        WHERE pp.id = ?
    ";

    if (!$__is_admin) {
        $sql .= " AND pp.publication_status = 'published'";
    }

    $sql .= " LIMIT 1";

    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        projects_fail('Inlägget kunde inte förberedas.', 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        projects_fail('Inlägget hittades inte.', 404);
    }

    $row['id'] = (int)$row['id'];
    $row['project_id'] = (int)$row['project_id'];

    $stmt = $mysqli->prepare("
        SELECT
            id,
            project_id,
            post_id,
            file_path,
            media_type,
            mime_type,
            caption,
            alt_text,
            sort_order,
            created_at
        FROM project_media
        WHERE post_id = ?
          AND media_type = 'image'
        ORDER BY sort_order ASC, id ASC
    ");

    if (!$stmt) {
        projects_fail('Bilderna kunde inte förberedas.', 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $images = [];

    while ($image = $result->fetch_assoc()) {
        $image['id'] = (int)$image['id'];
        $image['project_id'] = (int)$image['project_id'];
        $image['post_id'] = (int)$image['post_id'];
        $image['sort_order'] = (int)$image['sort_order'];
        $images[] = $image;
    }

    $stmt->close();

    projects_ok([
        'row' => $row,
        'images' => $images,
    ]);
}

if ($action === 'PROJECT_POST_ADD') {
    $projectId = (int)($_POST['project_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $content = projects_nullable_text($_POST['content'] ?? '');
    $publishedAt = projects_nullable_text($_POST['published_at'] ?? '');
    $publicationStatus = trim(
        (string)($_POST['publication_status'] ?? 'draft')
    );

    if ($projectId <= 0) {
        projects_fail('Projekt-id saknas.');
    }

    if ($title === '') {
        projects_fail('Rubrik saknas.');
    }

    projects_validate_publication_status($publicationStatus);

    if ($publishedAt !== null) {
        $publishedAt .= ' 12:00:00';
    }

    $slug = projects_post_slug($mysqli, $projectId, $title);

    $stmt = $mysqli->prepare("
        INSERT INTO project_posts (
            project_id,
            title,
            slug,
            content,
            post_type,
            publication_status,
            published_at
        )
        VALUES (?, ?, ?, ?, 'update', ?, ?)
    ");

    if (!$stmt) {
        projects_fail('Inlägget kunde inte förberedas.', 500);
    }

    $stmt->bind_param(
        'isssss',
        $projectId,
        $title,
        $slug,
        $content,
        $publicationStatus,
        $publishedAt
    );

    if (!$stmt->execute()) {
        projects_fail(
            'Inlägget kunde inte sparas: ' . $stmt->error,
            500
        );
    }

    projects_ok([
        'id' => (int)$stmt->insert_id,
        'project_id' => $projectId,
    ]);
}

if ($action === 'PROJECT_POST_UPDATE') {
    $id = (int)($_POST['id'] ?? 0);
    $projectId = (int)($_POST['project_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $content = projects_nullable_text($_POST['content'] ?? '');
    $publishedAt = projects_nullable_text($_POST['published_at'] ?? '');
    $publicationStatus = trim(
        (string)($_POST['publication_status'] ?? 'draft')
    );

    if ($id <= 0 || $projectId <= 0) {
        projects_fail('Inläggs-id eller projekt-id saknas.');
    }

    if ($title === '') {
        projects_fail('Rubrik saknas.');
    }

    projects_validate_publication_status($publicationStatus);

    if ($publishedAt !== null) {
        $publishedAt .= ' 12:00:00';
    }

    $slug = projects_post_slug($mysqli, $projectId, $title, $id);

    $stmt = $mysqli->prepare("
        UPDATE project_posts
        SET
            title = ?,
            slug = ?,
            content = ?,
            publication_status = ?,
            published_at = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND project_id = ?
    ");

    if (!$stmt) {
        projects_fail('Inlägget kunde inte förberedas.', 500);
    }

    $stmt->bind_param(
        'sssssii',
        $title,
        $slug,
        $content,
        $publicationStatus,
        $publishedAt,
        $id,
        $projectId
    );

    if (!$stmt->execute()) {
        projects_fail(
            'Inlägget kunde inte uppdateras: ' . $stmt->error,
            500
        );
    }

    projects_ok([
        'id' => $id,
        'project_id' => $projectId,
        'affected' => $stmt->affected_rows,
    ]);
}

if ($action === 'PROJECT_POST_DELETE') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        projects_fail('Inläggs-id saknas.');
    }

    $stmt = $mysqli->prepare(
        "SELECT project_id, file_path
         FROM project_media
         WHERE post_id = ?"
    );

    if (!$stmt) {
        projects_fail('Inläggets bilder kunde inte läsas.', 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $files = [];

    while ($row = $result->fetch_assoc()) {
        $files[] = (string)$row['file_path'];
    }

    $stmt->close();

    $stmt = $mysqli->prepare(
        "DELETE FROM project_posts WHERE id = ?"
    );

    if (!$stmt) {
        projects_fail('Inlägget kunde inte förberedas.', 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    foreach ($files as $publicPath) {
        if (preg_match('#^/uploads/projects/\d+/posts/\d+/[^/]+$#', $publicPath)) {
            $diskPath = dirname(__DIR__) . '/Projects' . $publicPath;
            if (is_file($diskPath)) {
                @unlink($diskPath);
            }
        }
    }

    projects_ok(['affected' => $affected]);
}

if ($action === 'PROJECT_POST_IMAGE_UPLOAD') {
    $projectId = (int)($_POST['project_id'] ?? 0);
    $postId = (int)($_POST['post_id'] ?? 0);

    if ($projectId <= 0 || $postId <= 0) {
        projects_fail('Projekt-id eller inläggs-id saknas.');
    }

    if (!isset($_FILES['files'])) {
        projects_fail('Inga bilder valdes.');
    }

    $stmt = $mysqli->prepare(
        "SELECT 1
         FROM project_posts
         WHERE id = ?
           AND project_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        projects_fail('Inlägget kunde inte kontrolleras.', 500);
    }

    $stmt->bind_param('ii', $postId, $projectId);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows === 1;
    $stmt->close();

    if (!$exists) {
        projects_fail('Inlägget hittades inte.', 404);
    }

    $files = $_FILES['files'];
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $targetDirectory =
        dirname(__DIR__)
        . '/projects/uploads/projects/'
        . $projectId
        . '/posts/'
        . $postId;

    if (
        !is_dir($targetDirectory)
        && !mkdir($targetDirectory, 0775, true)
        && !is_dir($targetDirectory)
    ) {
        projects_fail('Kunde inte skapa bildkatalogen.', 500);
    }

    $uploaded = [];

    foreach ($tmpNames as $index => $tmpPath) {
        if ((int)$errors[$index] !== UPLOAD_ERR_OK) {
            continue;
        }

        if ((int)$sizes[$index] <= 0 || (int)$sizes[$index] > 8 * 1024 * 1024) {
            continue;
        }

        if (!is_uploaded_file($tmpPath)) {
            continue;
        }

        $mime = '';

        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($tmpPath);
        }

        if (!isset($allowedTypes[$mime])) {
            continue;
        }

        $extension = $allowedTypes[$mime];
        $fileName =
            'image-'
            . date('Ymd-His')
            . '-'
            . bin2hex(random_bytes(4))
            . '.'
            . $extension;

        $targetPath =
            rtrim($targetDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . $fileName;

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            continue;
        }

        $publicPath =
            '/uploads/projects/'
            . $projectId
            . '/posts/'
            . $postId
            . '/'
            . $fileName;

        $stmt = $mysqli->prepare("
            INSERT INTO project_media (
                project_id,
                post_id,
                file_path,
                media_type,
                mime_type,
                sort_order
            )
            VALUES (
                ?,
                ?,
                ?,
                'image',
                ?,
                (
                    SELECT COALESCE(MAX(pm.sort_order), 0) + 10
                    FROM project_media pm
                    WHERE pm.post_id = ?
                )
            )
        ");

        if (!$stmt) {
            @unlink($targetPath);
            continue;
        }

        $stmt->bind_param(
            'iissi',
            $projectId,
            $postId,
            $publicPath,
            $mime,
            $postId
        );

        if (!$stmt->execute()) {
            @unlink($targetPath);
            $stmt->close();
            continue;
        }

        $uploaded[] = [
            'id' => (int)$stmt->insert_id,
            'file_path' => $publicPath,
        ];

        $stmt->close();
    }

    if (!$uploaded) {
        projects_fail(
            'Ingen bild kunde laddas upp. Kontrollera format och storlek.'
        );
    }

    projects_ok([
        'rows' => $uploaded,
        'count' => count($uploaded),
    ]);
}

if ($action === 'PROJECT_POST_IMAGE_CAPTION_UPDATE') {
    $id = (int)($_POST['id'] ?? 0);
    $caption = projects_nullable_text($_POST['caption'] ?? '');

    if ($id <= 0) {
        projects_fail('Bild-id saknas.');
    }

    $stmt = $mysqli->prepare(
        "UPDATE project_media
         SET caption = ?
         WHERE id = ?
           AND media_type = 'image'"
    );

    if (!$stmt) {
        projects_fail('Bildtexten kunde inte förberedas.', 500);
    }

    $stmt->bind_param('si', $caption, $id);
    $stmt->execute();

    projects_ok([
        'id' => $id,
        'affected' => $stmt->affected_rows,
    ]);
}

if ($action === 'PROJECT_POST_IMAGE_DELETE') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        projects_fail('Bild-id saknas.');
    }

    $stmt = $mysqli->prepare(
        "SELECT file_path
         FROM project_media
         WHERE id = ?
           AND media_type = 'image'
         LIMIT 1"
    );

    if (!$stmt) {
        projects_fail('Bilden kunde inte läsas.', 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        projects_fail('Bilden hittades inte.', 404);
    }

    $stmt = $mysqli->prepare(
        "DELETE FROM project_media WHERE id = ?"
    );

    if (!$stmt) {
        projects_fail('Bilden kunde inte tas bort.', 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    $publicPath = (string)$row['file_path'];

    if (preg_match('#^/uploads/projects/\d+/posts/\d+/[^/]+$#', $publicPath)) {
        $diskPath = dirname(__DIR__) . '/Projects' . $publicPath;
        if (is_file($diskPath)) {
            @unlink($diskPath);
        }
    }

    projects_ok(['affected' => $affected]);
}

if ($action === 'PROJECT_COVER_UPLOAD') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        projects_fail('Projekt-id saknas.');
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        projects_fail('Ingen bild valdes.');
    }

    $file = $_FILES['file'];
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK) {
        projects_fail(
            'Bilduppladdningen misslyckades. Felkod: '
            . $uploadError
        );
    }

    $fileSize = (int)($file['size'] ?? 0);

    if ($fileSize <= 0) {
        projects_fail('Den uppladdade bilden är tom.');
    }

    if ($fileSize > 8 * 1024 * 1024) {
        projects_fail('Bilden är för stor. Maxstorlek är 8 MB.');
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');

    if (
        $tmpPath === ''
        || !is_uploaded_file($tmpPath)
    ) {
        projects_fail('Den uppladdade filen är ogiltig.');
    }

    $mime = '';

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpPath);
    }

    if ($mime === '') {
        $mime = (string)($file['type'] ?? '');
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($allowedTypes[$mime])) {
        projects_fail(
            'Otillåten bildtyp. Tillåtna format är JPG, PNG, WebP och GIF.'
        );
    }

    $stmt = $mysqli->prepare(
        "SELECT cover_image
         FROM projects
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        projects_fail(
            'Projektet kunde inte kontrolleras.',
            500
        );
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $project = $result->fetch_assoc();
    $stmt->close();

    if (!$project) {
        projects_fail('Projektet hittades inte.', 404);
    }

    $extension = $allowedTypes[$mime];
    $fileName = 'cover-' . date('Ymd-His') . '.' . $extension;

    /*
     * projects_service.php ligger i:
     * public_html/php/
     *
     * Projektets webroot ligger i:
     * public_html/Projects/
     */
    $targetDirectory =
        dirname(__DIR__)
        . '/projects/uploads/projects/'
        . $id;

    if (
        !is_dir($targetDirectory)
        && !mkdir($targetDirectory, 0775, true)
        && !is_dir($targetDirectory)
    ) {
        projects_fail(
            'Kunde inte skapa katalog för projektbilden.',
            500
        );
    }

    if (!is_writable($targetDirectory)) {
        projects_fail(
            'Katalogen för projektbilder är inte skrivbar.',
            500
        );
    }

    $targetPath =
        rtrim($targetDirectory, '/\\')
        . DIRECTORY_SEPARATOR
        . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        projects_fail(
            'Kunde inte spara projektbilden.',
            500
        );
    }

    $publicPath =
        '/uploads/projects/'
        . $id
        . '/'
        . $fileName;

    $oldCover = trim(
        (string)($project['cover_image'] ?? '')
    );

    $stmt = $mysqli->prepare(
        "UPDATE projects
         SET
            cover_image = ?,
            updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );

    if (!$stmt) {
        @unlink($targetPath);

        projects_fail(
            'Projektbilden kunde inte förberedas för sparning.',
            500
        );
    }

    $stmt->bind_param(
        'si',
        $publicPath,
        $id
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        @unlink($targetPath);

        projects_fail(
            'Projektbilden kunde inte sparas: '
            . $error,
            500
        );
    }

    $stmt->close();

    /*
     * Ta bort tidigare lokalt uppladdad projektbild.
     * Standardbilden och externa URL:er påverkas inte.
     */
    if (
        $oldCover !== ''
        && preg_match(
            '#^/uploads/projects/'
            . preg_quote((string)$id, '#')
            . '/[^/]+$#',
            $oldCover
        )
    ) {
        $oldPath =
            dirname(__DIR__)
            . '/Projects'
            . $oldCover;

        if (
            is_file($oldPath)
            && realpath($oldPath) !== realpath($targetPath)
        ) {
            @unlink($oldPath);
        }
    }

    projects_ok([
        'id' => $id,
        'cover_image' => $publicPath,
        'mime_type' => $mime,
        'file_size' => $fileSize,
    ]);
}

if ($action === 'PROJECT_UPDATE_STATUS') {
    $id = (int)($_POST['id'] ?? 0);
    $status = trim(
        (string)($_POST['status'] ?? '')
    );

    if ($id <= 0) {
        projects_fail('Projekt-id saknas.');
    }

    projects_validate_status($status);

    $completedAt = $status === 'completed'
        ? date('Y-m-d')
        : null;

    $stmt = $mysqli->prepare("
        UPDATE projects
        SET
            status = ?,
            completed_at = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    if (!$stmt) {
        projects_fail(
            'Statusändringen kunde inte förberedas.',
            500
        );
    }

    $stmt->bind_param(
        'ssi',
        $status,
        $completedAt,
        $id
    );

    if (!$stmt->execute()) {
        projects_fail(
            'Statusen kunde inte uppdateras.',
            500
        );
    }

    projects_ok([
        'id' => $id,
        'status' => $status,
        'affected' => $stmt->affected_rows,
    ]);
}

if ($action === 'PROJECT_DELETE') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        projects_fail('Projekt-id saknas.');
    }

    $stmt = $mysqli->prepare(
        "DELETE FROM projects WHERE id = ?"
    );

    if (!$stmt) {
        projects_fail(
            'Borttagningen kunde inte förberedas.',
            500
        );
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        projects_fail(
            'Projektet kunde inte tas bort.',
            500
        );
    }

    projects_ok([
        'affected' => $stmt->affected_rows,
    ]);
}

projects_fail('Okänd action.', 404);
