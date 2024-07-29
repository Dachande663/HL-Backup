# HL Backup

An opionated MySQL backup tool.


## Introduction

HL Backup is a MySQL backup tool designed to meet my needs. As such it does one specific set of actions.

1. Dump a database with ability to filter tables
2. Compress the dump using bzip2 at the highest ratio
3. Encrypt the dump using age and a given public key
4. Upload the dump to an S3-compatible store

It doesn't support postgres, sqlite or any other DB. It doesn't support gzip, zstd or any other compression algorithm. It doesn't support PGP or any other encryption lib. It doesn't do FTP, SFTP, local storage, or any other destination.

PRs for fixes are welcomed. PRs for adding new functionality are not, but feel free to fork and tailor.


## Usage

    docker pull ghcr.io/dachande663/hl-backup:main

    docker run hl-backup version

    docker run hl-backup dump
        --db-host              The database host e.g. localhost, 127.0.0.1, /var/socket, db.host.tld [default = localhost]
        --db-port              The database port [default = 3306]
        --db-username          The database username [default = root]
        --db-password          The database password
        --db-database          [REQUIRED] The database to export
        --db-tables-allowlist  A comma-separated list of tables to export
        --db-tables-blocklist  A comma-separated list of tables to skip
        --encryption-key       [REQUIRED] The age public key to encrypt the file with
        --heartbeat-start      A URL to POST to when starting an export
        --heartbeat-finish     A URL to POST to when an export finishes
        --heartbeat-fail       A URL to POST to when an export fails
        --s3-access-key        [REQUIRED] S3 access key
        --s3-secret-key        [REQUIRED] S3 secret key
        --s3-endpoint          S3 endpoint e.g. https://s3.us-west-002.backblazeb2.com to use Backblaze B2
        --s3-region            S3 region
        --s3-bucket            [REQUIRED] S3 bucket
        --s3-file-name         The destination filename for S3. Can include directories and substitutions. [default = export-{{database}}-{{YYYY}}-{{MM}}-{{DD}}-{{hh}}{{mm}}{{ss}}.sql.bz2.age]
        --debug                If set, output debug information
        --dry-run              If set, perform the export but don't upload
        --help                 Get help about this method
