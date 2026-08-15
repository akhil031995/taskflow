-- 005 project workspace: where the agent implements, and how to reach the app.
-- folder_path: local working directory the agent should stay within.
-- access_url:  optional URL where the running app can be viewed.
ALTER TABLE projects
  ADD COLUMN folder_path VARCHAR(500) NULL COMMENT 'local working directory for implementation',
  ADD COLUMN access_url  VARCHAR(500) NULL COMMENT 'optional URL to view the running app';
