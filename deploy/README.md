# DreamHost Deploy

1. Copy `dreamhost.env.example` to `dreamhost.env`.
2. Fill in your DreamHost SSH hostname, username, password, remote path, and production database values.
3. Run:

```bash
./deploy/deploy.sh
```

Optional:

```bash
./deploy/deploy.sh --delete
```

`--delete` removes remote files that no longer exist locally for the files included in the sync.

Preview a deploy without changing the server:

```bash
./deploy/deploy.sh --dry-run
```

You can combine flags:

```bash
./deploy/deploy.sh --dry-run --delete
```

During deploy, `deploy.sh` also writes the production `public/config.php` on the remote server from the values in `dreamhost.env`.
