<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 immutability posture for support_session_events (PostgreSQL).
 *
 * Blocks UPDATE at the database layer. DELETE remains available so FK
 * cascade / controlled archive jobs still work — application Eloquent
 * guards reject deletes from product code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION support_session_events_forbid_update()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'support_session_events is append-only; UPDATE is not allowed';
END;
$$;

DROP TRIGGER IF EXISTS trg_support_session_events_forbid_update ON support_session_events;

CREATE TRIGGER trg_support_session_events_forbid_update
    BEFORE UPDATE ON support_session_events
    FOR EACH ROW
    EXECUTE PROCEDURE support_session_events_forbid_update();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_support_session_events_forbid_update ON support_session_events;
DROP FUNCTION IF EXISTS support_session_events_forbid_update();
SQL);
    }
};
