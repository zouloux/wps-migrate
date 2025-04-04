# WPS - Migrate Wordpress plugin

Migrate uploads and data directory ( like sqlite database ) from your Wordpress admin panel.
Allows **download** and **upload** as tar.gz archives.

### Minimalist
- No admin message
- No buy plugin messages
- No setup

### Config
Can be disabled with an env `WPS_MIGRATE_DISABLE=true`
Uploads ( media ) path is automatic, but can be defined with `WPS_MIGRATE_UPLOADS_PATH`
Data path ( sqlite for ex ) is automatic if [sqlite database integration](https://github.com/WordPress/sqlite-database-integration) is installed, but can be configured with `WPS_MIGRATE_DATA_PATH`.

### Dependencies
It has no dependencies other than Bedrock and Wordpress.

### Install

How to install with [Bedrock](https://roots.io/bedrock/) :

```bash
composer require zouloux/wps-migrate
```
