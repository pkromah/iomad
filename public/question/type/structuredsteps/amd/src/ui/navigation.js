define([], function() {
    function Navigation(root) {
        this.root = root;
        this.fields = [];
    }

    Navigation.prototype.init = function() {
        this.refresh();
    };

    Navigation.prototype.refresh = function() {
        this.fields = Array.prototype.slice.call(this.root.querySelectorAll('.ss-field[data-ss-field-id]'));
    };

    Navigation.prototype.indexOf = function(fieldEl) {
        return this.fields.indexOf(fieldEl);
    };

    Navigation.prototype.focusAt = function(idx) {
        if (idx < 0 || idx >= this.fields.length) {
            return null;
        }

        var target = this.fields[idx];
        target.focus();
        target.scrollIntoView({block: 'nearest', inline: 'nearest'});
        return target;
    };

    Navigation.prototype.next = function(current) {
        var idx = this.indexOf(current);
        if (idx === -1) {
            return this.focusAt(0);
        }
        return this.focusAt(Math.min(this.fields.length - 1, idx + 1));
    };

    Navigation.prototype.prev = function(current) {
        var idx = this.indexOf(current);
        if (idx === -1) {
            return this.focusAt(0);
        }
        return this.focusAt(Math.max(0, idx - 1));
    };

    Navigation.prototype.destroy = function() {
        this.fields = [];
    };

    return Navigation;
});
