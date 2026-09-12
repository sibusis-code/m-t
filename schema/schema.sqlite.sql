-- SQLite mirror of schema.mysql.sql — for local development only.
--
-- This exists so the application can be built, run and tested without a MySQL
-- server. It is NOT the canonical schema; schema.mysql.sql is, and it carries
-- the commentary explaining why each column is there.
--
-- Same tables, same column names, same order. tools/migrate.php compares the
-- two files and refuses to run if they have drifted, because a development
-- database that quietly disagrees with production is worse than no development
-- database at all.

CREATE TABLE IF NOT EXISTS tenants (
  id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  slug          TEXT    NOT NULL,
  name          TEXT    NOT NULL,
  academy_name  TEXT    NOT NULL,
  contact_email TEXT    NOT NULL,
  created_at    TEXT    NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_tenants_slug ON tenants (slug);


CREATE TABLE IF NOT EXISTS users (
  id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL REFERENCES tenants (id),
  email         TEXT    NOT NULL,
  password_hash TEXT    NOT NULL,
  first_name    TEXT    NOT NULL,
  last_name     TEXT    NOT NULL,
  employee_no   TEXT        NULL,
  department    TEXT        NULL,
  role          TEXT    NOT NULL DEFAULT 'learner',
  status        TEXT    NOT NULL DEFAULT 'active',
  created_at    TEXT    NOT NULL,
  last_login_at TEXT        NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_tenant_email ON users (tenant_id, email);


CREATE TABLE IF NOT EXISTS registrations (
  id           INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL REFERENCES tenants (id),
  user_id      INTEGER     NULL REFERENCES users (id),
  course_slug  TEXT        NULL,
  course_title TEXT        NULL,
  full_name    TEXT    NOT NULL,
  email        TEXT    NOT NULL,
  phone        TEXT        NULL,
  employee_no  TEXT        NULL,
  department   TEXT        NULL,
  line_manager TEXT        NULL,
  message      TEXT        NULL,
  status       TEXT    NOT NULL DEFAULT 'new',
  source       TEXT    NOT NULL DEFAULT 'web',
  ip_hash      TEXT        NULL,
  user_agent   TEXT        NULL,
  created_at   TEXT    NOT NULL,
  purge_after  TEXT        NULL
);
CREATE INDEX IF NOT EXISTS ix_reg_tenant_created ON registrations (tenant_id, created_at);
CREATE INDEX IF NOT EXISTS ix_reg_purge ON registrations (purge_after);


CREATE TABLE IF NOT EXISTS consents (
  id              INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NOT NULL REFERENCES tenants (id),
  registration_id INTEGER     NULL REFERENCES registrations (id),
  user_id         INTEGER     NULL REFERENCES users (id),
  purpose         TEXT    NOT NULL,
  policy_version  TEXT    NOT NULL,
  granted         INTEGER NOT NULL DEFAULT 1,
  granted_at      TEXT    NOT NULL,
  ip_hash         TEXT        NULL
);
CREATE INDEX IF NOT EXISTS ix_consent_tenant ON consents (tenant_id);


CREATE TABLE IF NOT EXISTS audit_log (
  id             INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id      INTEGER     NULL,
  actor_user_id  INTEGER     NULL,
  action         TEXT    NOT NULL,
  entity         TEXT        NULL,
  entity_id      INTEGER     NULL,
  detail         TEXT        NULL,
  ip_hash        TEXT        NULL,
  created_at     TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_audit_tenant_created ON audit_log (tenant_id, created_at);
CREATE INDEX IF NOT EXISTS ix_audit_entity ON audit_log (entity, entity_id);


CREATE TABLE IF NOT EXISTS password_resets (
  id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL REFERENCES tenants (id),
  user_id    INTEGER NOT NULL REFERENCES users (id),
  token_hash TEXT    NOT NULL,
  expires_at TEXT    NOT NULL,
  used_at    TEXT        NULL,
  ip_hash    TEXT        NULL,
  created_at TEXT    NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_reset_token ON password_resets (token_hash);
CREATE INDEX IF NOT EXISTS ix_reset_user ON password_resets (tenant_id, user_id);
CREATE INDEX IF NOT EXISTS ix_reset_expires ON password_resets (expires_at);


-- See the long note on this table in schema.mysql.sql: a first-time
-- set-password link for an account an administrator just created, kept apart
-- from password_resets because a reset and an invite answer different
-- questions even though the token mechanics match.
CREATE TABLE IF NOT EXISTS account_invites (
  id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL REFERENCES tenants (id),
  user_id    INTEGER NOT NULL REFERENCES users (id),
  token_hash TEXT    NOT NULL,
  expires_at TEXT    NOT NULL,
  used_at    TEXT        NULL,
  invited_by INTEGER     NULL REFERENCES users (id),
  created_at TEXT    NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_invite_token ON account_invites (token_hash);
CREATE INDEX IF NOT EXISTS ix_invite_user ON account_invites (tenant_id, user_id);
CREATE INDEX IF NOT EXISTS ix_invite_expires ON account_invites (expires_at);


CREATE TABLE IF NOT EXISTS enrolments (
  id              INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NOT NULL REFERENCES tenants (id),
  user_id         INTEGER NOT NULL REFERENCES users (id),
  course_slug     TEXT    NOT NULL,
  course_title    TEXT        NULL,
  registration_id INTEGER     NULL REFERENCES registrations (id),
  status          TEXT    NOT NULL DEFAULT 'active',
  enrolled_at     TEXT    NOT NULL,
  enrolled_by     INTEGER     NULL REFERENCES users (id),
  completed_at    TEXT        NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_enrol_user_course ON enrolments (tenant_id, user_id, course_slug);
CREATE INDEX IF NOT EXISTS ix_enrol_tenant_status ON enrolments (tenant_id, status);


CREATE TABLE IF NOT EXISTS learner_progress (
  id           INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL REFERENCES tenants (id),
  user_id      INTEGER NOT NULL REFERENCES users (id),
  course_slug  TEXT    NOT NULL,
  module_code  TEXT    NOT NULL,
  item_code    TEXT    NOT NULL DEFAULT '',
  completed_at TEXT    NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_lprog_item ON learner_progress (tenant_id, user_id, course_slug, module_code, item_code);
CREATE INDEX IF NOT EXISTS ix_lprog_user ON learner_progress (tenant_id, user_id);


CREATE TABLE IF NOT EXISTS progress_reports (
  id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL REFERENCES tenants (id),
  user_id       INTEGER     NULL REFERENCES users (id),
  full_name     TEXT    NOT NULL,
  email         TEXT    NOT NULL,
  employee_no   TEXT        NULL,
  line_manager  TEXT        NULL,
  qualification TEXT        NULL,
  summary       TEXT        NULL,
  detail        TEXT        NULL,
  message       TEXT        NULL,
  status        TEXT    NOT NULL DEFAULT 'new',
  ip_hash       TEXT        NULL,
  user_agent    TEXT        NULL,
  created_at    TEXT    NOT NULL,
  purge_after   TEXT        NULL
);
CREATE INDEX IF NOT EXISTS ix_prog_tenant_created ON progress_reports (tenant_id, created_at);

-- See the long note on this table in schema.mysql.sql. In short: we store a
-- LINK, never a file; a "anyone with the link" Drive URL is a bearer token; and
-- assessment material must never be entered here.
CREATE TABLE IF NOT EXISTS materials (
  id          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER NOT NULL REFERENCES tenants (id),
  course_slug TEXT    NOT NULL,
  module_code TEXT    NOT NULL,
  kind        TEXT    NOT NULL,
  url         TEXT    NOT NULL,
  label       TEXT        NULL,
  updated_at  TEXT    NOT NULL,
  updated_by  INTEGER     NULL REFERENCES users (id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_material_slot ON materials (tenant_id, course_slug, module_code, kind);
CREATE INDEX IF NOT EXISTS ix_material_course ON materials (tenant_id, course_slug);

-- See the long note on this table in schema.mysql.sql: the file-backed twin
-- of `materials`, its own table rather than columns bolted on, mutually
-- exclusive with a `materials` row for the same slot (enforced in PHP).
CREATE TABLE IF NOT EXISTS material_files (
  id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL REFERENCES tenants (id),
  course_slug   TEXT    NOT NULL,
  module_code   TEXT    NOT NULL,
  kind          TEXT    NOT NULL,
  disk_name     TEXT    NOT NULL,
  original_name TEXT    NOT NULL,
  mime_type     TEXT    NOT NULL,
  size_bytes    INTEGER NOT NULL,
  updated_at    TEXT    NOT NULL,
  updated_by    INTEGER     NULL REFERENCES users (id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_matfile_slot ON material_files (tenant_id, course_slug, module_code, kind);
CREATE INDEX IF NOT EXISTS ix_matfile_course ON material_files (tenant_id, course_slug);

-- See the long note on this table in schema.mysql.sql: a self-check quiz for
-- one module, NOT the QCTO assessment. One quiz per (course, module).
CREATE TABLE IF NOT EXISTS quizzes (
  id           INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL REFERENCES tenants (id),
  course_slug  TEXT    NOT NULL,
  module_code  TEXT    NOT NULL,
  pass_pct     INTEGER     NULL,
  published    INTEGER NOT NULL DEFAULT 0,
  created_at   TEXT    NOT NULL,
  updated_at   TEXT    NOT NULL,
  updated_by   INTEGER     NULL REFERENCES users (id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_quiz_slot ON quizzes (tenant_id, course_slug, module_code);

-- active, not deleted, on removal — see the long note in schema.mysql.sql:
-- quiz_attempt_answers hangs off a question the same way enrolments hang off
-- a user, so it is switched off rather than deleted.
CREATE TABLE IF NOT EXISTS quiz_questions (
  id           INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL REFERENCES tenants (id),
  quiz_id      INTEGER NOT NULL REFERENCES quizzes (id),
  prompt       TEXT    NOT NULL,
  sort_order   INTEGER NOT NULL DEFAULT 0,
  active       INTEGER NOT NULL DEFAULT 1,
  created_at   TEXT    NOT NULL,
  updated_at   TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_quizq_quiz ON quiz_questions (tenant_id, quiz_id, sort_order);

CREATE TABLE IF NOT EXISTS quiz_choices (
  id           INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL REFERENCES tenants (id),
  question_id  INTEGER NOT NULL REFERENCES quiz_questions (id),
  choice_text  TEXT    NOT NULL,
  is_correct   INTEGER NOT NULL DEFAULT 0,
  sort_order   INTEGER NOT NULL DEFAULT 0,
  active       INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS ix_quizc_question ON quiz_choices (tenant_id, question_id, sort_order);

-- See the long note on this table in schema.mysql.sql: NO score_pct column —
-- computed in PHP from score_count/question_count at read time. Unlimited
-- attempts, best kept, so there is no UNIQUE on (quiz_id, user_id).
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id             INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id      INTEGER NOT NULL REFERENCES tenants (id),
  quiz_id        INTEGER NOT NULL REFERENCES quizzes (id),
  user_id        INTEGER NOT NULL REFERENCES users (id),
  started_at     TEXT    NOT NULL,
  submitted_at   TEXT    NOT NULL,
  score_count    INTEGER NOT NULL,
  question_count INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_qatt_quiz_user ON quiz_attempts (tenant_id, quiz_id, user_id);
CREATE INDEX IF NOT EXISTS ix_qatt_user ON quiz_attempts (tenant_id, user_id);

CREATE TABLE IF NOT EXISTS quiz_attempt_answers (
  id           INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL REFERENCES tenants (id),
  attempt_id   INTEGER NOT NULL REFERENCES quiz_attempts (id),
  question_id  INTEGER NOT NULL REFERENCES quiz_questions (id),
  choice_id    INTEGER     NULL REFERENCES quiz_choices (id),
  is_correct   INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_qans_attempt_question ON quiz_attempt_answers (tenant_id, attempt_id, question_id);


-- Written teaching content for one area of one topic. See the fuller note in
-- schema.mysql.sql: it is here rather than in pm-modules.js because that file
-- is public and this is Centenary's material.
CREATE TABLE IF NOT EXISTS topic_sections (
  id          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER NOT NULL REFERENCES tenants (id),
  course_slug TEXT    NOT NULL,
  module_code TEXT    NOT NULL,
  topic_code  TEXT    NOT NULL,
  area_index  INTEGER NOT NULL,
  area_title  TEXT    NOT NULL,
  body        TEXT    NOT NULL,
  published   INTEGER NOT NULL DEFAULT 0,
  updated_at  TEXT    NOT NULL,
  updated_by  INTEGER     NULL REFERENCES users (id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_section_area ON topic_sections (tenant_id, course_slug, module_code, topic_code, area_index);
CREATE INDEX IF NOT EXISTS ix_section_module ON topic_sections (tenant_id, course_slug, module_code);

/* Which letters have already gone to which learner — see the MySQL schema for
   why this exists and why a failed send is still recorded. */
CREATE TABLE IF NOT EXISTS letters_sent (
  id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL REFERENCES tenants (id),
  user_id    INTEGER NOT NULL REFERENCES users (id),
  kind       TEXT    NOT NULL,
  ref        TEXT    NOT NULL,
  delivered  INTEGER NOT NULL DEFAULT 0,
  sent_at    TEXT    NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_letter_once ON letters_sent (tenant_id, user_id, kind, ref);
CREATE INDEX IF NOT EXISTS ix_letter_user ON letters_sent (tenant_id, user_id);

-- Which courses a trainer may see. See the note in schema.mysql.sql — rights
-- here are additive from zero, so an account with no rows sees nothing.
CREATE TABLE IF NOT EXISTS trainer_courses (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER NOT NULL REFERENCES tenants (id),
  user_id     INTEGER NOT NULL REFERENCES users (id),
  course_slug TEXT    NOT NULL,
  created_at  TEXT    NOT NULL,
  created_by  INTEGER NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_traincourse ON trainer_courses (tenant_id, user_id, course_slug);
CREATE INDEX IF NOT EXISTS ix_traincourse_user ON trainer_courses (tenant_id, user_id);

-- In-person classes and the register a facilitator marks. See the long note in
-- schema.mysql.sql for why attendance is its own table and why marked_by is
-- recorded.
CREATE TABLE IF NOT EXISTS classes (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id      INTEGER NOT NULL REFERENCES tenants (id),
  course_slug    TEXT    NOT NULL,
  title          TEXT    NOT NULL,
  venue          TEXT        NULL,
  held_on        TEXT    NOT NULL,
  starts_at      TEXT        NULL,
  ends_at        TEXT        NULL,
  facilitator_id INTEGER     NULL REFERENCES users (id),
  notes          TEXT        NULL,
  status         TEXT    NOT NULL DEFAULT 'scheduled',
  created_at     TEXT    NOT NULL,
  created_by     INTEGER     NULL
);
CREATE INDEX IF NOT EXISTS ix_class_course ON classes (tenant_id, course_slug, held_on);
CREATE INDEX IF NOT EXISTS ix_class_facil  ON classes (tenant_id, facilitator_id);

CREATE TABLE IF NOT EXISTS class_attendance (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL REFERENCES tenants (id),
  class_id   INTEGER NOT NULL REFERENCES classes (id),
  user_id    INTEGER NOT NULL REFERENCES users (id),
  status     TEXT    NOT NULL,
  note       TEXT        NULL,
  marked_at  TEXT    NOT NULL,
  marked_by  INTEGER     NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_attend_once ON class_attendance (tenant_id, class_id, user_id);
CREATE INDEX IF NOT EXISTS ix_attend_user ON class_attendance (tenant_id, user_id);
