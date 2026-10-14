"use strict";

(document.getElementById("child-error") ?? document.getElementById("duplicate-warning"))?.focus();
const form = document.querySelector(".child-form");
let changed = false;
form?.addEventListener("input", () => { changed = true; });
form?.addEventListener("submit", () => { changed = false; });
window.addEventListener("beforeunload", (event) => {
    if (changed) {
        event.preventDefault();
        event.returnValue = "";
    }
});
