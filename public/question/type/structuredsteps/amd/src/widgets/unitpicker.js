define(['qtype_structuredsteps/widgets/plain'], function(PlainWidget) {
    function UnitPickerWidget(fieldEl) {
        PlainWidget.call(this, fieldEl);
        this.changeHandler = null;
    }

    UnitPickerWidget.prototype = Object.create(PlainWidget.prototype);
    UnitPickerWidget.prototype.constructor = UnitPickerWidget;

    UnitPickerWidget.prototype.init = function() {
        var field = this.fieldEl;

        this.changeHandler = function() {
            var value = field.value || '';
            field.setAttribute('data-ss-selected-unit', value);
        };

        field.addEventListener('change', this.changeHandler);
    };

    UnitPickerWidget.prototype.destroy = function() {
        if (this.changeHandler) {
            this.fieldEl.removeEventListener('change', this.changeHandler);
        }
    };

    return UnitPickerWidget;
});
