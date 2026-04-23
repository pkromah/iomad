define(['qtype_structuredsteps/widgets/plain'], function(PlainWidget) {
    function DigitBoxWidget(fieldEl) {
        PlainWidget.call(this, fieldEl);
        this.keyHandler = null;
        this.inputHandler = null;
    }

    DigitBoxWidget.prototype = Object.create(PlainWidget.prototype);
    DigitBoxWidget.prototype.constructor = DigitBoxWidget;

    DigitBoxWidget.prototype.init = function() {
        var field = this.fieldEl;
        field.setAttribute('inputmode', 'numeric');
        field.setAttribute('maxlength', '1');

        this.keyHandler = function(e) {
            if (e.key.length === 1 && !/^[0-9]$/.test(e.key)) {
                e.preventDefault();
            }
        };

        this.inputHandler = function() {
            var value = field.value || '';
            value = value.replace(/[^0-9]/g, '');
            if (value.length > 1) {
                value = value.charAt(value.length - 1);
            }
            field.value = value;
        };

        field.addEventListener('keydown', this.keyHandler);
        field.addEventListener('input', this.inputHandler);
    };

    DigitBoxWidget.prototype.destroy = function() {
        if (this.keyHandler) {
            this.fieldEl.removeEventListener('keydown', this.keyHandler);
        }
        if (this.inputHandler) {
            this.fieldEl.removeEventListener('input', this.inputHandler);
        }
    };

    return DigitBoxWidget;
});
