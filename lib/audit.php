<?php
declare(strict_types=1);

/* The access trail.
 *
 * POPIA s19 asks for reasonable technical measures to secure personal
 * information. Most of those measures — hashing, TLS, scoped queries — are
 * invisible and unfalsifiable from the outside. This one is the exception: it
 * is the record that makes the others auditable, and it is what turns "we think
 * nothing was accessed" into an answer after an incident.
 *
 * Deliberately never records the personal information itself. Logging a
 * learner's email into the audit table to describe reading that learner's email
 * just makes a second copy in a table with a longer retention period.
 */

defined('APP_BOOTED') or exit('lib/audit.php is not a page.');

function audit(
    string $action,
    ?string $entity = null,
    ?int $entityId = null,
    ?string $detail = null,
    ?int $actorUserId = null
): void {
    try {
        db_insert('audit_log', [
            'tenant_id'     => tenant_id(),
            'actor_user_id' => $actorUserId ?? current_user_id(),
            'action'        => mb_substr($action, 0, 60),
            'entity'        => $entity === null ? null : mb_substr($entity, 0, 60),
            'entity_id'     => $entityId,
            'detail'        => $detail === null ? null : mb_substr($detail, 0, 500),
            'ip_hash'       => client_ip_hash(),
            'created_at'    => now(),
        ]);
    } catch (Throwable $e) {
        // An audit failure must never take down the thing being audited — a
        // learner should not lose a registration because a log write failed.
        // It does not pass silently either: it goes to the file log, which is
        // itself the signal that the database is unhealthy.
        app_log('AUDIT FAILED (' . $action . '): ' . $e->getMessage());
    }
}
