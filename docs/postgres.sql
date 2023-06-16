drop table event;
drop table workflow;
drop table subscription;
drop table log;

CREATE TABLE workflow (
  workflow_id	bigserial NOT NULL PRIMARY KEY,
  type	varchar(128) NOT NULL  DEFAULT '',
  context	TEXT,
  created_at	timestamp default current_timestamp,
  scheduled_at	timestamp default NULL,
  started_at	timestamp default NULL,
  finished_at	timestamp default NULL,
  lock	varchar(128) NOT NULL DEFAULT '',
  status	varchar(16) NOT NULL DEFAULT 'ACTIVE',
  error_count	INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE event (
  event_id	bigserial NOT NULL PRIMARY KEY,
  type	varchar(64) NOT NULL DEFAULT '',
  context	TEXT,
  created_at	timestamp default current_timestamp,
  started_at	timestamp default NULL,
  finished_at	timestamp default NULL,
  status	varchar(16) NOT NULL DEFAULT 'ACTIVE',
  workflow_id	bigint NOT NULL
);

CREATE TABLE subscription (
  id bigserial  NOT NULL PRIMARY KEY,
  workflow_id	bigint NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'ACTIVE',
  event_type 	varchar(64) NOT NULL DEFAULT '',
  context_key	varchar(64) NOT NULL DEFAULT '',
  context_value	varchar(128) NOT NULL DEFAULT '',
  created_at timestamp default current_timestamp,
  update_at	timestamp default current_timestamp
);

CREATE TABLE log (
  id bigserial NOT NULL PRIMARY KEY,
  workflow_id	BIGINT NOT NULL,
  host varchar(32) default '',
  pid int default 0,
  created_at	timestamp default current_timestamp,
  log_text	TEXT NOT NULL
);

create table host
(
    hostname varchar(128) not null
        constraint host_pk
        primary key,
    updated_at timestamp default now()
);

comment on table host is 'List of hosts (containers) which process workflows';

create unique index subscr_uni_ind
  on subscription (workflow_id, event_type, context_key, context_value, status );

CREATE INDEX log_ind ON log (workflow_id , created_at );
CREATE INDEX workflow_queue_ind ON workflow (scheduled_at, status );
CREATE INDEX event_queue_ind ON event (created_at, status );
CREATE INDEX subscr_wf_ind ON subscription (workflow_id, status);