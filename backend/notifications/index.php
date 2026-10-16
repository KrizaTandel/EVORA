<?php
/**
 * EVORA HRMS — User Notifications API Endpoint
 * Core PHP 8.2
 * 
 * GET:  List current authenticated user's private notifications & unread count
 * POST: Mark single or all owned notifications as read (strictly validates ownership)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

// Requires authenticated session
$currentUser = requireAuth();
$userId = (int)$currentUser['id'];
$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------
// 1. GET: Fetch current user's notifications
// ---------------------------------------------------------------------
if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare('
            SELECT id, title, message, is_read, created_at
            FROM notifications
            WHERE user_id = :uid
            ORDER BY created_at DESC
            LIMIT 50
        ');
        $stmt->execute([':uid' => $userId]);
        $notifications = $stmt->fetchAll();

        // Count unread
        $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0');
        $unreadStmt->execute([':uid' => $userId]);
        $unreadCount = (int)$unreadStmt->fetchColumn();

        jsonResponse(true, 'Notifications retrieved.', [
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
            'total'         => count($notifications),
        ], 200);

    } catch (PDOException $e) {
        error_log('[EVORA Notifications GET Error] ' . $e->getMessage());
        jsonResponse(false, 'Unable to load notifications.', [], 500);
    }
}

// ---------------------------------------------------------------------
// 2. POST: Mark notification(s) as read
// ---------------------------------------------------------------------
if ($method === 'POST') {
    requireCsrf();
    $input = getRequestBody();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $notifId = isset($input['id']) ? (int)$input['id'] : 0;

    try {
        if ($action === 'mark_all') {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
            jsonResponse(true, 'All notifications marked as read.', [], 200);
        }

        if ($notifId > 0) {
            // Strictly enforce ownership constraint in WHERE clause
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid');
            $stmt->execute([':id' => $notifId, ':uid' => $userId]);

            if ($stmt->rowCount() === 0) {
                // Verify whether notification exists for another user or doesn't exist
                $checkStmt = $pdo->prepare('SELECT id, user_id FROM notifications WHERE id = :id LIMIT 1');
                $checkStmt->execute([':id' => $notifId]);
                $exists = $checkStmt->fetch();

                if (!$exists) {
                    jsonResponse(false, 'Notification not found.', [], 404);
                }
                // Exists but owned by another user -> forbidden
                jsonResponse(false, 'Access forbidden: You cannot modify notifications belonging to another user.', [], 403);
            }

            jsonResponse(true, 'Notification marked as read.', ['id' => $notifId], 200);
        }

        jsonResponse(false, 'Please provide a valid notification ID or action="mark_all".', [], 422);

    } catch (PDOException $e) {
        error_log('[EVORA Notifications POST Error] ' . $e->getMessage());
        jsonResponse(false, 'Failed to update notification state.', [], 500);
    }
}

jsonResponse(false, 'Method not allowed.', [], 405);
