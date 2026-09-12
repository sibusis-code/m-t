-- Centenary Networks academy platform — MySQL schema (canonical).
--
-- ONE database, one row per company in `tenants`, and a tenant_id on every
-- table that holds personal information. The alternative — a database per
-- company — isolates harder but multiplies by four every migration, backup and
-- credential. With one database the isolation has to be enforced in code, so it
-- is enforced in exactly one place: tenant_id() in lib/db.php reads the tenant
-- from a config file that lives outside the web root, and nothing derives it
-- from anything a visitor can send.
--
-- schema/schema.sqlite.sql mirrors this file table for table and column for
-- column so the application can run on a laptop. tools/migrate.php compares the
-- two and refuses to run if they have drifted apart.
--
-- POPIA notes are inline. They are not decoration: each one is the reason a
-- column exists, or the reason a column deliberately does not.

CREATE TABLE IF NOT EXISTS tenants (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(40)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  academy_name  VARCHAR(120) NOT NULL,
  contact_email VARCHAR(190) NOT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Learners and administrators.
--
-- No ID number column, and that is deliberate for now. A South African ID
-- number is needed to register a learner with the QCTO, but it is not needed to
-- express interest in a course, and POPIA's minimality condition says we do not
-- collect it until the purpose exists. It gets added with the enrolment tables,
-- with its own consent, not before.
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name    VARCHAR(80)  NOT NULL,
  last_name     VARCHAR(80)  NOT NULL,
  employee_no   VARCHAR(40)      NULL,
  department    VARCHAR(120)     NULL,
  role          VARCHAR(20)  NOT NULL DEFAULT 'learner',
  status        VARCHAR(20)  NOT NULL DEFAULT 'active',
  created_at    DATETIME     NOT NULL,
  last_login_at DATETIME         NULL,
  PRIMARY KEY (id),
  -- Scoped to the tenant, not global: the same person could legitimately be a
  -- learner at two of these companies, and one must not see the other.
  UNIQUE KEY uq_users_tenant_email (tenant_id, email),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Course registrations — what the FormSubmit form used to email away.
--
-- The row is now the record. Under FormSubmit the only copy lived in one
-- person's inbox, on a third-party service outside South Africa, with no
-- retention rule and no way to answer "what do you hold about me".
CREATE TABLE IF NOT EXISTS registrations (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED     NULL,
  course_slug  VARCHAR(80)      NULL,
  course_title VARCHAR(190)     NULL,
  full_name    VARCHAR(160) NOT NULL,
  email        VARCHAR(190) NOT NULL,
  phone        VARCHAR(40)      NULL,
  employee_no  VARCHAR(40)      NULL,
  department   VARCHAR(120)     NULL,
  line_manager VARCHAR(160)     NULL,
  message      TEXT             NULL,
  status       VARCHAR(20)  NOT NULL DEFAULT 'new',
  source       VARCHAR(40)  NOT NULL DEFAULT 'web',
  -- Hashed, never the address itself. See 'ip_pepper' in lib/config.sample.php.
  ip_hash      CHAR(64)         NULL,
  user_agent   VARCHAR(255)     NULL,
  created_at   DATETIME     NOT NULL,
  -- POPIA s14: personal information is not kept longer than the purpose needs.
  -- A date on the row makes deletion a scheduled job rather than a good
  -- intention. Registrations that never become enrolments expire; the ones that
  -- do are carried over to the enrolment record with its own longer basis,
  -- because QCTO record-keeping obliges us to retain those.
  purge_after  DATE             NULL,
  PRIMARY KEY (id),
  KEY ix_reg_tenant_created (tenant_id, created_at),
  KEY ix_reg_purge (purge_after),
  CONSTRAINT fk_reg_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_reg_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- What each person agreed to, and when.
--
-- Consent that cannot be evidenced is not consent. policy_version pins the row
-- to the wording that was on screen at the time, so "I never agreed to that"
-- has an answer years later, after the notice has been revised twice.
CREATE TABLE IF NOT EXISTS consents (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED NOT NULL,
  registration_id INT UNSIGNED     NULL,
  user_id         INT UNSIGNED     NULL,
  purpose         VARCHAR(60)  NOT NULL,
  policy_version  VARCHAR(20)  NOT NULL,
  granted         TINYINT(1)   NOT NULL DEFAULT 1,
  granted_at      DATETIME     NOT NULL,
  ip_hash         CHAR(64)         NULL,
  PRIMARY KEY (id),
  KEY ix_consent_tenant (tenant_id),
  CONSTRAINT fk_consent_tenant FOREIGN KEY (tenant_id)       REFERENCES tenants (id),
  CONSTRAINT fk_consent_reg    FOREIGN KEY (registration_id) REFERENCES registrations (id),
  CONSTRAINT fk_consent_user   FOREIGN KEY (user_id)         REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Who did what to whose data.
--
-- POPIA s19 requires reasonable measures to secure personal information, and an
-- access trail is the one that makes the others checkable. It is also the only
-- way to answer the question the Information Regulator asks after a breach:
-- what was reached, and by whom.
CREATE TABLE IF NOT EXISTS audit_log (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED     NULL,
  actor_user_id  INT UNSIGNED     NULL,
  action         VARCHAR(60)  NOT NULL,
  entity         VARCHAR(60)      NULL,
  entity_id      INT UNSIGNED     NULL,
  detail         VARCHAR(500)     NULL,
  ip_hash        CHAR(64)         NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_tenant_created (tenant_id, created_at),
  KEY ix_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Password reset links.
--
-- The token itself is NEVER stored. What is stored is its SHA-256 hash, for the
-- same reason passwords are hashed: this table is the one an attacker who gets a
-- read of the database would go to first, and a table of live reset tokens is a
-- table of working sign-ins for every account in it. A hash makes the stolen
-- copy worthless.
--
-- Rows are kept after use rather than deleted, and used_at is why: "somebody
-- reset this account's password on the 3rd" is a question that gets asked after
-- an incident, and a deleted row cannot answer it. They carry no personal
-- information beyond the user id.
--
-- No email column. The address is on the user row; copying it here would create
-- a second place to find every learner's email address, with its own lifetime.
CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME         NULL,
  ip_hash    CHAR(64)         NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  -- Unique on the hash, so two tokens can never collide into one account.
  UNIQUE KEY uq_reset_token (token_hash),
  KEY ix_reset_user (tenant_id, user_id),
  KEY ix_reset_expires (expires_at),
  CONSTRAINT fk_reset_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_reset_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- First-time set-password links, for an account an administrator just created.
--
-- Deliberately its own table rather than a row in password_resets, even though
-- the mechanics are identical (a hashed single-use token, an expiry, sign in on
-- success). The two answer different questions: a reset assumes a working
-- password already existed and the person asking IS the visitor who forgot it;
-- an invite exists because the account never had a password anyone knows, the
-- request is not self-service at all — it is an administrator pressing "Enrol"
-- — and the visitor who eventually opens the link is proving they are the
-- person named on a brand-new account, not recovering an old one. Folding that
-- into password_resets would mean either a misleading "someone asked to reset
-- your password" email for a password that never existed, or a branching flag
-- threaded through a file (lib/reset.php) that earns its safety from being
-- small and read as a whole. Two tables, two short files, one job each.
--
-- Same token handling as password_resets and for the same reasons: the token
-- itself is NEVER stored, only its SHA-256 hash, so a stolen read of this table
-- is a table of useless strings rather than working sign-ins; rows are kept
-- after use because "who invited this account, and when" is a question that
-- gets asked later, not deleted once answered.
--
-- Seven days, not the reset link's one hour (see lib/invite.php) — a reset is
-- something you act on right away because you are locked out this minute; an
-- invite waits in an inbox until a new starter gets to onboarding admin, which
-- is not necessarily today.
--
-- invited_by names the administrator who pressed the button, same reasoning as
-- enrolments.enrolled_by: "why does this account exist" should have a name and
-- a date behind it, not an inference from created_at.
CREATE TABLE IF NOT EXISTS account_invites (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME         NULL,
  invited_by INT UNSIGNED     NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invite_token (token_hash),
  KEY ix_invite_user (tenant_id, user_id),
  KEY ix_invite_expires (expires_at),
  CONSTRAINT fk_invite_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id),
  CONSTRAINT fk_invite_user    FOREIGN KEY (user_id)    REFERENCES users (id),
  CONSTRAINT fk_invite_by      FOREIGN KEY (invited_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Who is on which course.
--
-- A registration is an expression of interest; an enrolment is a decision. They
-- are separate rows because they answer different questions and expire on
-- different clocks: a registration that goes nowhere is deleted after twelve
-- months, while an enrolment is the start of a learner record the QCTO obliges
-- us to keep.
--
-- The row is created by an administrator pressing "Enrol" on the registrations
-- list, which is also the moment the learner's account is created. There is no
-- self-service route to this table, deliberately: the site is on a public URL
-- and we have no working email verification, so "anyone who can type an address
-- can create an account" would be the whole access control.
--
-- enrolled_by names the administrator who did it. When somebody asks in a year
-- why a person has access to material, the answer needs to be a name and a
-- date, not an inference from created_at.
CREATE TABLE IF NOT EXISTS enrolments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  course_slug     VARCHAR(60)  NOT NULL,
  course_title    VARCHAR(190)     NULL,
  registration_id INT UNSIGNED     NULL,
  status          VARCHAR(20)  NOT NULL DEFAULT 'active',
  enrolled_at     DATETIME     NOT NULL,
  enrolled_by     INT UNSIGNED     NULL,
  completed_at    DATETIME         NULL,
  PRIMARY KEY (id),
  -- One enrolment per person per course. Pressing "Enrol" twice is a slip, not
  -- a second enrolment, and the database says so rather than the application
  -- remembering to.
  UNIQUE KEY uq_enrol_user_course (tenant_id, user_id, course_slug),
  KEY ix_enrol_tenant_status (tenant_id, status),
  CONSTRAINT fk_enrol_tenant FOREIGN KEY (tenant_id)       REFERENCES tenants (id),
  CONSTRAINT fk_enrol_user   FOREIGN KEY (user_id)         REFERENCES users (id),
  CONSTRAINT fk_enrol_reg    FOREIGN KEY (registration_id) REFERENCES registrations (id),
  CONSTRAINT fk_enrol_by     FOREIGN KEY (enrolled_by)     REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- A learner's ticked-off topics, one row per tick.
--
-- This replaces localStorage, which is where progress lived while there was no
-- backend. That was defensible on static hosting and is not defensible now: it
-- was tied to one browser on one device, it vanished when somebody cleared
-- their history, and on a shared site machine the next learner saw the previous
-- learner's record. None of those are bugs in the old code — they are what
-- browser storage is — and all three are why accounts exist.
--
-- A row means "done". Un-ticking DELETES the row rather than setting a flag,
-- because a tick a learner has taken back is not information we have a purpose
-- for holding. POPIA's minimality condition cuts both ways: it is also about
-- not keeping what has stopped being needed.
--
-- item_code is the topic ('KM-01-KT01'). The empty string is not a missing
-- value — it is the module-level row, meaning the learner marked the whole
-- module complete. It is '' rather than NULL because MySQL and SQLite both
-- allow duplicate NULLs through a UNIQUE index, so a nullable column here would
-- silently stop the uniqueness constraint from doing its job.
--
-- No purge_after, for the same reason progress_reports has none: this is part
-- of the learner record against an accredited qualification.
CREATE TABLE IF NOT EXISTS learner_progress (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  course_slug  VARCHAR(60)  NOT NULL,
  module_code  VARCHAR(20)  NOT NULL,
  item_code    VARCHAR(40)  NOT NULL DEFAULT '',
  completed_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lprog_item (tenant_id, user_id, course_slug, module_code, item_code),
  KEY ix_lprog_user (tenant_id, user_id),
  CONSTRAINT fk_lprog_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_lprog_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Learner progress reports — what pm-progress.html used to email to FormSubmit.
--
-- Same third-party problem as registrations, and arguably worse: a progress
-- report says how far behind somebody is, which is exactly the sort of thing an
-- employee would not expect to be sitting on a service outside the country.
--
-- No purge_after value is set on these rows, deliberately and not by oversight.
-- A progress report against an accredited qualification is part of the learner
-- record the QCTO obliges us to retain, so it does not expire on the twelve-month
-- rule that registrations use. The exact period the QCTO requires is still
-- outstanding — it is one of the three items marked "to confirm" on the privacy
-- notice, and this column is where the answer gets applied once we have it.
CREATE TABLE IF NOT EXISTS progress_reports (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED     NULL,
  full_name     VARCHAR(160) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  employee_no   VARCHAR(40)      NULL,
  line_manager  VARCHAR(160)     NULL,
  qualification VARCHAR(190)     NULL,
  summary       VARCHAR(500)     NULL,
  detail        TEXT             NULL,
  message       TEXT             NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'new',
  ip_hash       CHAR(64)         NULL,
  user_agent    VARCHAR(255)     NULL,
  created_at    DATETIME     NOT NULL,
  purge_after   DATE             NULL,
  PRIMARY KEY (id),
  KEY ix_prog_tenant_created (tenant_id, created_at),
  CONSTRAINT fk_prog_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_prog_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Where the learning material actually lives.
--
-- We hold a LINK, never a file. Centenary runs Google Workspace, so the guides,
-- workbooks and recordings sit in a Shared Drive and this table remembers which
-- URL belongs to which module. That split is deliberate: storage, virus
-- scanning, document viewing, video streaming and mobile apps are all solved
-- problems, and a training platform that reimplements them is a training
-- platform that falls over.
--
-- WHAT THIS TABLE DOES NOT DO, and it matters: a Drive link shared as "anyone
-- with the link" is a bearer token. Holding the URL is holding the file. The
-- academy controls who is GIVEN a link — sign-in, enrolment, and an audit row
-- per open — but it cannot stop a learner forwarding one. That is an acceptable
-- trade for a learner guide. It is not acceptable for assessment material, and
-- summative assessments, marking memos and facilitator guides must never be
-- entered here.
--
-- Scoped by tenant like everything else. Four academies may point at four
-- different Drive folders, and one company's material is not another's.
--
-- kind is 'guide', 'workbook' or 'video'. Constrained in PHP rather than by an
-- ENUM so that adding a fourth kind is a code change with a migration behind it
-- rather than an ALTER TABLE nobody can run on hosting with no shell.
CREATE TABLE IF NOT EXISTS materials (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   INT UNSIGNED NOT NULL,
  course_slug VARCHAR(60)  NOT NULL,
  module_code VARCHAR(20)  NOT NULL,
  kind        VARCHAR(20)  NOT NULL,
  url         VARCHAR(500) NOT NULL,
  label       VARCHAR(190)     NULL,
  updated_at  DATETIME     NOT NULL,
  updated_by  INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  -- One link per kind per module per course per tenant. Pasting a second guide
  -- for KM-01 replaces the first rather than quietly giving learners two.
  UNIQUE KEY uq_material_slot (tenant_id, course_slug, module_code, kind),
  KEY ix_material_course (tenant_id, course_slug),
  CONSTRAINT fk_material_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_material_user   FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- The file-backed twin of `materials`, for when there is no Drive/SharePoint
-- link to point at — an actual PDF, workbook or video the academy holds.
--
-- Kept as its OWN table rather than columns added to `materials`, and that is
-- worth explaining rather than assuming. The comment above `materials` says
-- "we hold a LINK, never a file" and gives real reasons: storage, virus
-- scanning, document viewing, video streaming and mobile apps are solved
-- problems. That reasoning is still correct for the link path, and nothing
-- here contradicts it — `materials` keeps meaning exactly what it always
-- meant. File storage is a deliberate, explained departure for the specific
-- material that has no Drive link to hold instead, so it gets its own table
-- rather than a nullable branch bolted onto an existing one. Same reasoning
-- that kept `account_invites` apart from `password_resets`.
--
-- One file per (course, module, kind), same rule as `materials`, and the two
-- are mutually exclusive per slot — enforced in PHP (lib/material_files.php),
-- not here, because "at most one of these two rows may exist" is not
-- something two independent UNIQUE constraints can express.
--
-- disk_name is a random token with NO extension, and the bytes themselves live
-- outside the web root entirely (app_private_dir() in lib/bootstrap.php) —
-- belt and braces against the file ever being requested directly, let alone
-- executed as anything. mime_type is the CANONICAL value the upload was
-- validated against, not whatever the browser claimed or a later re-sniff;
-- lib/material_files.php never trusts either of those to set a header.
CREATE TABLE IF NOT EXISTS material_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  course_slug   VARCHAR(60)  NOT NULL,
  module_code   VARCHAR(20)  NOT NULL,
  kind          VARCHAR(20)  NOT NULL,
  disk_name     VARCHAR(64)  NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(127) NOT NULL,
  size_bytes    BIGINT UNSIGNED NOT NULL,
  updated_at    DATETIME     NOT NULL,
  updated_by    INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_matfile_slot (tenant_id, course_slug, module_code, kind),
  KEY ix_matfile_course (tenant_id, course_slug),
  CONSTRAINT fk_matfile_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_matfile_user   FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- A self-check quiz for one module. ONE per (course, module) — narrower than
-- "many quizzes per module", matching what was actually asked for; loosening
-- it later (a title/slug column, drop the unique) is a small, contained
-- change if it's ever needed.
--
-- pass_pct is nullable on purpose: a quiz can just show a score with no
-- pass/fail line, which is the honest default until the academy decides a
-- module actually needs a pass mark.
--
-- THIS IS NOT THE QCTO ASSESSMENT, and nothing that reads from this table may
-- imply otherwise — see the disclaimer text repeated verbatim on quiz.php,
-- admin-quizzes.php and my.php. Competence is Centenary's decision after the
-- real assessment; the qualification is the QCTO's after the EISA. A quiz
-- here is the academy's own self-check, built to help someone study.
CREATE TABLE IF NOT EXISTS quizzes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    INT UNSIGNED NOT NULL,
  course_slug  VARCHAR(60)  NOT NULL,
  module_code  VARCHAR(20)  NOT NULL,
  pass_pct     TINYINT UNSIGNED NULL,
  published    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,
  updated_by   INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_quiz_slot (tenant_id, course_slug, module_code),
  CONSTRAINT fk_quiz_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_quiz_user   FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One multiple-choice question. sort_order is a plain integer an admin can
-- reorder in the authoring UI — not inferred from insertion order, which
-- would make "move this question up" impossible to express cleanly.
--
-- active, not deleted, when an admin removes a question from the form. Same
-- reasoning admin-users.php already uses for a learner who has left: progress
-- and enrolments hang off a user, so the account is switched off rather than
-- deleted. Here it is quiz_attempt_answers that hangs off a question — a
-- learner's already-graded attempt keeps pointing at the exact question and
-- choice they were graded against, and a hard DELETE would either break that
-- foreign key outright (which is what happens the moment anyone has attempted
-- the quiz) or silently rewrite history. quiz_questions_with_choices() and
-- quiz_question_count() only ever see active = 1; a removed question simply
-- stops appearing anywhere live while every past attempt still reads true.
CREATE TABLE IF NOT EXISTS quiz_questions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    INT UNSIGNED NOT NULL,
  quiz_id      INT UNSIGNED NOT NULL,
  prompt       TEXT         NOT NULL,
  sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_quizq_quiz (tenant_id, quiz_id, sort_order),
  CONSTRAINT fk_quizq_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_quizq_quiz   FOREIGN KEY (quiz_id)   REFERENCES quizzes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One answer option. is_correct is validated in PHP to have exactly one true
-- value per question before a quiz can be published — the column itself
-- allows any number, same trade the rest of this codebase makes throughout
-- (e.g. `kind` on `materials` is a plain VARCHAR, not an ENUM) in favour of a
-- change that needs no migration nobody can run on hosting with no shell.
--
-- active carries the same reasoning as quiz_questions.active, for the same
-- reason: quiz_attempt_answers.choice_id points at a specific row here, and a
-- removed choice is switched off rather than deleted so a past attempt that
-- picked it keeps a valid, readable answer.
CREATE TABLE IF NOT EXISTS quiz_choices (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    INT UNSIGNED NOT NULL,
  question_id  INT UNSIGNED NOT NULL,
  choice_text  VARCHAR(500) NOT NULL,
  is_correct   TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_quizc_question (tenant_id, question_id, sort_order),
  CONSTRAINT fk_quizc_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id),
  CONSTRAINT fk_quizc_question FOREIGN KEY (question_id) REFERENCES quiz_questions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One attempt at a quiz. Unlimited attempts are allowed and the best is kept,
-- so there is deliberately no UNIQUE on (quiz_id, user_id) here.
--
-- question_count is a SNAPSHOT of how many questions the quiz had at the
-- moment of this attempt, not a live count. That is what keeps an old
-- attempt's score honest after an admin later adds or removes a question —
-- without it, "8 out of 8" from before an edit would silently start reading
-- against a quiz that now has ten.
--
-- NO score_pct COLUMN, and that is deliberate, not an oversight: the
-- percentage is score_count/question_count, computed in PHP at read time —
-- see the "DELIBERATELY NO PERCENTAGE" reasoning already established for
-- learner_progress in lib/learner.php, carried through to its logical
-- conclusion here. Computing "best score" therefore compares PERCENTAGES in
-- PHP, never MAX(score_count) in SQL — two attempts can legitimately have
-- different question_count values, and comparing raw counts across those
-- would be wrong.
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NOT NULL,
  quiz_id        INT UNSIGNED NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  started_at     DATETIME     NOT NULL,
  submitted_at   DATETIME     NOT NULL,
  score_count    INT UNSIGNED NOT NULL,
  question_count INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY ix_qatt_quiz_user (tenant_id, quiz_id, user_id),
  KEY ix_qatt_user (tenant_id, user_id),
  CONSTRAINT fk_qatt_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_qatt_quiz   FOREIGN KEY (quiz_id)   REFERENCES quizzes (id),
  CONSTRAINT fk_qatt_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One question's worth of one attempt. choice_id NULL means the learner left
-- it unanswered — that is scored as wrong (is_correct = 0) and still counts
-- toward question_count; it does not shrink the denominator the way simply
-- omitting a row would. is_correct is a SNAPSHOT graded against the answer
-- key at the moment of the attempt, for the same reason question_count is: it
-- must keep reading true even if an admin later changes which choice is
-- correct.
CREATE TABLE IF NOT EXISTS quiz_attempt_answers (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    INT UNSIGNED NOT NULL,
  attempt_id   INT UNSIGNED NOT NULL,
  question_id  INT UNSIGNED NOT NULL,
  choice_id    INT UNSIGNED     NULL,
  is_correct   TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qans_attempt_question (tenant_id, attempt_id, question_id),
  CONSTRAINT fk_qans_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id),
  CONSTRAINT fk_qans_attempt  FOREIGN KEY (attempt_id)  REFERENCES quiz_attempts (id),
  CONSTRAINT fk_qans_question FOREIGN KEY (question_id) REFERENCES quiz_questions (id),
  CONSTRAINT fk_qans_choice   FOREIGN KEY (choice_id)   REFERENCES quiz_choices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Written teaching content for ONE AREA of one topic — the "read it here"
-- half of a module, as opposed to `materials`/`material_files`, which hand
-- over a whole document.
--
-- WHY THIS IS NOT IN pm-modules.js
--
-- That file already carries the study structure (modules, topics, and the
-- `covers` list this table attaches to) and its header says plainly what it
-- deliberately leaves out: "the full teaching prose... the learner guides are
-- Centenary's material and the downloads are access-controlled". pm-modules.js
-- is served to anybody who opens the site. Teaching content put there would be
-- published to the world the moment it was saved, which is the exact mistake
-- the DOCS map made before links moved into the database. So the prose lives
-- here and reaches a learner only through lessons.php, behind the same
-- signed-in-and-enrolled check materials.php applies.
--
-- KEYED BY BOTH INDEX AND TITLE, on purpose. area_index is the position in
-- that topic's `covers` array, which is what the page renders against.
-- area_title is a SNAPSHOT of the heading as it read when the content was
-- written — it is not used for lookup, it is there so that if the registered
-- curriculum is ever revised, a mismatch between the two is visible rather
-- than silently attaching last year's prose to a different heading.
CREATE TABLE IF NOT EXISTS topic_sections (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   INT UNSIGNED NOT NULL,
  course_slug VARCHAR(60)  NOT NULL,
  module_code VARCHAR(20)  NOT NULL,
  topic_code  VARCHAR(30)  NOT NULL,
  area_index  TINYINT UNSIGNED NOT NULL,
  area_title  VARCHAR(255) NOT NULL,
  body        MEDIUMTEXT   NOT NULL,
  published   TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at  DATETIME     NOT NULL,
  updated_by  INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_section_area (tenant_id, course_slug, module_code, topic_code, area_index),
  KEY ix_section_module (tenant_id, course_slug, module_code),
  CONSTRAINT fk_section_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_section_user   FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Which letters have already gone to which learner.
 *
 * The point of this table is that a learner is never sent the same letter
 * twice. Quizzes carry unlimited attempts, so "email the module result" has to
 * mean "email it once" — without a record of the send, every later retake in a
 * finished module would post another copy of the same report.
 *
 * It is the record of an EVENT, not a queue: a row exists because a send was
 * attempted, and `delivered` says how that went. A failed send is still
 * recorded, with delivered = 0, so an admin can see that a learner was owed a
 * letter that never left. It is deliberately not retried automatically —
 * mail() failing usually means the server or DNS is wrong, and a retry loop
 * against a broken SPF record just multiplies the spam-folder copies.
 */
CREATE TABLE IF NOT EXISTS letters_sent (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  kind       VARCHAR(30)  NOT NULL,   -- 'welcome' | 'module_results'
  ref        VARCHAR(90)  NOT NULL,   -- course slug, or 'course-slug/KM-04'
  delivered  TINYINT(1)   NOT NULL DEFAULT 0,
  sent_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_letter_once (tenant_id, user_id, kind, ref),
  KEY ix_letter_user (tenant_id, user_id),
  CONSTRAINT fk_letter_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_letter_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Which courses a trainer may see.
--
-- Added 10 Sep 2026 with the trainer role. A trainer account on its own grants
-- nothing: rights here are additive from zero, so an account with no rows in
-- this table sees no courses and no learners. That is the safe direction, and
-- it is also what a brand-new trainer account should look like until somebody
-- decides what they teach.
--
-- Nothing joins this to the published trainer list in trainers.js, on purpose.
-- That file says who may be NAMED on a public page; this table says who may
-- SIGN IN and look at a cohort. A person can be either without being both, and
-- conflating them would mean publishing somebody to give them a login.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trainer_courses (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  course_slug VARCHAR(60)  NOT NULL,
  created_at  DATETIME     NOT NULL,
  created_by  INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_traincourse (tenant_id, user_id, course_slug),
  KEY ix_traincourse_user (tenant_id, user_id),
  CONSTRAINT fk_traincourse_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_traincourse_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- In-person classes, and the register a facilitator marks.
--
-- Added 11 Sep 2026 at Tarryn's request. The academy is not only online: a
-- course can be delivered in a room, and the room needs a register.
--
-- TWO TABLES, NOT ONE. A class exists whether or not anybody has been marked
-- yet, and an unmarked class is a real thing — it is a class that has been
-- scheduled and not run. Storing attendance on the class row would have meant
-- either inventing a row per learner up front, or having no way to tell "not
-- marked" from "absent". Those are very different facts about a person.
--
-- ATTENDANCE IS NOT ENROLMENT. The register lists whoever is enrolled on the
-- course at the time it is opened; marking somebody absent does not touch
-- their enrolment, and un-enrolling somebody does not erase that they were in
-- the room on the day. The two questions are asked by different people for
-- different reasons, and a B-BBEE audit needs the second one to stay true.
--
-- WHY marked_by IS RECORDED. A register is evidence. "Present" with nobody's
-- name against it is worth much less than "present, marked by T. Norris at
-- 14:12" — the whole point of a facilitator marking it is that a named person
-- is saying so.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS classes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  course_slug   VARCHAR(60)  NOT NULL,
  title         VARCHAR(160) NOT NULL,
  venue         VARCHAR(160)     NULL,
  held_on       DATE         NOT NULL,
  starts_at     VARCHAR(5)       NULL,   -- "09:00", local time, as typed
  ends_at       VARCHAR(5)       NULL,
  facilitator_id INT UNSIGNED    NULL,   -- the trainer who marks it
  notes         TEXT             NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'scheduled',  -- scheduled | held | cancelled
  created_at    DATETIME     NOT NULL,
  created_by    INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  KEY ix_class_course (tenant_id, course_slug, held_on),
  KEY ix_class_facil (tenant_id, facilitator_id),
  CONSTRAINT fk_class_tenant FOREIGN KEY (tenant_id)      REFERENCES tenants (id),
  CONSTRAINT fk_class_facil  FOREIGN KEY (facilitator_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_attendance (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  INT UNSIGNED NOT NULL,
  class_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  -- present | absent | late | excused. No "unknown": a learner with no row
  -- here has not been marked, which the register shows as exactly that.
  status     VARCHAR(12)  NOT NULL,
  note       VARCHAR(200)     NULL,
  marked_at  DATETIME     NOT NULL,
  marked_by  INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attend_once (tenant_id, class_id, user_id),
  KEY ix_attend_user (tenant_id, user_id),
  CONSTRAINT fk_attend_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_attend_class  FOREIGN KEY (class_id)  REFERENCES classes (id),
  CONSTRAINT fk_attend_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
