CREATE TABLE IF NOT EXISTS `User` (
  `id` TEXT PRIMARY KEY,
  `name` TEXT NOT NULL,
  `email` TEXT NOT NULL UNIQUE,
  `passwordHash` TEXT NOT NULL,
  `department` TEXT NOT NULL,
  `position` TEXT NOT NULL,
  `role` TEXT NOT NULL DEFAULT 'USER',
  `joinDate` TEXT NOT NULL,
  `annualLeave` REAL NOT NULL DEFAULT 15,
  `annualLeaveOverride` REAL NULL,
  `leaveBalanceAdjustment` REAL NOT NULL DEFAULT 0,
  `leaveBalanceAdjustedAt` TEXT NULL,
  `isActive` INTEGER NOT NULL DEFAULT 1,
  `mustChangePassword` INTEGER NOT NULL DEFAULT 1,
  `passwordChangedAt` TEXT NULL,
  `createdAt` TEXT NOT NULL,
  `updatedAt` TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS `CardExpense` (
  `id` TEXT PRIMARY KEY,
  `userId` TEXT NOT NULL,
  `usedAt` TEXT NOT NULL,
  `merchant` TEXT NOT NULL,
  `amount` INTEGER NOT NULL,
  `category` TEXT NOT NULL,
  `purpose` TEXT NOT NULL,
  `receiptNote` TEXT NULL,
  `isFixed` INTEGER NOT NULL DEFAULT 0,
  `hasReceipt` INTEGER NOT NULL DEFAULT 0,
  `hasApprovalDocument` INTEGER NOT NULL DEFAULT 0,
  `note` TEXT NULL,
  `receiptFileName` TEXT NULL,
  `receiptFilePath` TEXT NULL,
  `receiptAttachments` TEXT NULL,
  `receiptMimeType` TEXT NULL,
  `status` TEXT NOT NULL DEFAULT 'PENDING',
  `decisionReason` TEXT NULL,
  `decidedById` TEXT NULL,
  `decidedAt` TEXT NULL,
  `createdAt` TEXT NOT NULL,
  `updatedAt` TEXT NOT NULL,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`decidedById`) REFERENCES `User` (`id`) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS `LeaveBalanceAdjustment` (
  `id` TEXT PRIMARY KEY,
  `userId` TEXT NOT NULL,
  `actorId` TEXT NOT NULL,
  `previousBalance` REAL NOT NULL,
  `targetBalance` REAL NOT NULL,
  `adjustmentDelta` REAL NOT NULL,
  `reason` TEXT NOT NULL,
  `effectiveDate` TEXT NOT NULL,
  `createdAt` TEXT NOT NULL,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`actorId`) REFERENCES `User` (`id`) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS `Session` (
  `id` TEXT PRIMARY KEY,
  `token` TEXT NOT NULL UNIQUE,
  `userId` TEXT NOT NULL,
  `expiresAt` TEXT NOT NULL,
  `createdAt` TEXT NOT NULL,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `LeaveRequest` (
  `id` TEXT PRIMARY KEY,
  `userId` TEXT NOT NULL,
  `leaveType` TEXT NOT NULL,
  `startDate` TEXT NOT NULL,
  `endDate` TEXT NOT NULL,
  `days` REAL NOT NULL,
  `reason` TEXT NOT NULL,
  `status` TEXT NOT NULL DEFAULT 'PENDING_TEAM_LEADER',
  `teamLeaderStatus` TEXT NOT NULL DEFAULT 'PENDING',
  `teamLeaderApprovedAt` TEXT NULL,
  `teamLeaderApprovedBy` TEXT NULL,
  `teamLeaderRejectReason` TEXT NULL,
  `directorStatus` TEXT NOT NULL DEFAULT 'PENDING',
  `directorApprovedAt` TEXT NULL,
  `directorApprovedBy` TEXT NULL,
  `directorRejectReason` TEXT NULL,
  `ceoStatus` TEXT NOT NULL DEFAULT 'PENDING',
  `ceoApprovedAt` TEXT NULL,
  `ceoApprovedBy` TEXT NULL,
  `ceoRejectReason` TEXT NULL,
  `isDeleted` INTEGER NOT NULL DEFAULT 0,
  `deletedAt` TEXT NULL,
  `requestFingerprint` TEXT NULL UNIQUE,
  `createdAt` TEXT NOT NULL,
  `updatedAt` TEXT NOT NULL,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS `LeaveHistory` (
  `id` TEXT PRIMARY KEY,
  `leaveRequestId` TEXT NOT NULL,
  `userId` TEXT NOT NULL,
  `action` TEXT NOT NULL,
  `actorId` TEXT NOT NULL,
  `beforeData` TEXT NULL,
  `afterData` TEXT NULL,
  `createdAt` TEXT NOT NULL,
  FOREIGN KEY (`leaveRequestId`) REFERENCES `LeaveRequest` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`actorId`) REFERENCES `User` (`id`) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS `CompanyHoliday` (
  `id` TEXT PRIMARY KEY,
  `date` TEXT NOT NULL UNIQUE,
  `name` TEXT NOT NULL,
  `createdAt` TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS `ReportDelivery` (
  `id` TEXT PRIMARY KEY,
  `period` TEXT NOT NULL,
  `trigger` TEXT NOT NULL,
  `sender` TEXT NOT NULL,
  `recipient` TEXT NOT NULL,
  `status` TEXT NOT NULL,
  `fileName` TEXT NULL,
  `errorMessage` TEXT NULL,
  `sentAt` TEXT NULL,
  `createdAt` TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS `idx_LeaveRequest_userId` ON `LeaveRequest` (`userId`);

CREATE INDEX IF NOT EXISTS `idx_LeaveRequest_status` ON `LeaveRequest` (`status`);

CREATE INDEX IF NOT EXISTS `idx_LeaveHistory_leaveRequestId` ON `LeaveHistory` (`leaveRequestId`);

CREATE INDEX IF NOT EXISTS `idx_CardExpense_userId` ON `CardExpense` (`userId`);

CREATE INDEX IF NOT EXISTS `idx_CardExpense_status` ON `CardExpense` (`status`);

CREATE INDEX IF NOT EXISTS `idx_Session_userId` ON `Session` (`userId`);

CREATE INDEX IF NOT EXISTS `idx_LeaveBalanceAdjustment_userId` ON `LeaveBalanceAdjustment` (`userId`);
