# Moderation Helpers

This module brings content moderation controls to the frontend side of the
Gin toolbar. When viewing moderated content with appropriate permissions,
the toolbar displays:

- The current moderation state of the viewed revision, including the
  revision author and date.
- If a newer draft exists that isn't published, a panel linking to
  that draft for review and comparison.
- If viewing a non-default revision, a panel showing the default
  revision state with a link to view and compare.
- Action buttons for each valid workflow transition (publish, draft,
  review, archive, etc.) based on the user's permissions and the
  current workflow configuration.

## Requirements

- **Drupal 11** (PHP 8.4+)
- **Content Moderation** (core) — must be enabled
- **Workflows** (core) — must be enabled
- **Gin** admin theme with **Gin Toolbar** — the moderation panel renders
  inside Gin's secondary toolbar
- **Diff** (optional) — enables "Compare" links between revisions

## Installation

1. Place the `moderation_helpers` folder in your project's custom modules
   directory (e.g. `web/modules/custom/moderation_helpers`).

2. Enable the module:

       drush en moderation_helpers

3. Make sure the required core modules are enabled:

       drush en workflows content_moderation

## Configuration

### 1. Set up a Workflow

Navigate to **Admin → Configuration → Workflow → Workflows**
(`/admin/config/workflow/workflows`) and create or edit an
**Content moderation** workflow with the states and transitions your
site needs (e.g. Draft → Needs Review → Published, Archived, etc.).

### 2. Assign the Workflow to Content Types

Edit your workflow and under **This workflow applies to**, add each
content type that should use moderation. Once assigned, new and
existing content of those types will use the workflow's states.

### 3. Assign Permissions

Navigate to **Admin → People → Permissions** (`/admin/people/permissions`)
and grant the appropriate transition permissions to each role. The
module reads these permissions to decide which action buttons to show
each user.

Key permissions to configure:

- **Content Moderation** section — one permission per workflow
  transition (e.g. "Use Draft → Published transition").
- **Node** section — "View own unpublished content", "View any
  unpublished content" (needed to see draft revisions).
- **Node** section — "Revert revisions" (needed for the Revert
  button on historical revisions).

### 4. Enable Gin and Gin Toolbar

The moderation panel renders inside the Gin admin theme's secondary
toolbar. Make sure Gin is your admin theme and Gin Toolbar is enabled:

    drush theme:enable gin
    drush en gin_toolbar

Set Gin as the administration theme at **Admin → Appearance**
(`/admin/appearance`).

### 5. Install Diff (optional)

To enable "Compare" links that show a side-by-side diff between
revisions, install and enable the Diff module:

    composer require drupal/diff
    drush en diff

Without Diff, the compare links will not appear.

## How It Works

When an authenticated user with toolbar access views a node page that
is under a content moderation workflow, the module:

1. Hooks into `hook_preprocess_toolbar()` to build the
   `ModerationHelperForm` form and pass it to the toolbar template.
2. Provides a toolbar template suggestion (`toolbar__moderation_helpers`)
   that overrides the Gin toolbar's secondary toolbar area. On frontend
   node pages the moderation form replaces the default breadcrumb; on
   other pages a "Back to Administration" link is shown instead.
3. The form shows the current revision's moderation state, plus panels
   for the default and latest revisions when they differ.
4. Displays action buttons for each valid transition the current user
   can perform, based on the workflow configuration and permissions.
5. On submit, creates a new revision in the target state and redirects
   back to the appropriate revision page.

The module also includes an event subscriber that redirects
`/node/{nid}/latest` to `/node/{nid}` when a user gets an access
denied error (e.g. when no forward revision exists under moderation).

