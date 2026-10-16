<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/security.php';

if (!isset($_SESSION['username'], $_SESSION['user_id'])) {
    http_response_code(401);

    echo json_encode([
        'ok' => false,
        'error' => 'Unauthorized'
    ]);
    exit;
}

require_once __DIR__ . '/../dbinv.php';


function respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}


$method = $_SERVER['REQUEST_METHOD'];

$user_id = (int) ($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    respond([
        'ok' => false,
        'error' => 'Invalid user session.'
    ], 401);
}


/*
 * =========================================================
 * GET
 * =========================================================
 */

if ($method === 'GET') {
    $view = trim((string) ($_GET['view'] ?? 'message_center'));

    /*
     * -----------------------------------------------------
     * OPEN MESSAGES
     * -----------------------------------------------------
     *
     * Active INFO messages that this user has not marked
     * as read.
     */

    if ($view === 'message_center') {

        $stmt = $conn->prepare(
            "SELECT
                cn.id,
                cn.note_date,
                cn.title,
                cn.description,
                cn.category,
                cn.created_by,
                cn.created_at

             FROM calendar_notes cn

             LEFT JOIN calendar_note_reads cnr
               ON cnr.note_id = cn.id
              AND cnr.user_id = ?

             WHERE cn.is_active = 1
               AND cn.category = 'INFO'
               AND cnr.note_id IS NULL

             ORDER BY
               cn.note_date DESC,
               cn.id DESC"
        );

        if (!$stmt) {
            respond([
                'ok' => false,
                'error' => 'Unable to prepare open messages query.'
            ], 500);
        }


        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];

        while (
            $row = $result->fetch_assoc()
        ) {
            $messages[] = $row;
        }

        $stmt->close();

        respond([
            'ok' => true,
            'view' => 'open',
            'messages' => $messages,
            'count' => count($messages)
        ]);
    }


    /*
     * -----------------------------------------------------
     * READ MESSAGES
     * -----------------------------------------------------
     *
     * Messages this user marked read during the last
     * 30 days.
     */

    if ($view === 'read_messages') {

        $stmt = $conn->prepare(
            "SELECT
                cn.id,
                cn.note_date,
                cn.title,
                cn.description,
                cn.category,
                cn.created_by,
                cn.created_at,
                cnr.read_at

             FROM calendar_notes cn

             INNER JOIN calendar_note_reads cnr
               ON cnr.note_id = cn.id
              AND cnr.user_id = ?

             WHERE cn.is_active = 1
               AND cn.category = 'INFO'
               AND cnr.read_at >= DATE_SUB(
                    NOW(),
                    INTERVAL 30 DAY
               )

             ORDER BY
               cnr.read_at DESC,
               cn.id DESC"
        );


        if (!$stmt) {
            respond([
                'ok' => false,
                'error' => 'Unable to prepare read messages query.'
            ], 500);
        }


        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];


        while (
            $row = $result->fetch_assoc()
        ) {
            $messages[] = $row;
        }

        $stmt->close();

        respond([
            'ok' => true,
            'view' => 'read',
            'messages' => $messages,
            'count' => count($messages)
        ]);
    }


    /*
     * -----------------------------------------------------
     * MESSAGE HISTORY
     * -----------------------------------------------------
     *
     * Messages read more than 30 days ago.
     */

    if ($view === 'old_messages') {

        $stmt = $conn->prepare(
            "SELECT
                cn.id,
                cn.note_date,
                cn.title,
                cn.description,
                cn.category,
                cn.created_by,
                cn.created_at,
                cnr.read_at

             FROM calendar_notes cn

             INNER JOIN calendar_note_reads cnr
               ON cnr.note_id = cn.id
              AND cnr.user_id = ?

             WHERE cn.is_active = 1
               AND cn.category = 'INFO'
               AND cnr.read_at < DATE_SUB(
                    NOW(),
                    INTERVAL 30 DAY
               )

             ORDER BY
               cnr.read_at DESC,
               cn.id DESC

             LIMIT 100"
        );


        if (!$stmt) {
            respond([
                'ok' => false,
                'error' => 'Unable to prepare message history query.'
            ], 500);
        }


        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];

        while (
            $row = $result->fetch_assoc()
        ) {
            $messages[] = $row;
        }


        $stmt->close();


        respond([
            'ok' => true,
            'view' => 'history',
            'messages' => $messages,
            'count' => count($messages)
        ]);
    }


    /*
     * Unknown GET view.
     */

    respond([
        'ok' => false,
        'error' => 'Invalid Message Center view.'
    ], 400);
}


/*
 * =========================================================
 * POST
 * =========================================================
 */

if ($method === 'POST') {

    $body =
        file_get_contents('php://input');

    $json =
        $body
        ? json_decode($body, true)
        : null;


    if (!is_array($json)) {
        respond([
            'ok' => false,
            'error' => 'Invalid JSON.'
        ], 400);
    }


    $action =
        trim(
            (string) ($json['action'] ?? '')
        );


    /*
     * -----------------------------------------------------
     * MARK MESSAGE READ
     * -----------------------------------------------------
     */

    if ($action === 'mark_read') {

        $note_id =
            (int) ($json['id'] ?? 0);


        if ($note_id <= 0) {
            respond([
                'ok' => false,
                'error' => 'Invalid message.'
            ], 400);
        }


        /*
         * Verify that the requested record is an active
         * Message Center message.
         */

        $stmt = $conn->prepare(
            "SELECT id

             FROM calendar_notes

             WHERE id = ?
               AND is_active = 1
               AND category = 'INFO'

             LIMIT 1"
        );


        if (!$stmt) {
            respond([
                'ok' => false,
                'error' => 'Unable to validate message.'
            ], 500);
        }


        $stmt->bind_param('i', $note_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $message = $result->fetch_assoc();
        $stmt->close();

        if (!$message) {
            respond([
                'ok' => false,
                'error' => 'Message not found.'
            ], 404);
        }


        /*
         * Store read state for this user only.
         */

        $stmt = $conn->prepare(
            "INSERT INTO calendar_note_reads
                (
                    note_id,
                    user_id,
                    read_at
                )

             VALUES (
                    ?,
                    ?,
                    NOW()
             )

             ON DUPLICATE KEY UPDATE
                read_at = NOW()"
        );


        if (!$stmt) {
            respond([
                'ok' => false,
                'error' => 'Unable to prepare message update.'
            ], 500);
        }

        $stmt->bind_param('ii', $note_id, $user_id);


        if (!$stmt->execute()) {
            $stmt->close();

            respond([
                'ok' => false,
                'error' => 'Unable to mark message as read.'
            ], 500);
        }

        $stmt->close();

        respond([
            'ok' => true,
            'id' => $note_id
        ]);
    }


    /*
     * Unknown POST action.
     */

    respond([
        'ok' => false,
        'error' => 'Invalid Message Center action.'
    ], 400);
}


/*
 * =========================================================
 * Unsupported method
 * =========================================================
 */

respond([
    'ok' => false,
    'error' => 'Method not allowed.'
], 405);