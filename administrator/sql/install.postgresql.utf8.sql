CREATE TABLE IF NOT EXISTS "#__zabbix_settings" (
  "name" varchar(100) NOT NULL,
  "value" text NOT NULL,
  PRIMARY KEY ("name")
);

CREATE TABLE IF NOT EXISTS "#__zabbix_usermap" (
  "joomla_user_id" bigint NOT NULL,
  "zabbix_userid" varchar(20) DEFAULT NULL,
  "zabbix_username" varchar(100) DEFAULT NULL,
  "email" varchar(320) NOT NULL DEFAULT '',
  "status" varchar(20) NOT NULL,
  "source" varchar(10) NOT NULL DEFAULT 'auto',
  "matched_at" timestamp without time zone DEFAULT NULL,
  "checked_at" timestamp without time zone NOT NULL,
  "modified_by" bigint NOT NULL DEFAULT 0,
  PRIMARY KEY ("joomla_user_id")
);
CREATE INDEX IF NOT EXISTS "#__zabbix_usermap_idx_zabbix_userid" ON "#__zabbix_usermap" ("zabbix_userid");
CREATE INDEX IF NOT EXISTS "#__zabbix_usermap_idx_status" ON "#__zabbix_usermap" ("status");

CREATE TABLE IF NOT EXISTS "#__zabbix_cache" (
  "cache_key" varchar(191) NOT NULL,
  "user_id" bigint NOT NULL DEFAULT 0,
  "expires" bigint NOT NULL,
  "value" text NOT NULL,
  PRIMARY KEY ("cache_key")
);
CREATE INDEX IF NOT EXISTS "#__zabbix_cache_idx_user_id" ON "#__zabbix_cache" ("user_id");
CREATE INDEX IF NOT EXISTS "#__zabbix_cache_idx_expires" ON "#__zabbix_cache" ("expires");

CREATE TABLE IF NOT EXISTS "#__zabbix_audit" (
  "id" serial NOT NULL,
  "user_id" bigint NOT NULL DEFAULT 0,
  "actor_id" bigint NOT NULL DEFAULT 0,
  "action" varchar(50) NOT NULL,
  "result" varchar(20) NOT NULL,
  "detail" varchar(255) NOT NULL DEFAULT '',
  "created" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__zabbix_audit_idx_user_action_created" ON "#__zabbix_audit" ("user_id", "action", "created");
CREATE INDEX IF NOT EXISTS "#__zabbix_audit_idx_action_created" ON "#__zabbix_audit" ("action", "created");
