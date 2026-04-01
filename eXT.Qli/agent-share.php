<?php
http_response_code(410);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>agent-share removed</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: Arial, sans-serif;
            background: #0f172a;
            color: #e5e7eb;
        }
        .card {
            max-width: 720px;
            padding: 24px;
            border-radius: 16px;
            background: #111827;
            border: 1px solid rgba(255,255,255,.08);
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
        }
        h1 { margin-top: 0; }
        code {
            background: rgba(255,255,255,.08);
            padding: 2px 6px;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>agent-share.php has been removed</h1>
        <p>The screen viewing flow now comes directly from <code>extqli_agent.py</code> over WebSocket.</p>
        <p>No browser share page is used anymore, and no HTTPS requirement is needed for the agent screen stream.</p>
    </div>
</body>
</html>
