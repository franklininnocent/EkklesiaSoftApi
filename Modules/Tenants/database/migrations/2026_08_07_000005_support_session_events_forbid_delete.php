<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.1: forbid DELETE on support_session_events (append-only).
 * Controlled archival must use a dedicated process that drops the trigger first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION support_session_events_forbid_delete()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'support_session_events is append-only; DELETE is not allowed';
END;
$$;

DROP TRIGGER IF EXISTS trg_support_session_events_forbid_delete ON support_session_events;

CREATE TRIGGER trg_support_session_events_forbid_delete
    BEFORE DELETE ON support_session_events
    FOR EACH ROW
    EXECUTE PROCEDURE support_session_events_forbid_delete();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_support_session_events_forbid_delete ON support_session_events;
DROP FUNCTION IF EXISTS support_session_events_forbid_delete();
SQL);
    }
};
