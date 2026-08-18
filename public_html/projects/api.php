<?php

declare(strict_types=1);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'auth_check':
        require __DIR__ . '/../php/projects_auth_check.php';
        exit;

    case 'login':
        require __DIR__ . '/../php/projects_login.php';
        exit;

    case 'logout':
        require __DIR__ . '/../php/projects_logout.php';
        exit;

    case 'PROJECT_LIST':
    case 'PROJECT_GET':
    case 'PROJECT_ADD':
    case 'PROJECT_UPDATE':
    case 'PROJECT_COVER_UPLOAD':
    case 'PROJECT_LINK_LIST':
    case 'PROJECT_LINK_ADD':
    case 'PROJECT_LINK_DELETE':
    case 'PROJECT_POST_LIST':
    case 'PROJECT_POST_GET':
    case 'PROJECT_POST_ADD':
    case 'PROJECT_POST_UPDATE':
    case 'PROJECT_POST_DELETE':
    case 'PROJECT_POST_IMAGE_UPLOAD':
    case 'PROJECT_POST_IMAGE_CAPTION_UPDATE':
    case 'PROJECT_POST_IMAGE_DELETE':
    case 'PROJECT_UPDATE_STATUS':
    case 'PROJECT_DELETE':
        require __DIR__ . '/../php/projects_service.php';
        exit;

    default:
        require __DIR__ . '/../php/projects_service.php';
        exit;
}
