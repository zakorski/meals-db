# QA Review Findings

## Duplicate draft saves
The Add Client template still embeds its own `#mealsdb-save-draft` click handler even though the shared `assets/js/admin.js` script already registers one. Clicking “Save to Draft” now fires both handlers, so two AJAX requests run and two identical drafts get created. Remove the inline handler and rely on the centralized asset (or gate one of them) to stop double inserts.

* Template handler: `views/add-client.php` lines 101-115
* Global handler: `assets/js/admin.js` lines 42-55

## Inline form formatters conflict with shared asset
The same template also reimplements the phone, postal, and datepicker bindings that `assets/js/admin.js` supplies. With two different formatters attached to the same inputs, user input is reformatted twice per keystroke, leading to unpredictable cursor jumps and mask behaviour. Drop the inline bindings and allow the shared script to manage those controls for consistency.

* Inline bindings: `views/add-client.php` lines 86-100
* Shared bindings: `assets/js/admin.js` lines 6-37
