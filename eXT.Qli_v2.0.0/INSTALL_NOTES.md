# eXT.Qli Refactor Install Notes

1. Back up the current deployment and database first.
2. Run `database/migrations/2026_05_05_create_signaling_tables.sql` against the existing `ext_qli` database.
3. Replace the matching files in your deployment with the files from this package.
4. Keep your existing authentication/session files unchanged. This package does not include or modify login/logout/Auth.php.
5. Delete the old `backend/api/signaling_data/` directory after confirming the new signaling tables are receiving rows.
6. Restart the Python agent.
7. Confirm the agent does not listen on port 8081:

```bash
ss -lntp | grep ':8081' || echo 'OK: no 8081 listener'
```

8. Confirm PHP syntax:

```bash
find backend -name '*.php' -print0 | xargs -0 -n1 php -l
```

9. Confirm JavaScript loads through the module script in `index.php`.
