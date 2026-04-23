define([], function() {
    function HintPanel(root) {
        this.root = root;
        this.clickHandler = null;
    }

    HintPanel.prototype.init = function() {
        var root = this.root;

        this.clickHandler = function(e) {
            var button = e.target.closest('[data-ss-hint-toggle="1"]');
            if (!button || !root.contains(button)) {
                return;
            }

            e.preventDefault();
            var targetId = button.getAttribute('data-ss-target');
            if (!targetId) {
                return;
            }

            var panel = document.getElementById(targetId);
            if (!panel) {
                return;
            }

            var expanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            if (expanded) {
                panel.setAttribute('hidden', 'hidden');
            } else {
                panel.removeAttribute('hidden');
            }
        };

        root.addEventListener('click', this.clickHandler);
    };

    HintPanel.prototype.destroy = function() {
        if (this.clickHandler) {
            this.root.removeEventListener('click', this.clickHandler);
        }
    };

    return HintPanel;
});
