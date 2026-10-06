document.addEventListener("DOMContentLoaded", function () {
    var form = document.getElementById("print-users-form");
    if (!form) {
        return;
    }

    var checkboxes = Array.prototype.slice.call(form.querySelectorAll(".print-user-checkbox"))
        .filter(function (checkbox) {
            return !checkbox.disabled;
        });
    var selectAll = document.getElementById("select-all-users");
    var printButton = document.getElementById("print-selected-button");
    var pageSize = document.getElementById("print-page-size");
    selectAll.disabled = checkboxes.length === 0;

    pageSize.addEventListener("change", function () {
        var url = new URL(window.location.href);
        url.searchParams.set("batch_id", "batch_" + form.elements.batch_id.value);
        url.searchParams.set("per_page", pageSize.value);
        url.searchParams.set("page", "1");
        window.location.assign(url.toString());
    });

    function updatePrintButton() {
        printButton.disabled = !checkboxes.some(function (checkbox) {
            return checkbox.checked;
        });
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener("change", updatePrintButton);
    });
    updatePrintButton();

    selectAll.addEventListener("change", function () {
        checkboxes.forEach(function (checkbox) {
            checkbox.checked = selectAll.checked;
        });
        updatePrintButton();
    });
});
