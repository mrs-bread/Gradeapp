<?php
function audit_log(PDO $pdo, $userId, string $action, $details = null)
{
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
        $json = null;
        if ($details !== null) {
            if (is_string($details))
                $json = $details;
            else
                $json = json_encode($details, JSON_UNESCAPED_UNICODE);
        }
        $stmt->execute([$userId, $action, $json]);
    } catch (Throwable $e) {
        error_log("audit_log failed: " . $e->getMessage());
    }
}
