CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "role" varchar not null default 'admin'
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_expiration_index" on "cache"("expiration");
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_locks_expiration_index" on "cache_locks"("expiration");
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "rental_outfit"(
  "rental_id" integer not null,
  "outfit_id" integer not null,
  "discount" numeric,
  "discount_type" varchar not null default 'fixed',
  "price_incl_tax" numeric,
  "price_excl_tax" numeric,
  "tax_amount" numeric,
  "discount_note" varchar,
  foreign key("rental_id") references "rentals"("id") on delete cascade,
  foreign key("outfit_id") references "outfits"("id") on delete cascade,
  primary key("rental_id", "outfit_id")
);
CREATE TABLE IF NOT EXISTS "agent_conversations"(
  "id" varchar not null,
  "user_id" integer,
  "title" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE INDEX "agent_conversations_user_id_updated_at_index" on "agent_conversations"(
  "user_id",
  "updated_at"
);
CREATE TABLE IF NOT EXISTS "agent_conversation_messages"(
  "id" varchar not null,
  "conversation_id" varchar not null,
  "user_id" integer,
  "agent" varchar not null,
  "role" varchar not null,
  "content" text not null,
  "attachments" text not null,
  "tool_calls" text not null,
  "tool_results" text not null,
  "usage" text not null,
  "meta" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE INDEX "conversation_index" on "agent_conversation_messages"(
  "conversation_id",
  "user_id",
  "updated_at"
);
CREATE INDEX "agent_conversation_messages_user_id_index" on "agent_conversation_messages"(
  "user_id"
);
CREATE INDEX "agent_conversation_messages_conversation_id_index" on "agent_conversation_messages"(
  "conversation_id"
);
CREATE TABLE IF NOT EXISTS "stores"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "code" varchar not null,
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "stores_code_unique" on "stores"("code");
CREATE TABLE IF NOT EXISTS "store_user"(
  "id" integer primary key autoincrement not null,
  "store_id" integer not null,
  "user_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("store_id") references "stores"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "store_user_store_id_user_id_unique" on "store_user"(
  "store_id",
  "user_id"
);
CREATE TABLE IF NOT EXISTS "outfits"(
  "id" integer primary key autoincrement not null,
  "code" varchar,
  "description" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "new_code" varchar,
  "price" numeric,
  "stock" integer not null default('1'),
  "qr_token" varchar,
  "qr_redirect_url" text,
  "store_id" integer,
  "is_tutorial" tinyint(1) not null default '0',
  foreign key("store_id") references "stores"("id") on delete restrict
);
CREATE UNIQUE INDEX "outfits_qr_token_unique" on "outfits"("qr_token");
CREATE INDEX "outfits_store_id_index" on "outfits"("store_id");
CREATE TABLE IF NOT EXISTS "shift_sessions"(
  "id" integer primary key autoincrement not null,
  "opened_by_user_id" integer not null,
  "closed_by_user_id" integer,
  "opened_at" datetime not null,
  "closed_at" datetime,
  "opening_note" text,
  "closing_note" text,
  "total_transactions" integer not null default('0'),
  "total_revenue" numeric not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  "period" varchar not null default('morning'),
  "store_id" integer,
  foreign key("closed_by_user_id") references users("id") on delete set null on update no action,
  foreign key("opened_by_user_id") references users("id") on delete cascade on update no action,
  foreign key("store_id") references "stores"("id") on delete restrict
);
CREATE INDEX "shift_sessions_store_id_index" on "shift_sessions"("store_id");
CREATE TABLE IF NOT EXISTS "invoices"(
  "id" integer primary key autoincrement not null,
  "rental_id" integer not null,
  "pdf_path" varchar,
  "png_path" varchar,
  "generated_at" datetime,
  "sent_at" datetime,
  "status" varchar not null default('generated'),
  "created_at" datetime,
  "updated_at" datetime,
  "store_id" integer,
  foreign key("rental_id") references rentals("id") on delete cascade on update no action,
  foreign key("store_id") references "stores"("id") on delete restrict
);
CREATE INDEX "invoices_rental_id_index" on "invoices"("rental_id");
CREATE INDEX "invoices_store_id_index" on "invoices"("store_id");
CREATE UNIQUE INDEX "outfits_store_id_code_unique" on "outfits"(
  "store_id",
  "code"
);
CREATE TABLE IF NOT EXISTS "store_settings"(
  "id" integer primary key autoincrement not null,
  "store_id" integer,
  "key" varchar not null,
  "value" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("store_id") references "stores"("id") on delete cascade
);
CREATE UNIQUE INDEX "store_settings_store_id_key_unique" on "store_settings"(
  "store_id",
  "key"
);
CREATE TABLE IF NOT EXISTS "rental_reminders"(
  "id" integer primary key autoincrement not null,
  "rental_id" integer not null,
  "kind" varchar not null,
  "target" varchar,
  "sent_at" datetime,
  "status" varchar not null default 'pending',
  "meta" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("rental_id") references "rentals"("id") on delete cascade
);
CREATE INDEX "rental_reminders_rental_id_index" on "rental_reminders"(
  "rental_id"
);
CREATE INDEX "rental_reminders_kind_index" on "rental_reminders"("kind");
CREATE INDEX "rental_reminders_target_index" on "rental_reminders"("target");
CREATE INDEX "rental_reminders_sent_at_index" on "rental_reminders"("sent_at");
CREATE INDEX "rental_reminders_status_index" on "rental_reminders"("status");
CREATE TABLE IF NOT EXISTS "security_events"(
  "id" integer primary key autoincrement not null,
  "store_id" integer,
  "user_id" integer,
  "kind" varchar not null,
  "severity" varchar not null default 'low',
  "ip" varchar,
  "method" varchar,
  "path" varchar,
  "query" text,
  "user_agent" varchar,
  "status_code" integer,
  "meta" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "security_events_store_id_index" on "security_events"("store_id");
CREATE INDEX "security_events_user_id_index" on "security_events"("user_id");
CREATE INDEX "security_events_kind_index" on "security_events"("kind");
CREATE INDEX "security_events_severity_index" on "security_events"("severity");
CREATE INDEX "security_events_ip_index" on "security_events"("ip");
CREATE TABLE IF NOT EXISTS "csp_reports"(
  "id" integer primary key autoincrement not null,
  "store_id" integer,
  "user_id" integer,
  "ip" varchar,
  "user_agent" varchar,
  "document_uri" varchar,
  "referrer" varchar,
  "blocked_uri" varchar,
  "violated_directive" varchar,
  "effective_directive" varchar,
  "original_policy" varchar,
  "disposition" varchar,
  "status_code" integer,
  "raw" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "csp_reports_store_id_index" on "csp_reports"("store_id");
CREATE INDEX "csp_reports_user_id_index" on "csp_reports"("user_id");
CREATE INDEX "csp_reports_ip_index" on "csp_reports"("ip");
CREATE TABLE IF NOT EXISTS "rental_payment_records"(
  "id" integer primary key autoincrement not null,
  "rental_id" integer not null,
  "shift_session_id" integer,
  "received_by_user_id" integer,
  "amount" numeric not null,
  "payment_method" varchar,
  "payment_kind" varchar,
  "recorded_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("rental_id") references "rentals"("id") on delete cascade,
  foreign key("shift_session_id") references "shift_sessions"("id") on delete set null,
  foreign key("received_by_user_id") references "users"("id") on delete set null
);
CREATE INDEX "rental_payment_records_shift_session_id_recorded_at_index" on "rental_payment_records"(
  "shift_session_id",
  "recorded_at"
);
CREATE INDEX "rental_payment_records_rental_id_recorded_at_index" on "rental_payment_records"(
  "rental_id",
  "recorded_at"
);
CREATE TABLE IF NOT EXISTS "discount_checks"(
  "id" integer primary key autoincrement not null,
  "rental_id" integer not null,
  "checked_by_user_id" integer not null,
  "checked_at" datetime not null,
  "note" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("rental_id") references "rentals"("id") on delete cascade,
  foreign key("checked_by_user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "discount_checks_rental_id_unique" on "discount_checks"(
  "rental_id"
);
CREATE TABLE IF NOT EXISTS "rental_approval_requests"(
  "id" integer primary key autoincrement not null,
  "requested_by_user_id" integer not null,
  "decided_by_user_id" integer,
  "source_shift_session_id" integer,
  "payload" text not null,
  "status" varchar not null default 'pending',
  "decision_note" text,
  "decided_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "store_id" integer,
  "is_tutorial" tinyint(1) not null default '0',
  foreign key("store_id") references stores("id") on delete restrict on update no action,
  foreign key("requested_by_user_id") references users("id") on delete cascade on update no action,
  foreign key("decided_by_user_id") references users("id") on delete set null on update no action,
  foreign key("source_shift_session_id") references shift_sessions("id") on delete set null on update no action
);
CREATE INDEX "rental_approval_requests_store_id_index" on "rental_approval_requests"(
  "store_id"
);
CREATE TABLE IF NOT EXISTS "rentals"(
  "id" integer primary key autoincrement not null,
  "customer_name" varchar not null,
  "phone" varchar not null,
  "start_date" date not null,
  "end_date" date not null,
  "status" varchar not null default('active'),
  "created_at" datetime,
  "updated_at" datetime,
  "instagram" varchar,
  "discount" numeric,
  "discount_type" varchar not null default('fixed'),
  "picked_up_at" datetime,
  "payment_method" varchar,
  "handled_by_user_id" integer,
  "shift_session_id" integer,
  "store_id" integer,
  "amount_paid" numeric,
  "payment_kind" varchar,
  "overdue_base_end_date" date,
  "overdue_merged_at" datetime,
  "overdue_charge_through_date" date,
  "overdue_fee_rate" numeric,
  "overdue_fee_days" integer,
  "overdue_fee_amount" numeric,
  "overdue_fee_applied_at" datetime,
  "overdue_amount_paid" numeric,
  "overdue_payment_method" varchar,
  "overdue_payment_kind" varchar,
  "overdue_payment_received_by_user_id" integer,
  "overdue_payment_shift_session_id" integer,
  "overdue_paid_at" datetime,
  "overdue_change_amount" numeric,
  "notes" text,
  "discount_note" varchar,
  "change_amount" numeric,
  "extra_charge" numeric,
  "down_payment_amount" numeric,
  "deleted_at" datetime,
  "created_by_user_id" integer,
  "is_tutorial" tinyint(1) not null default '0',
  foreign key("store_id") references stores("id") on delete restrict on update no action,
  foreign key("handled_by_user_id") references users("id") on delete set null on update no action,
  foreign key("shift_session_id") references shift_sessions("id") on delete set null on update no action,
  foreign key("overdue_payment_received_by_user_id") references users("id") on delete set null on update no action,
  foreign key("overdue_payment_shift_session_id") references shift_sessions("id") on delete set null on update no action,
  foreign key("created_by_user_id") references "users"("id") on delete set null
);
CREATE INDEX "rentals_store_id_index" on "rentals"("store_id");
CREATE TABLE IF NOT EXISTS "rental_activity_logs"(
  "id" integer primary key autoincrement not null,
  "store_id" integer not null,
  "rental_id" integer,
  "user_id" integer,
  "action" varchar not null,
  "changes" text,
  "note" text,
  "created_at" datetime,
  "updated_at" datetime,
  "is_tutorial" tinyint(1) not null default '0',
  foreign key("user_id") references users("id") on delete set null on update no action,
  foreign key("store_id") references stores("id") on delete cascade on update no action,
  foreign key("rental_id") references "rentals"("id") on delete cascade
);
CREATE INDEX "rental_activity_logs_rental_id_created_at_index" on "rental_activity_logs"(
  "rental_id",
  "created_at"
);
CREATE INDEX "rental_activity_logs_store_id_created_at_index" on "rental_activity_logs"(
  "store_id",
  "created_at"
);
CREATE TABLE IF NOT EXISTS "tutorial_rental_approval_requests"(
  "id" integer primary key autoincrement not null,
  "store_id" integer,
  "requested_by_user_id" integer not null,
  "decided_by_user_id" integer,
  "source_shift_session_id" integer,
  "payload" text not null,
  "status" varchar not null default 'pending',
  "is_tutorial" tinyint(1) not null default '1',
  "decision_note" text,
  "decided_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("store_id") references "stores"("id"),
  foreign key("requested_by_user_id") references "users"("id") on delete cascade,
  foreign key("decided_by_user_id") references "users"("id") on delete set null,
  foreign key("source_shift_session_id") references "shift_sessions"("id") on delete set null
);
CREATE INDEX "tutorial_rental_approval_requests_store_id_index" on "tutorial_rental_approval_requests"(
  "store_id"
);
CREATE TABLE IF NOT EXISTS "tutorial_rental_activity_logs"(
  "id" integer primary key autoincrement not null,
  "store_id" integer,
  "rental_id" integer,
  "is_tutorial" tinyint(1) not null default '1',
  "user_id" integer,
  "action" varchar not null,
  "changes" text,
  "note" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("store_id") references "stores"("id") on delete cascade,
  foreign key("rental_id") references "tutorial_rentals"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE INDEX "tutorial_rental_activity_logs_rental_id_created_at_index" on "tutorial_rental_activity_logs"(
  "rental_id",
  "created_at"
);
CREATE INDEX "tutorial_rental_activity_logs_store_id_created_at_index" on "tutorial_rental_activity_logs"(
  "store_id",
  "created_at"
);
CREATE TABLE IF NOT EXISTS "tutorial_rental_outfit"(
  "rental_id" integer not null,
  "outfit_id" integer not null,
  "discount" numeric,
  "discount_type" varchar not null default 'fixed',
  "discount_note" varchar,
  "price_incl_tax" numeric,
  "price_excl_tax" numeric,
  "tax_amount" numeric,
  foreign key("rental_id") references "tutorial_rentals"("id") on delete cascade,
  foreign key("outfit_id") references "outfits"("id") on delete cascade,
  primary key("rental_id", "outfit_id")
);
CREATE TABLE IF NOT EXISTS "tutorial_rentals"(
  "id" integer primary key autoincrement not null,
  "store_id" integer,
  "customer_name" varchar not null,
  "phone" varchar not null,
  "instagram" varchar,
  "notes" text,
  "payment_method" varchar,
  "amount_paid" numeric,
  "down_payment_amount" numeric,
  "change_amount" numeric,
  "payment_kind" varchar,
  "handled_by_user_id" integer,
  "created_by_user_id" integer,
  "shift_session_id" integer,
  "discount" numeric,
  "discount_type" varchar not null default('fixed'),
  "discount_note" varchar,
  "extra_charge" numeric,
  "start_date" date not null,
  "end_date" date not null,
  "status" varchar not null default('active'),
  "is_tutorial" tinyint(1) not null default('1'),
  "picked_up_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "overdue_base_end_date" date,
  "overdue_merged_at" datetime,
  "overdue_charge_through_date" date,
  "overdue_fee_rate" numeric,
  "overdue_fee_days" integer,
  "overdue_fee_amount" numeric,
  "overdue_fee_applied_at" datetime,
  "overdue_amount_paid" numeric,
  "overdue_payment_method" varchar,
  "overdue_payment_kind" varchar,
  "overdue_payment_received_by_user_id" integer,
  "overdue_payment_shift_session_id" integer,
  "overdue_paid_at" datetime,
  "overdue_change_amount" numeric,
  "deleted_at" datetime,
  foreign key("store_id") references stores("id") on delete no action on update no action,
  foreign key("handled_by_user_id") references users("id") on delete set null on update no action,
  foreign key("created_by_user_id") references users("id") on delete set null on update no action,
  foreign key("overdue_payment_received_by_user_id") references users("id") on delete set null on update no action,
  foreign key("shift_session_id") references "tutorial_shift_sessions"("id") on delete set null,
  foreign key("overdue_payment_shift_session_id") references "tutorial_shift_sessions"("id") on delete set null
);
CREATE INDEX "tutorial_rentals_status_index" on "tutorial_rentals"("status");
CREATE INDEX "tutorial_rentals_store_id_index" on "tutorial_rentals"(
  "store_id"
);
CREATE TABLE IF NOT EXISTS "tutorial_shift_sessions"(
  "id" integer primary key autoincrement not null,
  "opened_by_user_id" integer not null,
  "closed_by_user_id" integer,
  "opened_at" datetime not null,
  "period" varchar not null default('morning'),
  "closed_at" datetime,
  "opening_note" text,
  "closing_note" text,
  "total_transactions" integer not null default('0'),
  "total_revenue" numeric not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  "store_id" integer,
  foreign key("closed_by_user_id") references users("id") on delete set null on update no action,
  foreign key("opened_by_user_id") references users("id") on delete cascade on update no action,
  foreign key("store_id") references "stores"("id") on delete set null
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2026_05_01_091007_add_role_to_users_table',1);
INSERT INTO migrations VALUES(5,'2026_05_01_091009_create_outfits_table',1);
INSERT INTO migrations VALUES(6,'2026_05_01_091010_create_rentals_table',1);
INSERT INTO migrations VALUES(7,'2026_05_02_052845_add_instagram_to_rentals_table',1);
INSERT INTO migrations VALUES(8,'2026_05_02_054050_create_rental_outfit_table',1);
INSERT INTO migrations VALUES(9,'2026_05_02_054050_migrate_outfit_id_to_pivot',1);
INSERT INTO migrations VALUES(10,'2026_05_02_065537_add_new_code_to_outfits_table',1);
INSERT INTO migrations VALUES(11,'2026_05_02_090104_add_price_to_outfits_table',1);
INSERT INTO migrations VALUES(12,'2026_05_02_092329_create_pins_table',1);
INSERT INTO migrations VALUES(13,'2026_05_02_092330_add_discount_and_pin_to_rentals_table',1);
INSERT INTO migrations VALUES(14,'2026_05_02_100726_add_discount_to_rental_outfit_table',1);
INSERT INTO migrations VALUES(15,'2026_05_02_101749_add_discount_type_to_rental_outfit_table',1);
INSERT INTO migrations VALUES(16,'2026_05_05_233000_add_stock_to_outfits_table',1);
INSERT INTO migrations VALUES(17,'2026_05_06_001000_create_invoices_table',1);
INSERT INTO migrations VALUES(18,'2026_05_06_231223_create_agent_conversations_table',1);
INSERT INTO migrations VALUES(19,'2026_05_07_000000_add_picked_up_at_to_rentals_table',1);
INSERT INTO migrations VALUES(20,'2026_05_09_020500_add_payment_method_to_rentals_table',1);
INSERT INTO migrations VALUES(21,'2026_05_09_021500_normalize_outfit_stock_default_to_one',1);
INSERT INTO migrations VALUES(22,'2026_05_09_031500_add_handled_by_user_id_to_rentals_table',1);
INSERT INTO migrations VALUES(23,'2026_05_09_032500_create_shift_sessions_table',1);
INSERT INTO migrations VALUES(24,'2026_05_09_114600_create_rental_approval_requests_table',1);
INSERT INTO migrations VALUES(25,'2026_05_09_120000_add_period_to_shift_sessions_table',1);
INSERT INTO migrations VALUES(26,'2026_05_10_120000_add_qr_token_and_redirect_to_outfits_table',1);
INSERT INTO migrations VALUES(27,'2026_05_10_150000_create_stores_and_scope_data',1);
INSERT INTO migrations VALUES(28,'2026_05_10_200000_add_amount_paid_to_rentals_table',1);
INSERT INTO migrations VALUES(29,'2026_05_11_010000_add_payment_kind_to_rentals_table',1);
INSERT INTO migrations VALUES(30,'2026_05_14_120000_create_store_settings_table',1);
INSERT INTO migrations VALUES(31,'2026_05_17_150000_create_rental_reminders_table',1);
INSERT INTO migrations VALUES(32,'2026_05_17_190000_add_overdue_merge_fields_to_rentals_table',1);
INSERT INTO migrations VALUES(33,'2026_05_17_200000_add_overdue_charge_through_date_to_rentals_table',1);
INSERT INTO migrations VALUES(34,'2026_05_17_210000_add_overdue_fee_fields_to_rentals_table',1);
INSERT INTO migrations VALUES(35,'2026_05_17_220000_create_security_events_table',1);
INSERT INTO migrations VALUES(36,'2026_05_17_220100_create_csp_reports_table',1);
INSERT INTO migrations VALUES(37,'2026_05_19_120000_add_overdue_payment_fields_to_rentals_table',1);
INSERT INTO migrations VALUES(38,'2026_05_19_130000_add_overdue_change_amount_to_rentals_table',1);
INSERT INTO migrations VALUES(39,'2026_06_07_000000_move_rental_address_into_notes',1);
INSERT INTO migrations VALUES(40,'2026_06_07_000001_add_tax_price_columns_to_rental_outfit_table',1);
INSERT INTO migrations VALUES(41,'2026_06_16_000000_create_rental_payment_records_table',1);
INSERT INTO migrations VALUES(42,'2026_06_18_000000_add_discount_note_to_rentals_table',1);
INSERT INTO migrations VALUES(43,'2026_06_21_120000_add_change_amount_to_rentals_table',1);
INSERT INTO migrations VALUES(44,'2026_06_23_000000_add_extra_charge_to_rentals_table',1);
INSERT INTO migrations VALUES(45,'2026_06_28_000001_add_extra_charge_to_rentals_table',1);
INSERT INTO migrations VALUES(46,'2026_06_28_000002_add_discount_note_to_rentals_table',1);
INSERT INTO migrations VALUES(47,'2026_06_28_000003_add_discount_note_to_rental_outfit_table',1);
INSERT INTO migrations VALUES(48,'2026_06_29_000001_add_down_payment_amount_to_rentals_table',1);
INSERT INTO migrations VALUES(49,'2026_06_29_081916_create_discount_checks_table',1);
INSERT INTO migrations VALUES(50,'2026_07_09_000000_remove_pin_mechanism',1);
INSERT INTO migrations VALUES(51,'2026_07_09_100000_add_soft_deletes_to_rentals_table',1);
INSERT INTO migrations VALUES(52,'2026_07_11_000000_add_cancelled_status_to_rental_approval_requests',1);
INSERT INTO migrations VALUES(53,'2026_07_14_000000_add_created_by_user_id_to_rentals',1);
INSERT INTO migrations VALUES(54,'2026_07_26_171127_create_rental_activity_logs_table',1);
INSERT INTO migrations VALUES(55,'2026_07_28_085842_make_rental_activity_logs_rental_id_nullable',1);
INSERT INTO migrations VALUES(56,'2026_08_02_185107_add_is_tutorial_to_rentals_and_approval_requests_tables',1);
INSERT INTO migrations VALUES(57,'2026_08_02_192810_add_is_tutorial_to_outfits_table',1);
INSERT INTO migrations VALUES(58,'2026_08_02_202357_add_is_tutorial_to_rental_activity_logs_table',1);
INSERT INTO migrations VALUES(59,'2026_08_03_081620_create_tutorial_rentals_table',1);
INSERT INTO migrations VALUES(60,'2026_08_03_081621_create_tutorial_rental_approval_requests_table',1);
INSERT INTO migrations VALUES(61,'2026_08_03_081622_create_tutorial_rental_activity_logs_table',1);
INSERT INTO migrations VALUES(62,'2026_08_03_081623_create_tutorial_rental_outfit_table',1);
INSERT INTO migrations VALUES(63,'2026_08_05_210315_create_tutorial_shift_sessions_table',1);
INSERT INTO migrations VALUES(64,'2026_08_05_210354_repoint_tutorial_rentals_shift_session_fk_to_tutorial_shift_sessions',1);
INSERT INTO migrations VALUES(65,'2026_08_05_212622_add_store_id_to_tutorial_shift_sessions_table',1);
