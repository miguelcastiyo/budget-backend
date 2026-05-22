ALTER TABLE invitations
  ADD COLUMN invitee_name VARCHAR(120) NOT NULL DEFAULT '' AFTER invite_token_hash,
  ADD COLUMN role ENUM('admin', 'member') NOT NULL DEFAULT 'member' AFTER email,
  ADD COLUMN email_subject VARCHAR(160) NOT NULL DEFAULT 'You are invited to Budget App' AFTER invited_by_user_id,
  ADD COLUMN email_body TEXT NOT NULL AFTER email_subject;
