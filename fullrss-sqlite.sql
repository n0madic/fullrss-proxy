DROP TABLE IF EXISTS "feeds";
CREATE TABLE "feeds" (
  "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
  "enabled" integer NOT NULL DEFAULT '1',
  "name" text NOT NULL,
  "description" text NOT NULL,
  "charset" text NOT NULL DEFAULT 'UTF-8',
  "url" text NOT NULL,
  "method" text NOT NULL,
  "method_detail" text NOT NULL DEFAULT '',
  "filter" text NOT NULL DEFAULT '',
  "imgfix" text NOT NULL DEFAULT '',
  "xml" text NOT NULL DEFAULT '',
  "lastupdate" integer NOT NULL DEFAULT '0'
);

CREATE UNIQUE INDEX "IndexName" ON "feeds" ("name");


DROP TABLE IF EXISTS "log";
CREATE TABLE "log" ("id" text NOT NULL DEFAULT '',"time" integer NOT NULL,"text" text NOT NULL);

CREATE INDEX "log_id" ON "log" ("id");


DROP TABLE IF EXISTS "settings";
CREATE TABLE "settings" (
  "admin_pass" text NOT NULL
, "locale" text NOT NULL DEFAULT 'ru_RU');

INSERT INTO "settings" ("admin_pass", "locale") VALUES ('1411678a0b9e25ee2f7c8b2f7ac92b6a74b3f9c5',	'ru_RU');
