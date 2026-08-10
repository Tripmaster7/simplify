(function () {
    'use strict';

    var membershipInput = document.getElementById('aiw_membership_number');
    var membershipStatus = document.getElementById('aiw-membership-status');
    var requestSequence = 0;

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
        membershipInput.setCustomValidity('');
        membershipStatus.className = 'aiw-membership-status';
        membershipStatus.textContent = '';
    });

    membershipInput.addEventListener('blur', function () {
        var membershipNumber = membershipInput.value.trim();
        var currentRequest = ++requestSequence;

        membershipInput.value = membershipNumber;

        if (!/^0\d{4}$/.test(membershipNumber)) {
            showInvalid(AIWAdmin.membershipFormatMessage);
            return;
        }

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
                    var message = response.data && response.data.message
                        ? response.data.message
                        : AIWAdmin.membershipRequestError;
                    showInvalid(message);
                    return;
                }

                membershipInput.setCustomValidity('');
                membershipStatus.className = 'aiw-membership-status aiw-membership-status--valid';
                membershipStatus.textContent = AIWAdmin.membershipAuthorLabel + ' ' + response.data.name;
            })
            .catch(function () {
                if (currentRequest === requestSequence) {
                    showInvalid(AIWAdmin.membershipRequestError);
                }
            });
    });
})();
