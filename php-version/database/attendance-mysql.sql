CREATE TABLE IF NOT EXISTS `AttendanceDay` (
 `id` VARCHAR(64) PRIMARY KEY, `userId` VARCHAR(64) NOT NULL, `workDate` VARCHAR(10) NOT NULL,
 `checkIn` DATETIME NOT NULL, `checkOut` DATETIME NULL, `version` INTEGER NOT NULL,
 `updatedAt` DATETIME NOT NULL, UNIQUE (`userId`,`workDate`), FOREIGN KEY (`userId`) REFERENCES `User` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `AttendanceRequest` (
 `id` VARCHAR(64) PRIMARY KEY, `userId` VARCHAR(64) NOT NULL, `workDate` VARCHAR(10) NOT NULL,
 `checkIn` DATETIME NOT NULL, `checkOut` DATETIME NULL, `baseVersion` INTEGER NOT NULL,
 `reason` TEXT NOT NULL, `status` VARCHAR(16) NOT NULL, `createdAt` DATETIME NOT NULL,
 `decidedBy` VARCHAR(64) NULL, `decidedAt` DATETIME NULL, `decisionReason` TEXT NULL,
 FOREIGN KEY (`userId`) REFERENCES `User` (`id`), FOREIGN KEY (`decidedBy`) REFERENCES `User` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `AttendanceHistory` (
 `id` VARCHAR(64) PRIMARY KEY, `userId` VARCHAR(64) NOT NULL, `workDate` VARCHAR(10) NOT NULL,
 `actorId` VARCHAR(64) NOT NULL, `action` VARCHAR(32) NOT NULL,
 `beforeData` TEXT NOT NULL, `afterData` TEXT NOT NULL, `reason` TEXT NOT NULL, `createdAt` DATETIME NOT NULL,
 FOREIGN KEY (`userId`) REFERENCES `User` (`id`), FOREIGN KEY (`actorId`) REFERENCES `User` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AttendanceLocation` (
 `id` VARCHAR(64) PRIMARY KEY, `userId` VARCHAR(64) NOT NULL, `workDate` VARCHAR(10) NOT NULL,
 `kind` VARCHAR(8) NOT NULL, `recordedAt` DATETIME NOT NULL, `capturedAt` DATETIME NOT NULL,
 `latitude` DOUBLE NOT NULL, `longitude` DOUBLE NOT NULL, `accuracy` DOUBLE NOT NULL,
 `distanceMeters` DOUBLE NOT NULL, `punchMode` VARCHAR(24) NOT NULL,
 `reason` VARCHAR(120) NOT NULL, `policyVersion` VARCHAR(64) NOT NULL,
 UNIQUE (`userId`,`workDate`,`kind`), FOREIGN KEY (`userId`) REFERENCES `User` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AttendanceNetwork` (
 `id` VARCHAR(64) PRIMARY KEY, `userId` VARCHAR(64) NOT NULL, `workDate` VARCHAR(10) NOT NULL,
 `kind` VARCHAR(8) NOT NULL, `recordedAt` DATETIME NOT NULL, `ipAddress` VARCHAR(45) NOT NULL,
 UNIQUE (`userId`,`workDate`,`kind`), FOREIGN KEY (`userId`) REFERENCES `User` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AttendanceManual` (
 `id` VARCHAR(64) PRIMARY KEY, `userId` VARCHAR(64) NOT NULL, `workDate` VARCHAR(10) NOT NULL,
 `kind` VARCHAR(8) NOT NULL, `recordedAt` DATETIME NOT NULL, `submittedAt` DATETIME NOT NULL,
 UNIQUE (`userId`,`workDate`,`kind`), FOREIGN KEY (`userId`) REFERENCES `User` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
