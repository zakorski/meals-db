# QA Review Findings

## Duplicate draft saves
The Add Client template still embeds its own `#mealsdb-save-draft` click handler even though the shared `assets/js/admin.js` script already registers one. Clicking “Save to Draft” now fires both handlers, so two AJAX requests run and two identical drafts get created. Remove the inline handler and rely on the centralized asset (or gate one of them) to stop double inserts.

* Template handler: `views/add-client.php` lines 101-115
* Global handler: `assets/js/admin.js` lines 42-55

## Inline form formatters conflict with shared asset
The same template also reimplements the phone, postal, and datepicker bindings that `assets/js/admin.js` supplies. With two different formatters attached to the same inputs, user input is reformatted twice per keystroke, leading to unpredictable cursor jumps and mask behaviour. Drop the inline bindings and allow the shared script to manage those controls for consistency.

* Inline bindings: `views/add-client.php` lines 86-100
* Shared bindings: `assets/js/admin.js` lines 6-37

## Draft deletion triggers a fatal error
Clicking **Delete** on the Drafts tab calls the `mealsdb_delete_draft` AJAX endpoint, but the handler invokes `MealsDB_Client_Form::delete_draft()` which does not exist. PHP halts with a `Call to undefined method` fatal, so drafts can never be removed from the database. The form layer needs a real `delete_draft()` implementation (and the AJAX action should check its return value) to make deletions work.

* AJAX call site: `includes/class-ajax.php` lines 145-160
* Only draft persistence method available: `includes/class-client-form.php` lines 228-302

## Undefined draft identifier on Add Client form
The Add Client template renders a hidden `draft_id` input when `$resumed_draft_id > 0`, but that variable is never initialised. Resuming a draft therefore emits a PHP notice and the identifier is omitted, so subsequent saves cannot update the original draft. Initialise `$resumed_draft_id` when processing resumed submissions (or gate the hidden field more defensively) so the draft ID survives the round trip.

* Hidden input usage: `views/add-client.php` lines 44-51
* Resume flow that omits assignment: `views/add-client.php` lines 11-33
