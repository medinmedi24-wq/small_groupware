CREATE TABLE IF NOT EXISTS `User` (
  `id` VARCHAR(64) PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `passwordHash` VARCHAR(255) NOT NULL,
  `department` VARCHAR(255) NOT NULL,
  `position` VARCHAR(255) NOT NULL,
  `role` VARCHAR(32) NOT NULL DEFAULT 'USER',
  `joinDate` DATETIME(3) NOT NULL,
  `annualLeave` DOUBLE NOT NULL DEFAULT 15,
  `annualLeaveOverride` DOUBLE NULL,
  `leaveBalanceAdjustment` DOUBLE NOT NULL DEFAULT 0,
  `leaveBalanceAdjustedAt` DATETIME(3) NULL,
  `isActive` TINYINT(1) NOT NULL DEFAULT 1,
  `mustChangePassword` TINYINT(1) NOT NULL DEFAULT 1,
  `passwordChangedAt` DATETIME(3) NULL,
  `createdAt` DATETIME(3) NOT NULL,
  `updatedAt` DATETIME(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `CardExpense` (
  `id` VARCHAR(64) PRIMARY KEY,
  `userId` VARCHAR(64) NOT NULL,
  `usedAt` DATETIME(3) NOT NULL,
  `merchant` VARCHAR(255) NOT NULL,
  `amount` INT NOT NULL,
  `category` VARCHAR(255) NOT NULL,
  `purpose` LONGTEXT NOT NULL,
  `receiptNote` LONGTEXT NULL,
  `isFixed` TINYINT(1) NOT NULL DEFAULT 0,
  `hasReceipt` TINYINT(1) NOT NULL DEFAULT 0,
  `hasApprovalDocument` TINYINT(1) NOT NULL DEFAULT 0,
  `note` LONGTEXT NULL,
  `receiptFileName` VARCHAR(255) NULL,
  `receiptFilePath` VARCHAR(255) NULL,
  `receiptAttachments` LONGTEXT NULL,
  `receiptMimeType` VARCHAR(255) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  `decisionReason` LONGTEXT NULL,
  `decidedById` VARCHAR(64) NULL,
  `decidedAt` DATETIME(3) NULL,
  `createdAt` DATETIME(3) NOT NULL,
  `updatedAt` DATETIME(3) NOT NULL,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`decidedById`) REFERENCES `User` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `LeaveBalanceAdjustment` (
  `id` VARCHAR(64) PRIMARY KEY,
  `userId` VARCHAR(64) NOT NULL,
  `actorId` VARCHAR(64) NOT NULL,
  `previousBalance` DOUBLE NOT NULL,
  `targetBalance` DOUBLE NOT NULL,
  `adjustmentDelta` DOUBLE NOT NULL,
  `reason` LONGTEXT NOT NULL,
  `effectiveDate` DATETIME(3) NOT NULL,
  `createdAt` DATETIME(3) NOT NULL,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`actorId`) REFERENCES `User` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Session` (
  `id` VARCHAR(64) PRIMARY KEY,
  `token` VARCHAR(191) NOT NULL UNIQUE,
  `userId` VARCHAR(64) NOT NULL,
  `expiresAt` DATETIME(3) NOT NULL,
  `createdAt` DATETIME(3) NOT NULL,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `LeaveRequest` (
  `id` VARCHAR(64) PRIMARY KEY,
  `userId` VARCHAR(64) NOT NULL,
  `leaveType` VARCHAR(32) NOT NULL,
  `startDate` DATETIME(3) NOT NULL,
  `endDate` DATETIME(3) NOT NULL,
  `days` DOUBLE NOT NULL,
  `reason` LONGTEXT NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'PENDING_TEAM_LEADER',
  `teamLeaderStatus` VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  `teamLeaderApprovedAt` DATETIME(3) NULL,
  `teamLeaderApprovedBy` VARCHAR(255) NULL,
  `teamLeaderRejectReason` LONGTEXT NULL,
  `directorStatus` VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  `directorApprovedAt` DATETIME(3) NULL,
  `directorApprovedBy` VARCHAR(255) NULL,
  `directorRejectReason` LONGTEXT NULL,
  `ceoStatus` VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  `ceoApprovedAt` DATETIME(3) NULL,
  `ceoApprovedBy` VARCHAR(255) NULL,
  `ceoRejectReason` LONGTEXT NULL,
  `isDeleted` TINYINT(1) NOT NULL DEFAULT 0,
  `deletedAt` DATETIME(3) NULL,
  `requestFingerprint` VARCHAR(64) NULL UNIQUE,
  `createdAt` DATETIME(3) NOT NULL,
  `updatedAt` DATETIME(3) NOT NULL,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `LeaveHistory` (
  `id` VARCHAR(64) PRIMARY KEY,
  `leaveRequestId` VARCHAR(64) NOT NULL,
  `userId` VARCHAR(64) NOT NULL,
  `action` VARCHAR(255) NOT NULL,
  `actorId` VARCHAR(64) NOT NULL,
  `beforeData` LONGTEXT NULL,
  `afterData` LONGTEXT NULL,
  `createdAt` DATETIME(3) NOT NULL,
  FOREIGN KEY (`leaveRequestId`) REFERENCES `LeaveRequest` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`actorId`) REFERENCES `User` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `CompanyHoliday` (
  `id` VARCHAR(64) PRIMARY KEY,
  `date` DATETIME(3) NOT NULL UNIQUE,
  `name` VARCHAR(255) NOT NULL,
  `createdAt` DATETIME(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ReportDelivery` (
  `id` VARCHAR(64) PRIMARY KEY,
  `period` VARCHAR(255) NOT NULL,
  `trigger` VARCHAR(255) NOT NULL,
  `sender` VARCHAR(255) NOT NULL,
  `recipient` VARCHAR(255) NOT NULL,
  `status` VARCHAR(255) NOT NULL,
  `fileName` VARCHAR(255) NULL,
  `errorMessage` LONGTEXT NULL,
  `sentAt` DATETIME(3) NULL,
  `createdAt` DATETIME(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `idx_LeaveRequest_userId` ON `LeaveRequest` (`userId`);

CREATE INDEX `idx_LeaveRequest_status` ON `LeaveRequest` (`status`);

CREATE INDEX `idx_LeaveHistory_leaveRequestId` ON `LeaveHistory` (`leaveRequestId`);

CREATE INDEX `idx_CardExpense_userId` ON `CardExpense` (`userId`);

CREATE INDEX `idx_CardExpense_status` ON `CardExpense` (`status`);

CREATE INDEX `idx_Session_userId` ON `Session` (`userId`);

CREATE INDEX `idx_LeaveBalanceAdjustment_userId` ON `LeaveBalanceAdjustment` (`userId`);
