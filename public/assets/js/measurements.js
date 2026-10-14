'use strict';

const measurementForm = document.getElementById('measurement-form');
const measurementNotice = document.getElementById('measurement-error') || document.getElementById('measurement-warning');
measurementNotice?.focus();
if (measurementForm) {
    let dirty = false;
    measurementForm.addEventListener('input', () => { dirty = true; });
    window.addEventListener('beforeunload', (event) => {
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    measurementForm.addEventListener('submit', () => {
        dirty = false;
        const button = measurementForm.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = 'Saving…';
    });
    window.addEventListener('pageshow', () => {
        const button = measurementForm.querySelector('button[type="submit"]');
        button.disabled = false;
        button.textContent = 'Save measurement';
    });
}
