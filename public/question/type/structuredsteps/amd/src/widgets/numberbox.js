define(['qtype_structuredsteps/widgets/plain'], function(PlainWidget) {
    function NumberBoxWidget(fieldEl) {
        PlainWidget.call(this, fieldEl);
        this.inputHandler = null;
    }

    NumberBoxWidget.prototype = Object.create(PlainWidget.prototype);
    NumberBoxWidget.prototype.constructor = NumberBoxWidget;

    NumberBoxWidget.prototype.init = function() {
        var field = this.fieldEl;
        field.setAttribute('inputmode', 'decimal');

        this.inputHandler = function() {
            var value = field.value || '';
            value = value.replace(/,/g, '.').replace(/[^0-9.\-]/g, '');

            var negative = value.charAt(0) === '-';
            value = value.replace(/\-/g, '');
            if (negative) {
                value = '-' + value;
            }

            var parts = value.split('.');
            if (parts.length > 2) {
                value = parts.shift() + '.' + parts.join('');
            }

            field.value = value;
        };

        field.addEventListener('input', this.inputHandler);
    };

    NumberBoxWidget.prototype.destroy = function() {
        if (this.inputHandler) {
            this.fieldEl.removeEventListener('input', this.inputHandler);
        }
    };

    return NumberBoxWidget;
});
