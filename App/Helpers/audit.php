<?php
// App/Helpers/audit.php

function log_action(
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?string $description = null
): void {
    global $pdo;

    if (!isset($pdo)) {
        return;
    }

    $userId = $_SESSION["user_id"] ?? null;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs
            (
                user_id,
                action,
                entity_type,
                entity_id,
                description
            )
            VALUES
            (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $description
        ]);
    } catch (PDOException $e) {
        // For now, do not break the app if audit logging fails.
        // Later, we can decide whether audit failures should block certain actions.
        return;
    }
}