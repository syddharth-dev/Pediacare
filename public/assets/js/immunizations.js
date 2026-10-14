'use strict';

document.getElementById('immunization-error')?.focus();
const immunizationForm = document.getElementById('immunization-form');
let immunizationChanged = false;
let immunizationSaving = false;
if (immunizationForm) {
    immunizationForm.addEventListener('input', () => { immunizationChanged = true; });
    immunizationForm.addEventListener('change', () => { immunizationChanged = true; });
    immunizationForm.addEventListener('submit', () => {
        immunizationSaving = true;
        const button = immunizationForm.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = 'Recording…';
    });
    window.addEventListener('beforeunload', (event) => {
        if (immunizationChanged && !immunizationSaving) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    window.addEventListener('pageshow', () => {
        immunizationSaving = false;
        const button = immunizationForm.querySelector('button[type="submit"]');
        button.disabled = false;
        button.textContent = 'Record vaccine';
    });
}
