/**
 * Client Drafts admin view behavior.
 *
 * Extracted verbatim from the inline <script> previously embedded in
 * views/drafts.php (CLAUDE.md bans inline logic blocks > 20 lines). The only
 * change is that PHP-interpolated values (the delete nonce, ajax URL, and the
 * two user-facing strings) are now read from the "mealsdb-drafts-data" JSON
 * island instead of being echoed into the script source.
 */
(function ($) {
    "use strict";

    var _el = document.getElementById("mealsdb-drafts-data");
    var data = _el ? JSON.parse(_el.textContent || "{}") : {};

    // ajaxurl is a WP admin global; prefer the island value, fall back to it.
    var ajaxUrl = data.ajaxUrl || window.ajaxurl;
    var confirmDelete = data.confirmDelete || "Are you sure you want to delete this draft?";
    var failMessage = data.failMessage || "Failed to delete draft.";

    $(document).ready(function () {
        $(".delete-draft").on("click", function () {
            // Capture the row refs before the async confirm.
            var draftId = $(this).data("id");
            var row = $("#draft-row-" + draftId);

            window.MealsDBConfirm.confirm({
                title: "Delete draft",
                message: confirmDelete,
                confirmLabel: "Delete",
                destructive: true
            }).then(function (ok) {
                if (!ok) { return; }
                $.post(ajaxUrl, {
                    action: "mealsdb_delete_draft",
                    nonce: data.nonce || "",
                    id: draftId
                }, function (response) {
                    if (response.success) {
                        row.fadeOut();
                        if (response.data && response.data.message) {
                            window.MealsDBNotice("success", response.data.message);
                        }
                    } else {
                        window.MealsDBNotice("error", failMessage);
                    }
                });
            });
        });
    });
})(jQuery);
