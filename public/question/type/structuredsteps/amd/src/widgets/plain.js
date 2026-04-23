define([], function() {
    function PlainWidget(fieldEl) {
        this.fieldEl = fieldEl;
    }

    PlainWidget.prototype.init = function() {
        return;
    };

    PlainWidget.prototype.getValue = function() {
        return this.fieldEl.value;
    };

    PlainWidget.prototype.setValue = function(val) {
        this.fieldEl.value = (val === null || typeof val === 'undefined') ? '' : String(val);
    };

    PlainWidget.prototype.focus = function() {
        this.fieldEl.focus();
    };

    PlainWidget.prototype.destroy = function() {
        return;
    };

    return PlainWidget;
});
