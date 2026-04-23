define([], function() {
    function InputDock(root, navigation) {
        this.root = root;
        this.navigation = navigation;
        this.dock = null;
        this.activeField = null;
        this.focusHandler = null;
        this.clickHandler = null;
    }

    InputDock.prototype.init = function() {
        this.dock = this.root.querySelector('[data-ss-input-dock="1"]');
        if (!this.dock) {
            return;
        }

        var self = this;

        this.focusHandler = function(e) {
            var field = e.target.closest('.ss-field[data-ss-field-id]');
            if (!field || !self.root.contains(field)) {
                return;
            }
            self.activeField = field;
            self.dock.removeAttribute('hidden');
        };

        this.clickHandler = function(e) {
            var btn = e.target.closest('[data-ss-action]');
            if (!btn || !self.dock.contains(btn)) {
                return;
            }

            e.preventDefault();
            self.handleAction(btn.getAttribute('data-ss-action'));
        };

        this.root.addEventListener('focusin', this.focusHandler);
        this.dock.addEventListener('click', this.clickHandler);
    };

    InputDock.prototype.handleAction = function(action) {
        var field = this.activeField;
        if (!field) {
            return;
        }

        if (action === 'next') {
            this.activeField = this.navigation.next(field);
            this.announceStep(this.activeField, 'nav');
            return;
        }

        if (action === 'prev') {
            this.activeField = this.navigation.prev(field);
            this.announceStep(this.activeField, 'nav');
            return;
        }

        if (action === 'backspace') {
            field.value = (field.value || '').slice(0, -1);
            field.dispatchEvent(new Event('input', {bubbles: true}));
            return;
        }

        if (action === 'clear') {
            field.value = '';
            field.dispatchEvent(new Event('input', {bubbles: true}));
            return;
        }

        if (action === 'done') {
            this.dock.setAttribute('hidden', 'hidden');
            this.announceStep(field, 'done');
            field.blur();
        }
    };

    /**
     * QSS-042: Update the ARIA live region so screen readers announce step navigation.
     *
     * @param {Element|null} field  The field that is now active (after nav) or was just confirmed.
     * @param {string}       reason 'nav' | 'done'
     */
    InputDock.prototype.announceStep = function(field, reason) {
        var liveRegion = this.root.querySelector('[data-ss-live-region="1"]');
        if (!liveRegion || !field) {
            return;
        }

        var card = field.closest('.ss-step-card');
        if (!card) {
            return;
        }

        // Derive human-readable step position from badge text (set by renderer).
        var badge = card.querySelector('.ss-step-badge');
        var badgeNum = badge ? badge.textContent.trim() : '';
        var cards = Array.from(this.root.querySelectorAll('.ss-step-card:not(.ss-step-card--context)'));
        var total = cards.length;

        var msg = '';
        if (reason === 'done') {
            msg = 'Answer saved' + (badgeNum ? ' for step ' + badgeNum : '') + '.';
        } else {
            msg = badgeNum
                ? 'Step ' + badgeNum + ' of ' + total + '.'
                : 'Moved to next field.';
        }

        // Toggle text to ensure re-announcement even if message is unchanged.
        liveRegion.textContent = '';
        liveRegion.textContent = msg;
    };

    InputDock.prototype.destroy = function() {
        if (!this.dock) {
            return;
        }

        if (this.focusHandler) {
            this.root.removeEventListener('focusin', this.focusHandler);
        }

        if (this.clickHandler) {
            this.dock.removeEventListener('click', this.clickHandler);
        }

        this.activeField = null;
    };

    return InputDock;
});
