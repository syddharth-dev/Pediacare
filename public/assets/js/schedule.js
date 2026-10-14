'use strict';

document.getElementById('schedule-error')?.focus();

const scheduleForms = [...document.querySelectorAll('form.auth-form')];
const changedScheduleForms = new Set();
let savingSchedule = false;

for (const form of scheduleForms) {
    form.addEventListener('input', () => changedScheduleForms.add(form));
    form.addEventListener('change', () => changedScheduleForms.add(form));
    form.addEventListener('submit', () => {
        savingSchedule = true;
        for (const button of form.querySelectorAll('button[type="submit"]')) {
            button.dataset.originalText = button.textContent;
            button.textContent = 'Saving…';
            button.disabled = true;
        }
    });
}

window.addEventListener('beforeunload', (event) => {
    if (!savingSchedule && changedScheduleForms.size > 0) {
        event.preventDefault();
        event.returnValue = '';
    }
});

window.addEventListener('pageshow', () => {
    savingSchedule = false;
    for (const button of document.querySelectorAll('button[data-original-text]')) {
        button.textContent = button.dataset.originalText;
        button.disabled = false;
    }
});
