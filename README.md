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

- **Drupal 10.4+** (PHP 8.1+)
- **Content Moderation** (core) — must be enabled
- **Workflows** (core) — must be enabled
- **Gin** admin theme with **Gin Toolbar** — the moderation panel renders
  inside Gin's secondary toolbar
- **Diff** (optional) — enables "Compare" links between revisions

## Installation

1. Add the repository to your `composer.json` file:
   ```json
   {
        "type": "vcs",
        "url": "https://github.com/graduatecollege/drupal_moderation_helpers"
    }
   ```
2. Require the module:
   ```bash
   composer require graduatecollege/moderation_helpers:dev-main@dev
   ```
3. Install as usual.

## Configuration

Once you've set up core content moderation workflows, navigate to the
Moderation Helpers settings page (`/admin/config/workflow/moderation-helpers`).
It will list all workflows. Click edit on the workflow you want to
configure.

Use the switch at the top to "Enable moderation helpers for this workflow",
and then configure each moderation state as desired.
