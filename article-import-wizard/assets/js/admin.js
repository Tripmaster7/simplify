(function () {
    'use strict';

    // Enter never sends a wizard form; only clicking a button does. Browsers otherwise submit on
    // Enter in any single-line field (title, author, files, checkboxes...), which sends a half-filled
    // form and loses the selected files. Enter still adds a line break in text areas and still
    // activates a focused button. This script is only loaded on the wizard page.
    var buttonTypes = ['submit', 'button', 'reset', 'image'];
    document.addEventListener('keydown', function (event) {
        var target = event.target;
        if (event.key !== 'Enter' || event.isComposing || !target || target.tagName !== 'INPUT' || !target.form) {
            return;
        }

        if (buttonTypes.indexOf((target.type || '').toLowerCase()) === -1) {
            event.preventDefault();
        }
    }, true);

    var membershipInput = document.getElementById('aiw_membership_number');
    var membershipStatus = document.getElementById('aiw-membership-status');
    var requestSequence = 0;
    // The value of the last completed or running check, so Enter followed by Tab does not check twice.
    var checkedValue = null;

    if (!membershipInput || !membershipStatus || typeof AIWAdmin === 'undefined') {
        return;
    }

    function showInvalid(message) {
        membershipInput.setCustomValidity(message);
        membershipStatus.className = 'aiw-membership-status aiw-membership-status--error';
        membershipStatus.textContent = message;
        membershipInput.reportValidity();
    }

    membershipInput.addEventListener('input', function () {
        requestSequence++;
        checkedValue = null;
        membershipInput.setCustomValidity('');
        membershipStatus.className = 'aiw-membership-status';
        membershipStatus.textContent = '';
    });

    // Enter in this field checks the author like Tab does, instead of submitting the whole form
    // before the categories and files are filled in.
    membershipInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.isComposing) {
            event.preventDefault();
            checkAuthor();
        }
    });

    membershipInput.addEventListener('blur', checkAuthor);

    // A check still running when the form is sent is cut off by the page load; ignore its result
    // so no "could not be checked" error flashes up.
    if (membershipInput.form) {
        membershipInput.form.addEventListener('submit', function () {
            requestSequence++;
        });
    }

    function checkAuthor() {
        var membershipNumber = membershipInput.value.trim();
        if (membershipNumber === checkedValue) {
            return;
        }

        checkedValue = membershipNumber;
        var currentRequest = ++requestSequence;

        membershipInput.value = membershipNumber;

        membershipInput.setCustomValidity('');
        membershipStatus.className = 'aiw-membership-status';
        membershipStatus.textContent = AIWAdmin.membershipCheckingMessage;

        var body = new URLSearchParams();
        body.set('action', 'aiw_validate_membership');
        body.set('nonce', AIWAdmin.membershipNonce);
        body.set('membership_number', membershipNumber);

        fetch(AIWAdmin.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (response) {
                if (currentRequest !== requestSequence) {
                    return;
                }

                if (!response.success) {
                    checkedValue = null;
                    var message = response.data && response.data.message
                        ? response.data.message
                        : AIWAdmin.membershipRequestError;
                    showInvalid(message);
                    return;
                }

                membershipInput.setCustomValidity('');
                membershipStatus.className = 'aiw-membership-status aiw-membership-status--valid';
                membershipStatus.textContent = (response.data.fallback
                    ? (AIWAdmin.membershipFallbackLabel || 'Fallback author:')
                    : AIWAdmin.membershipAuthorLabel)
                    + ' ' + response.data.name;
            })
            .catch(function () {
                if (currentRequest === requestSequence) {
                    checkedValue = null;
                    showInvalid(AIWAdmin.membershipRequestError);
                }
            });
    }
})();
