# Deploy for free

Running your own MapChat at no cost. Back to the [README](../README.md).

The app runs as a Docker container on [Render](https://render.com)'s free plan,
with the database on [TiDB Cloud Starter](https://tidbcloud.com), a free
MySQL-compatible service.

1. Create a TiDB Cloud Starter cluster. In its SQL editor, run
   `CREATE DATABASE mapchat; USE mapchat;`, then the contents of `database/init.sql`,
   and, if you want the sample conversation, `database/demo.sql`.
2. In Render, choose **New > Blueprint** and pick this repository. Render reads
   `render.yaml` and asks for the database host, user and password from TiDB's
   **Connect** dialog, and for `APP_URL` (the `onrender.com` address).
3. Optional settings:
   - `MAILER_DSN` for activation and password-reset emails. Render's free plan
     blocks SMTP, so use `brevo+api://API_KEY@default`.
   - `CLOUDINARY_CLOUD_NAME` and `CLOUDINARY_UPLOAD_PRESET` for avatar uploads.
   - `GROQ_API_KEY` for MapChat and the AI characters to chat back.

Free Render services sleep after 15 minutes without visitors; the `Keep awake`
workflow visits every 10 minutes, well within Render's 750 free hours a month.
The container also brings an older database up to date when it starts.

<details>
<summary>Upgrading a database from before the automatic upgrades</summary>

```sql
ALTER TABLE chats
  ADD COLUMN to_email VARCHAR(255) NULL DEFAULT NULL AFTER email,
  ADD KEY fk_m_to_email (to_email),
  ADD CONSTRAINT fk_m_to_email FOREIGN KEY (to_email) REFERENCES users (email)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE userinfo
  ADD COLUMN country CHAR(2) AFTER city,
  ADD COLUMN timezone VARCHAR(64) AFTER country;
```

</details>
