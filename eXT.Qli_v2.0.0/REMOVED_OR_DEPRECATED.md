# Removed / Deprecated Items

- `backend/api/send_agent_task.php` is now deprecated and returns HTTP 410. Dashboard tasks must use an already-open WebRTC data channel.
- `agent-share.php` can be deleted from deployment when no route references it. The uploaded version only returned HTTP 410.
- Duplicate local copies such as `extqli_agent (1).py` should be deleted manually from the server if present.
- The Python agent no longer starts a local HTTP task server and no longer listens on `0.0.0.0:8081`.
