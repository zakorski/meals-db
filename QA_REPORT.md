# QA Review Findings

## Draft saves always create new rows
Clicking “Save to Draft” serializes the Add Client form and posts it to the `mealsdb_save_draft` AJAX handler, but the success payload never returns the newly created draft’s identifier. Because the browser never learns that ID, there is nothing to persist on the form for the next save, so every subsequent click is treated as a brand-new draft and inserts a duplicate row instead of updating the existing one. Return the ID from `MealsDB_Client_Form::save_draft()` (and store it client side) so later saves can send it back for an update.

* Missing identifier in response: `includes/class-ajax.php` lines 109-139
* No client-side persistence of the ID: `assets/js/admin.js` lines 45-58, `views/add-client.php` lines 41-47 and 94-99

## Draft deletion triggers a fatal error
Clicking **Delete** on the Drafts tab calls the `mealsdb_delete_draft` AJAX endpoint, but the handler invokes `MealsDB_Client_Form::delete_draft()` which does not exist. PHP halts with a `Call to undefined method` fatal, so drafts can never be removed from the database. The form layer needs a real `delete_draft()` implementation (and the AJAX action should check its return value) to make deletions work.

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
