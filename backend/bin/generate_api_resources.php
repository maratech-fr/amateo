<?php

declare(strict_types=1);

/**
 * Generation script for API Platform DTOs, State Providers and Processors
 * for all 20 ClubScheduler entities.
 */
$projectDir = dirname(__DIR__);
$srcDir = $projectDir . '/src';

$entities = [
    'Club' => [
        'hasClubId' => false,
        'hasSeasonId' => false,
        'table' => 'club',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'name', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'slug', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'planId', 'type' => 'int', 'nullable' => true],
            ['name' => 'billingCycle', 'type' => 'string', 'nullable' => true, 'assert' => 'Choice', 'choices' => ['"monthly"', '"annual"', '"quarterly"']],
            ['name' => 'planExpiresAt', 'type' => 'DateTimeImmutable', 'nullable' => true],
            ['name' => 'generationCountSeason', 'type' => 'int', 'required' => true],
            ['name' => 'schoolZone', 'type' => 'string', 'nullable' => true],
            ['name' => 'timezone', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'locale', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'onboardingCompleted', 'type' => 'bool', 'required' => true],
            ['name' => 'ffbbClubCode', 'type' => 'string', 'nullable' => true],
        ],
    ],
    'User' => [
        'hasClubId' => false,
        'hasSeasonId' => false,
        'table' => 'app_user',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'email', 'type' => 'string', 'required' => true, 'assert' => 'Email'],
            ['name' => 'firstName', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'lastName', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'emailVerifiedAt', 'type' => 'DateTimeImmutable', 'nullable' => true, 'readOnly' => true],
        ],
    ],
    'Sport' => [
        'hasClubId' => false,
        'hasSeasonId' => false,
        'table' => 'sport',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'name', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'slug', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'icon', 'type' => 'string', 'nullable' => true],
            ['name' => 'isActive', 'type' => 'bool', 'required' => true],
        ],
    ],
    'Plan' => [
        'hasClubId' => false,
        'hasSeasonId' => false,
        'table' => 'plan',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'name', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'maxTeams', 'type' => 'int', 'required' => true],
            ['name' => 'maxVenues', 'type' => 'int', 'required' => true],
            ['name' => 'maxGenerations', 'type' => 'int', 'required' => true],
            ['name' => 'monthlyPrice', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'annualPrice', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'features', 'type' => 'array', 'required' => true],
        ],
    ],
    'PriorityTier' => [
        'hasClubId' => false,
        'hasSeasonId' => false,
        'table' => 'priority_tier',
        'fields' => [
            ['name' => 'id', 'type' => 'int', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'label', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'name', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'color', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'orToolsWeight', 'type' => 'int', 'required' => true],
            ['name' => 'defaultMinSessions', 'type' => 'int', 'required' => true],
        ],
    ],
    'Season' => [
        'hasClubId' => true,
        'hasSeasonId' => false,
        'table' => 'season',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'name', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'startDate', 'type' => 'DateTimeImmutable', 'required' => true],
            ['name' => 'endDate', 'type' => 'DateTimeImmutable', 'required' => true],
            ['name' => 'status', 'type' => 'string', 'required' => true, 'assert' => 'Choice', 'choices' => ['"draft"', '"active"', '"archived"', '"closed"']],
            ['name' => 'exportPdfUrl', 'type' => 'string', 'nullable' => true, 'readOnly' => true],
            ['name' => 'transitionData', 'type' => 'array', 'required' => true, 'readOnly' => true],
        ],
    ],
    'ClubUser' => [
        'hasClubId' => true,
        'hasSeasonId' => false,
        'table' => 'club_user',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'userId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'role', 'type' => 'string', 'required' => true, 'assert' => 'Choice', 'choices' => ['"owner"', '"admin"', '"editor"', '"viewer"']],
            ['name' => 'joinedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'isActive', 'type' => 'bool', 'required' => true],
        ],
    ],
    'SportCategory' => [
        'hasClubId' => true,
        'hasSeasonId' => false,
        'table' => 'sport_category',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'sportId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'name', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'isCustom', 'type' => 'bool', 'required' => true],
            ['name' => 'ageMin', 'type' => 'int', 'nullable' => true],
            ['name' => 'ageMax', 'type' => 'int', 'nullable' => true],
            ['name' => 'sortOrder', 'type' => 'int', 'required' => true],
        ],
    ],
    'Venue' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'venue',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'name', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'isExternal', 'type' => 'bool', 'required' => true],
            ['name' => 'color', 'type' => 'string', 'nullable' => true],
            ['name' => 'latitude', 'type' => 'string', 'nullable' => true],
            ['name' => 'longitude', 'type' => 'string', 'nullable' => true],
            ['name' => 'source', 'type' => 'string', 'required' => true, 'assert' => 'Choice', 'choices' => ['"manual"', '"ffbb"', '"import"']],
            ['name' => 'externalRef', 'type' => 'string', 'nullable' => true],
            ['name' => 'isActive', 'type' => 'bool', 'required' => true],
            ['name' => 'parentVenueId', 'type' => 'string', 'nullable' => true],
        ],
    ],
    'Team' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'team',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'sportCategoryId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'priorityTierId', 'type' => 'int', 'required' => true],
            ['name' => 'name', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'gender', 'type' => 'string', 'nullable' => true, 'assert' => 'Choice', 'choices' => ['"M"', '"F"', '"mixed"']],
            ['name' => 'sessionsPerWeek', 'type' => 'int', 'required' => true],
            ['name' => 'minSessionsOverride', 'type' => 'int', 'nullable' => true],
            ['name' => 'matchDay', 'type' => 'int', 'nullable' => true],
            ['name' => 'forcedVenueId', 'type' => 'string', 'nullable' => true],
            ['name' => 'isActive', 'type' => 'bool', 'required' => true],
            ['name' => 'parentTeamId', 'type' => 'string', 'nullable' => true],
        ],
    ],
    'Coach' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'coach',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'firstName', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'lastName', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'email', 'type' => 'string', 'nullable' => true, 'assert' => 'Email'],
            ['name' => 'phone', 'type' => 'string', 'nullable' => true],
            ['name' => 'maxDaysOverride', 'type' => 'int', 'nullable' => true],
            ['name' => 'maxDaysOverrideConfirmed', 'type' => 'bool', 'required' => true],
            ['name' => 'acceptableLateMinutes', 'type' => 'int', 'nullable' => true],
            ['name' => 'isActive', 'type' => 'bool', 'required' => true],
            ['name' => 'parentCoachId', 'type' => 'string', 'nullable' => true],
        ],
    ],
    'CoachUnavailability' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'coach_unavailability',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'coachId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'dayOfWeek', 'type' => 'int', 'required' => true, 'assert' => 'Range', 'rangeMin' => 1, 'rangeMax' => 7],
            ['name' => 'startTime', 'type' => 'DateTimeImmutable', 'nullable' => true],
            ['name' => 'endTime', 'type' => 'DateTimeImmutable', 'nullable' => true],
        ],
    ],
    'CoachPlayerMembership' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'coach_player_membership',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'coachId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'teamId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'position', 'type' => 'string', 'nullable' => true],
            ['name' => 'isActive', 'type' => 'bool', 'required' => true],
        ],
    ],
    'TeamCoach' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'team_coach',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'teamId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'coachId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'role', 'type' => 'string', 'required' => true, 'assert' => 'Choice', 'choices' => ['"head"', '"assistant"', '"trainer"']],
            ['name' => 'isRequired', 'type' => 'bool', 'required' => true],
        ],
    ],
    'TeamConstraint' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'team_constraint',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'teamId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'type', 'type' => 'string', 'required' => true, 'assert' => 'Choice', 'choices' => ['"preferred"', '"avoid"', '"forbidden"', '"required"']],
            ['name' => 'dayOfWeek', 'type' => 'int', 'nullable' => true, 'assert' => 'Range', 'rangeMin' => 1, 'rangeMax' => 7],
            ['name' => 'startTime', 'type' => 'DateTimeImmutable', 'nullable' => true],
            ['name' => 'endTime', 'type' => 'DateTimeImmutable', 'nullable' => true],
            ['name' => 'venueId', 'type' => 'string', 'nullable' => true],
            ['name' => 'reason', 'type' => 'string', 'nullable' => true],
            ['name' => 'createdBy', 'type' => 'string', 'nullable' => true],
            ['name' => 'sourceOccurrenceId', 'type' => 'string', 'nullable' => true],
        ],
    ],
    'VenueAvailability' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'venue_availability',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'venueId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'dayOfWeek', 'type' => 'int', 'required' => true, 'assert' => 'Range', 'rangeMin' => 1, 'rangeMax' => 7],
            ['name' => 'startTime', 'type' => 'DateTimeImmutable', 'required' => true],
            ['name' => 'endTime', 'type' => 'DateTimeImmutable', 'required' => true],
        ],
    ],
    'VenueClosure' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'venue_closure',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'venueId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'dateStart', 'type' => 'DateTimeImmutable', 'required' => true],
            ['name' => 'dateEnd', 'type' => 'DateTimeImmutable', 'required' => true],
            ['name' => 'reason', 'type' => 'string', 'nullable' => true],
        ],
    ],
    'Schedule' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'schedule',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'name', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'status', 'type' => 'string', 'required' => true, 'assert' => 'Choice', 'choices' => ['"draft"', '"generating"', '"published"', '"archived"']],
            ['name' => 'score', 'type' => 'int', 'nullable' => true, 'readOnly' => true],
            ['name' => 'solverSeed', 'type' => 'int', 'required' => true],
            ['name' => 'snapshotHash', 'type' => 'string', 'nullable' => true, 'readOnly' => true],
            ['name' => 'snapshotData', 'type' => 'array', 'required' => true, 'readOnly' => true],
            ['name' => 'solverVersion', 'type' => 'string', 'nullable' => true, 'readOnly' => true],
            ['name' => 'constraintVersion', 'type' => 'string', 'nullable' => true, 'readOnly' => true],
            ['name' => 'scoreFormulaVersion', 'type' => 'string', 'nullable' => true, 'readOnly' => true],
            ['name' => 'solverTimeoutSeconds', 'type' => 'int', 'nullable' => true, 'readOnly' => true],
            ['name' => 'solverNbVariables', 'type' => 'int', 'nullable' => true, 'readOnly' => true],
            ['name' => 'solverNbConstraints', 'type' => 'int', 'nullable' => true, 'readOnly' => true],
            ['name' => 'solverNbConflicts', 'type' => 'int', 'nullable' => true, 'readOnly' => true],
            ['name' => 'solverWallTimeMs', 'type' => 'int', 'nullable' => true, 'readOnly' => true],
            ['name' => 'pdfExportStatus', 'type' => 'string', 'nullable' => true, 'readOnly' => true],
            ['name' => 'pdfExportUrl', 'type' => 'string', 'nullable' => true, 'readOnly' => true],
        ],
    ],
    'ScheduleDiagnostic' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'schedule_diagnostic',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'scheduleId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'type', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'severity', 'type' => 'string', 'required' => true, 'assert' => 'Choice', 'choices' => ['"info"', '"warning"', '"error"', '"critical"']],
            ['name' => 'teamId', 'type' => 'string', 'nullable' => true],
            ['name' => 'coachId', 'type' => 'string', 'nullable' => true],
            ['name' => 'venueId', 'type' => 'string', 'nullable' => true],
            ['name' => 'message', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'suggestions', 'type' => 'array', 'required' => true],
        ],
    ],
    'ScheduleSlotTemplate' => [
        'hasClubId' => true,
        'hasSeasonId' => true,
        'table' => 'schedule_slot_template',
        'fields' => [
            ['name' => 'id', 'type' => 'string', 'readOnly' => true, 'isId' => true],
            ['name' => 'version', 'type' => 'int', 'readOnly' => true],
            ['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'updatedAt', 'type' => 'DateTimeImmutable', 'readOnly' => true],
            ['name' => 'scheduleId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'teamId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'venueId', 'type' => 'string', 'required' => true, 'assert' => 'NotBlank'],
            ['name' => 'coachId', 'type' => 'string', 'nullable' => true],
            ['name' => 'dayOfWeek', 'type' => 'int', 'required' => true, 'assert' => 'Range', 'rangeMin' => 1, 'rangeMax' => 7],
            ['name' => 'startTime', 'type' => 'DateTimeImmutable', 'required' => true],
            ['name' => 'durationMinutes', 'type' => 'int', 'required' => true],
            ['name' => 'lockLevel', 'type' => 'string', 'required' => true, 'assert' => 'Choice', 'choices' => ['"NONE"', '"SOFT"', '"HARD"']],
            ['name' => 'pendingConstraintSuggestion', 'type' => 'array', 'nullable' => true],
        ],
    ],
];

// Create directories
@mkdir($srcDir . '/Dto', 0o755, true);
@mkdir($srcDir . '/State/Provider', 0o755, true);
@mkdir($srcDir . '/State/Processor', 0o755, true);

// Generate AbstractStateProvider
$abstractProvider = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace App\State\Provider;

    use ApiPlatform\Metadata\Operation;
    use ApiPlatform\State\ProviderInterface;
    use ApiPlatform\State\Pagination\Pagination;
    use Doctrine\ORM\EntityManagerInterface;
    use Symfony\Component\HttpFoundation\RequestStack;

    abstract class AbstractStateProvider implements ProviderInterface
    {
        public function __construct(
            protected readonly EntityManagerInterface $entityManager,
            protected readonly RequestStack $requestStack,
            protected readonly Pagination $pagination,
        ) {
        }

        abstract protected function getEntityClass(): string;
        abstract protected function mapEntityToOutput(object $entity): object;

        public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
        {
            $request = $this->requestStack->getCurrentRequest();
            $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');

            if ($operation instanceof \ApiPlatform\Metadata\GetCollection) {
                return $this->provideCollection($operation, $context, $clubId);
            }

            return $this->provideItem($uriVariables, $clubId);
        }

        protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
        {
            $qb = $this->entityManager->createQueryBuilder()
                ->select('e')
                ->from($this->getEntityClass(), 'e');

            if ($this->pagination->isEnabled($operation, $context)) {
                $offset = $this->pagination->getOffset($operation, $context);
                $limit = $this->pagination->getLimit($operation, $context);
                $qb->setFirstResult($offset)
                   ->setMaxResults($limit);
            }

            $query = $qb->getQuery();
            $results = $query->getResult();

            return array_map([$this, 'mapEntityToOutput'], $results);
        }

        protected function provideItem(array $uriVariables, ?string $clubId): ?object
        {
            $id = $uriVariables['id'] ?? null;
            if (!$id) {
                return null;
            }

            $entity = $this->entityManager->find($this->getEntityClass(), $id);
            if (!$entity) {
                return null;
            }

            if ($clubId !== null && method_exists($entity, 'getClubId') && $entity->getClubId() !== $clubId) {
                return null;
            }

            return $this->mapEntityToOutput($entity);
        }
    }
    PHP;

file_put_contents($srcDir . '/State/Provider/AbstractStateProvider.php', $abstractProvider);

// Generate AbstractStateProcessor
$abstractProcessor = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace App\State\Processor;

    use ApiPlatform\Metadata\DeleteOperationInterface;
    use ApiPlatform\Metadata\Operation;
    use ApiPlatform\State\ProcessorInterface;
    use Doctrine\ORM\EntityManagerInterface;
    use Symfony\Component\HttpFoundation\RequestStack;
    use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

    abstract class AbstractStateProcessor implements ProcessorInterface
    {
        public function __construct(
            protected readonly EntityManagerInterface $entityManager,
            protected readonly RequestStack $requestStack,
        ) {
        }

        abstract protected function getEntityClass(): string;
        abstract protected function createEntityFromInput(object $input): object;
        abstract protected function updateEntityFromInput(object $entity, object $input): void;
        abstract protected function mapEntityToOutput(object $entity): object;

        public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): object|void
        {
            $request = $this->requestStack->getCurrentRequest();
            $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
            $seasonId = $request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id');

            if ($operation instanceof DeleteOperationInterface) {
                return $this->processDelete($uriVariables, $clubId);
            }

            $method = $operation->getMethod() ?? '';
            if ($method === 'POST') {
                return $this->processPost($data, $clubId, $seasonId);
            }

            if (in_array($method, ['PUT', 'PATCH'], true)) {
                return $this->processPut($data, $uriVariables, $clubId, $seasonId);
            }

            return $data;
        }

        protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
        {
            $entity = $this->createEntityFromInput($input);

            if ($clubId !== null && method_exists($entity, 'setClubId')) {
                $entity->setClubId($clubId);
            }
            if ($seasonId !== null && method_exists($entity, 'setSeasonId')) {
                $entity->setSeasonId($seasonId);
            }

            $this->entityManager->persist($entity);
            $this->entityManager->flush();

            return $this->mapEntityToOutput($entity);
        }

        protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
        {
            $id = $uriVariables['id'] ?? null;
            $entity = $this->entityManager->find($this->getEntityClass(), $id);

            if (!$entity) {
                throw new NotFoundHttpException('Resource not found');
            }

            if ($clubId !== null && method_exists($entity, 'getClubId') && $entity->getClubId() !== $clubId) {
                throw new AccessDeniedHttpException('Access denied');
            }

            $this->updateEntityFromInput($entity, $input);
            $this->entityManager->flush();

            return $this->mapEntityToOutput($entity);
        }

        protected function processDelete(array $uriVariables, ?string $clubId): void
        {
            $id = $uriVariables['id'] ?? null;
            $entity = $this->entityManager->find($this->getEntityClass(), $id);

            if (!$entity) {
                throw new NotFoundHttpException('Resource not found');
            }

            if ($clubId !== null && method_exists($entity, 'getClubId') && $entity->getClubId() !== $clubId) {
                throw new AccessDeniedHttpException('Access denied');
            }

            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }
    PHP;

file_put_contents($srcDir . '/State/Processor/AbstractStateProcessor.php', $abstractProcessor);

foreach ($entities as $entityName => $meta) {
    $entityClass = "App\\Entity\\{$entityName}";
    $resourceClass = "App\\ApiResource\\{$entityName}Resource";
    $inputClass = "App\\Dto\\{$entityName}Input";
    $providerClass = "App\\State\\Provider\\{$entityName}StateProvider";
    $processorClass = "App\\State\\Processor\\{$entityName}StateProcessor";

    // --- Generate ApiResource (Output DTO) ---
    $resourceProps = [];
    $resourceImports = [
        'use ApiPlatform\Metadata\ApiResource;',
        'use ApiPlatform\Metadata\Get;',
        'use ApiPlatform\Metadata\GetCollection;',
        'use ApiPlatform\Metadata\Post;',
        'use ApiPlatform\Metadata\Put;',
        'use ApiPlatform\Metadata\Delete;',
        'use Symfony\Component\Serializer\Attribute\Groups;',
    ];

    foreach ($meta['fields'] as $field) {
        $type = $field['type'];
        $phpType = match ($type) {
            'DateTimeImmutable' => '\\DateTimeImmutable',
            default => $type,
        };
        $nullablePrefix = ($field['nullable'] ?? false) ? '?' : '';
        $prop = "    #[Groups(['read'])]\n";
        $prop .= "    public {$nullablePrefix}{$phpType} \${$field['name']}";
        if (($field['nullable'] ?? false) || ('array' === $field['type'])) {
            $prop .= ' = null';
        } elseif ('bool' === $field['type']) {
            $prop .= ' = false';
        } elseif ('int' === $field['type']) {
            $prop .= ' = 0';
        } elseif ('string' === $field['type']) {
            $prop .= ' = \'\'';
        }
        $prop .= ";\n";
        $resourceProps[] = $prop;
    }

    $resourceCode = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\ApiResource;\n\n";
    $resourceCode .= implode("\n", $resourceImports) . "\n\n";
    $resourceCode .= "#[ApiResource(\n";
    $resourceCode .= "    operations: [\n";
    $resourceCode .= "        new GetCollection(),\n";
    $resourceCode .= "        new Get(),\n";
    $resourceCode .= "        new Post(),\n";
    $resourceCode .= "        new Put(),\n";
    $resourceCode .= "        new Delete(),\n";
    $resourceCode .= "    ],\n";
    $resourceCode .= "    input: {$entityName}Input::class,\n";
    $resourceCode .= "    provider: {$entityName}StateProvider::class,\n";
    $resourceCode .= "    processor: {$entityName}StateProcessor::class,\n";
    $resourceCode .= "    paginationEnabled: true,\n";
    $resourceCode .= "    paginationItemsPerPage: 30,\n";
    $resourceCode .= ")]\n";
    $resourceCode .= "class {$entityName}Resource\n{\n";
    $resourceCode .= implode("\n", $resourceProps) . "\n";
    $resourceCode .= "\n    public static function fromEntity({$entityName} \$entity): self\n    {\n";
    $resourceCode .= "        \$dto = new self();\n";
    foreach ($meta['fields'] as $field) {
        $getter = 'get' . ucfirst($field['name']);
        if ('bool' === $field['type']) {
            $getter = 'get' . ucfirst($field['name']);
        }
        $resourceCode .= "        \$dto->{$field['name']} = \$entity->{$getter}();\n";
    }
    $resourceCode .= "        return \$dto;\n";
    $resourceCode .= "    }\n}\n";

    file_put_contents($srcDir . "/ApiResource/{$entityName}Resource.php", $resourceCode);

    // --- Generate Input DTO ---
    $inputProps = [];
    $inputImports = [
        'use Symfony\Component\Serializer\Attribute\Groups;',
        'use Symfony\Component\Validator\Constraints as Assert;',
    ];

    foreach ($meta['fields'] as $field) {
        if (($field['readOnly'] ?? false) || ($field['isId'] ?? false)) {
            continue;
        }

        $type = $field['type'];
        $phpType = match ($type) {
            'DateTimeImmutable' => '\\DateTimeImmutable',
            default => $type,
        };
        $nullablePrefix = ($field['nullable'] ?? false) ? '?' : '';
        $prop = '';

        if (($field['assert'] ?? null) === 'NotBlank' && !($field['nullable'] ?? false)) {
            $prop .= "    #[Assert\NotBlank]\n";
        }
        if (($field['assert'] ?? null) === 'Email') {
            $prop .= "    #[Assert\Email]\n";
        }
        if (($field['assert'] ?? null) === 'Choice') {
            $choices = implode(', ', $field['choices']);
            $prop .= "    #[Assert\Choice(choices: [{$choices}])]\n";
        }
        if (($field['assert'] ?? null) === 'Range') {
            $prop .= "    #[Assert\Range(min: {$field['rangeMin']}, max: {$field['rangeMax']})]\n";
        }

        $prop .= "    #[Groups(['write'])]\n";
        $prop .= "    public {$nullablePrefix}{$phpType} \${$field['name']}";
        if (($field['nullable'] ?? false) || ('array' === $field['type'])) {
            $prop .= ' = null';
        } elseif ('bool' === $field['type']) {
            $prop .= ' = false';
        } elseif ('int' === $field['type']) {
            $prop .= ' = 0';
        } elseif ('string' === $field['type']) {
            $prop .= ' = \'\'';
        }
        $prop .= ";\n";
        $inputProps[] = $prop;
    }

    $inputCode = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Dto;\n\n";
    $inputCode .= implode("\n", $inputImports) . "\n\n";
    $inputCode .= "class {$entityName}Input\n{\n";
    $inputCode .= implode("\n", $inputProps) . "\n}\n";

    file_put_contents($srcDir . "/Dto/{$entityName}Input.php", $inputCode);

    // --- Generate StateProvider ---
    $providerCode = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\State\\Provider;\n\n";
    $providerCode .= "use App\\ApiResource\\{$entityName}Resource;\n";
    $providerCode .= "use App\\Entity\\{$entityName};\n";
    $providerCode .= "\nclass {$entityName}StateProvider extends AbstractStateProvider\n{\n";
    $providerCode .= "    protected function getEntityClass(): string\n    {\n";
    $providerCode .= "        return {$entityName}::class;\n";
    $providerCode .= "    }\n\n";
    $providerCode .= "    protected function mapEntityToOutput(object \$entity): {$entityName}Resource\n    {\n";
    $providerCode .= "        return {$entityName}Resource::fromEntity(\$entity);\n";
    $providerCode .= "    }\n}\n";

    file_put_contents($srcDir . "/State/Provider/{$entityName}StateProvider.php", $providerCode);

    // --- Generate StateProcessor ---
    $processorCode = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\State\\Processor;\n\n";
    $processorCode .= "use App\\ApiResource\\{$entityName}Resource;\n";
    $processorCode .= "use App\\Dto\\{$entityName}Input;\n";
    $processorCode .= "use App\\Entity\\{$entityName};\n";
    $processorCode .= "\nclass {$entityName}StateProcessor extends AbstractStateProcessor\n{\n";
    $processorCode .= "    protected function getEntityClass(): string\n    {\n";
    $processorCode .= "        return {$entityName}::class;\n";
    $processorCode .= "    }\n\n";

    // createEntityFromInput
    $processorCode .= "    protected function createEntityFromInput(object \$input): {$entityName}\n    {\n";
    $processorCode .= "        \$entity = new {$entityName}();\n";
    foreach ($meta['fields'] as $field) {
        if (($field['readOnly'] ?? false) || ($field['isId'] ?? false)) {
            continue;
        }
        $setter = 'set' . ucfirst($field['name']);
        $processorCode .= "        if (\$input->{$field['name']} !== null || !" . (($field['nullable'] ?? false) ? 'true' : 'false') . ") {\n";
        $processorCode .= "            \$entity->{$setter}(\$input->{$field['name']});\n";
        $processorCode .= "        }\n";
    }
    $processorCode .= "        return \$entity;\n";
    $processorCode .= "    }\n\n";

    // updateEntityFromInput
    $processorCode .= "    protected function updateEntityFromInput(object \$entity, object \$input): void\n    {\n";
    foreach ($meta['fields'] as $field) {
        if (($field['readOnly'] ?? false) || ($field['isId'] ?? false)) {
            continue;
        }
        $setter = 'set' . ucfirst($field['name']);
        $processorCode .= "        \$entity->{$setter}(\$input->{$field['name']});\n";
    }
    $processorCode .= "    }\n\n";

    // mapEntityToOutput
    $processorCode .= "    protected function mapEntityToOutput(object \$entity): {$entityName}Resource\n    {\n";
    $processorCode .= "        return {$entityName}Resource::fromEntity(\$entity);\n";
    $processorCode .= "    }\n}\n";

    file_put_contents($srcDir . "/State/Processor/{$entityName}StateProcessor.php", $processorCode);
}

echo 'Generated ' . count($entities) . " entities with DTOs, providers and processors.\n";
