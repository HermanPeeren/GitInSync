CREATE TABLE IF NOT EXISTS "#__gitinsync_items" (
  "id" serial NOT NULL,
  "type" varchar(32) NOT NULL,
  "item_id" integer NOT NULL,
  "uuid" char(36) NOT NULL,
  "dirty" smallint NOT NULL DEFAULT 0,
  "excluded" smallint NOT NULL DEFAULT 0,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__gitinsync_items_idx_type_item" UNIQUE ("type", "item_id"),
  CONSTRAINT "#__gitinsync_items_idx_type_uuid" UNIQUE ("type", "uuid")
);
CREATE INDEX IF NOT EXISTS "#__gitinsync_items_idx_dirty" ON "#__gitinsync_items" ("dirty");

CREATE TABLE IF NOT EXISTS "#__gitinsync_state" (
  "name" varchar(191) NOT NULL,
  "value" text NOT NULL,
  PRIMARY KEY ("name")
);

CREATE TABLE IF NOT EXISTS "#__gitinsync_objects" (
  "id" char(40) NOT NULL,
  "type" varchar(10) NOT NULL,
  "data" text NOT NULL,
  PRIMARY KEY ("id")
);

CREATE TABLE IF NOT EXISTS "#__gitinsync_operations" (
  "id" serial NOT NULL,
  "kind" varchar(20) NOT NULL,
  "status" varchar(20) NOT NULL,
  "branch" varchar(255) NOT NULL DEFAULT '',
  "title" varchar(255) NOT NULL DEFAULT '',
  "data" text NOT NULL,
  "message" text NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "created_by" integer NOT NULL DEFAULT 0,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__gitinsync_operations_idx_status" ON "#__gitinsync_operations" ("status");

CREATE TABLE IF NOT EXISTS "#__gitinsync_pushed" (
  "id" char(40) NOT NULL,
  PRIMARY KEY ("id")
);
