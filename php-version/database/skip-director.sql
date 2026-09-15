-- Apply to an existing PHP database after backing it up.
-- Historical completed approvals are preserved. No pending request is auto-approved.
-- All employees need team approval; only team leaders skip that stage.
UPDATE `LeaveRequest`
SET `status`='PENDING_TEAM_LEADER', `teamLeaderStatus`='PENDING', `updatedAt`=CURRENT_TIMESTAMP
WHERE `isDeleted`=0 AND `status` IN ('PENDING_DIRECTOR','PENDING_CEO')
AND `teamLeaderStatus`<>'APPROVED'
AND `userId` IN (SELECT `id` FROM `User` WHERE `role`<>'TEAM_LEADER');
UPDATE `LeaveRequest`
SET `status`='PENDING_CEO', `teamLeaderStatus`='SKIPPED', `updatedAt`=CURRENT_TIMESTAMP
WHERE `isDeleted`=0 AND `status` IN ('PENDING_TEAM_LEADER','PENDING_DIRECTOR')
AND `teamLeaderStatus`<>'APPROVED'
AND `userId` IN (SELECT `id` FROM `User` WHERE `role`='TEAM_LEADER');
UPDATE `LeaveRequest`
SET `status`='PENDING_CEO', `updatedAt`=CURRENT_TIMESTAMP
WHERE `isDeleted`=0 AND `status`='PENDING_DIRECTOR' AND `teamLeaderStatus`='APPROVED';
UPDATE `LeaveRequest`
SET `directorStatus`='SKIPPED', `updatedAt`=CURRENT_TIMESTAMP
WHERE `isDeleted`=0 AND `status` IN ('PENDING_TEAM_LEADER','PENDING_CEO') AND `directorStatus`='PENDING';
