<?php

/**
 * Who may do what.
 *
 * This map is the authority. The browser gets a copy so the menu can hide what
 * an operator cannot use, but hiding a button is a courtesy, never a control:
 * every endpoint checks this again on the server.
 */

declare(strict_types=1);

const ADMIN_PERMISSIONS = [
    // Everything, including destructive actions and account management.
    'super_admin' => ['*'],

    // Day-to-day running of the desk.
    'admin' => [
        'student.view', 'student.export', 'student.verify', 'student.deactivate',
        // Renaming a room and moving it to another floor. Not the photos or
        // the route: those come from walking the building with a camera.
        'room.view', 'room.edit',
        'audit.view',
    ],

    // Teaching staff who need to look things up but hold no destructive power.
    'faculty' => [
        'student.view',
        'room.view',
    ],
];

/*
  Every permission that exists.

  This has to be declared rather than derived from the lists above, because
  some permissions belong only to super_admin and so appear in no named list.
  Deriving the catalogue from those lists meant `settings.manage` and
  `student.delete` were missing from what the browser was told, so the Settings
  menu item and the Delete button stayed hidden even for a full-access admin.
  The server always allowed both; only the interface disagreed.
*/
const ALL_PERMISSIONS = [
    'student.view', 'student.export', 'student.verify', 'student.deactivate', 'student.delete',
    'room.view', 'room.edit',
    'audit.view',
    'settings.manage',
];

const ADMIN_ROLE_LABELS = [
    'super_admin' => 'Full access',
    'admin'       => 'Staff',
    'faculty'     => 'View only',
];

/*
  What each level can do, in the words the Users & Access page shows. Kept
  beside ADMIN_PERMISSIONS so a change there is made here too: whoever hands
  out a level must know it includes student records.
*/
const ADMIN_ROLE_SUMMARIES = [
    'super_admin' => 'Everything, including staff accounts, settings and deleting student accounts.',
    'admin'       => 'Student accounts (look up, export, verify, turn off), rooms and the walkthrough '
        . '(look up and change), and the activity log.',
    'faculty'     => 'Can look up student accounts and rooms. Cannot change anything.',
];

function can(string $permission): bool
{
    $role = $_SESSION['admin_role'] ?? null;
    if ($role === null) {
        return false;
    }

    $granted = ADMIN_PERMISSIONS[$role] ?? [];

    return in_array('*', $granted, true) || in_array($permission, $granted, true);
}

/** The full permission list for the signed-in role, for the browser to read. */
function granted_permissions(): array
{
    $role = $_SESSION['admin_role'] ?? null;
    if ($role === null) {
        return [];
    }

    $granted = ADMIN_PERMISSIONS[$role] ?? [];

    // Expand the wildcard so the client never has to understand it.
    return in_array('*', $granted, true) ? ALL_PERMISSIONS : $granted;
}
